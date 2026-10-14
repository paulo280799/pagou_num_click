<?php

namespace App\Http\Controllers;

use App\Enums\StatusPaymentEnum;
use App\Http\Requests\PayCardRequest;
use App\Models\Payment;
use App\Services\ApiService;
use App\Support\ChargeOutcome;
use App\Support\ChargeResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CardPaymentController extends Controller
{
    public function __construct(private ApiService $apiService) {}

    public function store(PayCardRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();

        // Fase 1 — reservar a tentativa sob lock
        $payment = DB::transaction(function () use ($id) {
            $p = Payment::withoutGlobalScopes()->lockForUpdate()->findOrFail($id);

            abort_unless($p->status === StatusPaymentEnum::PENDING, 409);
            abort_if($p->expirationDate && Carbon::parse($p->expirationDate)->isPast(), 409);
            abort_if($p->customer === null, 409);
            // cartão em análise: aguardar o polling
            abort_if($p->card_order_id !== null && $p->card_attempt_key !== null, 409);
            // outra requisição em voo
            abort_if(
                $p->card_attempt_key !== null
                && $p->card_attempt_started_at
                && $p->card_attempt_started_at->gt(now()->subSeconds(config('integration.card_http_timeout') + 5)),
                409
            );

            // chave nova por tentativa; reaproveitada se a anterior deu timeout
            $p->update([
                'card_attempt_key' => $p->card_attempt_key ?? (string) Str::uuid(),
                'card_attempt_started_at' => now(),
            ]);

            return $p;
        });

        $cents = (int) round($payment->amount * 100);

        $body = [
            'code' => $payment->id,
            'items' => [[
                'amount' => $cents,
                'description' => "Pedido {$payment->refExternal}",
                'quantity' => 1,
                'code' => $payment->id,
            ]],
            'customer' => $payment->customer,
            'payments' => [[
                'payment_method' => 'credit_card',
                'amount' => $cents,
                'credit_card' => [
                    'operation_type' => 'auth_and_capture',
                    'installments' => (int) $validated['installments'],
                    'statement_descriptor' => config('integration.card_statement_descriptor'),
                    'card_token' => $validated['card_token'],
                    'card' => ['billing_address' => $validated['billing_address']],
                ],
            ]],
            'metadata' => [
                'payment_id' => $payment->id,
                'refExternal' => $payment->refExternal,
            ],
        ];

        // Fase 2 — chamar a Pagar.me (sem lock)
        try {
            $response = $this->apiService->create_card_order($body, $payment->card_attempt_key);
        } catch (ConnectionException $e) {
            Log::error("Cartão: falha de conexão com o provedor; payment={$payment->id}");

            return response()->json([
                'status' => 'processing',
                'message' => 'Pagamento em processamento. Tente novamente em instantes.',
            ], 503);
        }

        $data = $response->json();
        $result = ChargeResult::fromOrderResponse(is_array($data) ? $data : []);

        if ($response->serverError()) {
            Log::error("Cartão: erro {$response->status()} do provedor; payment={$payment->id}");

            return response()->json([
                'status' => 'processing',
                'message' => 'Pagamento em processamento. Tente novamente em instantes.',
            ], 503);
        }

        // Fase 3 — aplicar o resultado sob lock
        return DB::transaction(function () use ($payment, $result) {
            $p = Payment::withoutGlobalScopes()->lockForUpdate()->findOrFail($payment->id);

            switch ($result->outcome) {
                case ChargeOutcome::PAID:
                    $p->markCardPaid($result);

                    return response()->json(['status' => 'paid', 'message' => 'Pagamento aprovado.']);

                case ChargeOutcome::DECLINED:
                    Log::warning("Cartão recusado; payment={$p->id} order={$result->orderId} return_code={$result->acquirerReturnCode}");
                    $p->releaseCardAttempt();

                    return response()->json([
                        'status' => 'declined',
                        'message' => 'Cartão recusado. Verifique os dados ou use outro cartão.',
                    ], 422);

                default:
                    Log::warning("Cartão em análise/desconhecido; payment={$p->id} order={$result->orderId} tx_status={$result->transactionStatus}");
                    $p->update(['card_order_id' => $result->orderId]);

                    return response()->json([
                        'status' => 'processing',
                        'message' => 'Pagamento em análise.',
                    ], 202);
            }
        });
    }
}
