<?php

namespace App\Http\Controllers;

use App\Enums\GatewayEnum;
use App\Enums\StatusPaymentEnum;
use App\Http\Requests\PayCardRequest;
use App\Models\Payment;
use App\Services\Gateways\GatewayManager;
use App\Services\Gateways\TenantGateways;
use App\Support\ChargeOutcome;
use App\Support\ChargeResult;
use App\Support\Gateways\CardCheckoutConfig;
use App\Support\Gateways\CardRequest;
use App\Support\Gateways\GatewayUnavailableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CardPaymentController extends Controller
{
    public function __construct(private GatewayManager $gateways) {}

    /**
     * Config de tokenização do gateway vigente, buscada pelo browser no submit: o formulário vive em `wire:ignore`,
     * então a config do render fica congelada e não acompanharia uma troca de gateway.
     */
    public function config(string $id): JsonResponse
    {
        $payment = Payment::withoutGlobalScopes()->findOrFail($id);

        abort_unless($payment->status === StatusPaymentEnum::PENDING, 409);
        abort_if($payment->expirationDate && Carbon::parse($payment->expirationDate)->isPast(), 409);
        abort_if($payment->customer === null || ! $payment->allowsMethod('credit_card'), 409);

        $config = $this->gateways->forPayment($payment)->cardCheckoutConfig();
        abort_if($config === null, 409);

        return response()->json($this->configPayload($config, $payment));
    }

    public function store(PayCardRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();
        $gateway = GatewayEnum::from(strtoupper($validated['gateway'] ?? 'pagarme'));
        // cada conta cobra com as próprias credenciais: o tenant é o dono do Payment
        $paymentForTenant = Payment::withoutGlobalScopes()->findOrFail($id);
        \App\Support\TenantContext::tagLogs($paymentForTenant->account_id);
        $tenant = $this->gateways->forPayment($paymentForTenant);

        // O token do browser só vale no gateway em que foi gerado: se a decisão mudou, nada é cobrado e o browser re-tokeniza.
        if (! $tenant->isCardGatewayStillCurrent($gateway)) {
            return $this->fallbackResponse($tenant->cardCheckoutConfig(), Payment::withoutGlobalScopes()->findOrFail($id));
        }

        // Fase 1 — reservar a tentativa sob lock
        $payment = DB::transaction(function () use ($id, $gateway) {
            $p = Payment::withoutGlobalScopes()->lockForUpdate()->findOrFail($id);

            abort_unless($p->status === StatusPaymentEnum::PENDING, 409);
            abort_if($p->expirationDate && Carbon::parse($p->expirationDate)->isPast(), 409);
            abort_if($p->customer === null, 409);
            // checkout configurado só com PIX
            abort_unless($p->allowsMethod('credit_card'), 409);
            // cartão em análise: aguardar o polling
            abort_if($p->card_order_id !== null && $p->card_attempt_key !== null, 409);
            // no máximo uma tentativa ativa por Payment, somando os gateways: a de outro gateway é resolvida pelo polling antes
            abort_if($p->card_attempt_key !== null && $p->cardGatewayOwner() !== $gateway, 409);
            // outra requisição em voo
            abort_if(
                $p->card_attempt_key !== null
                && $p->card_attempt_started_at
                && $p->card_attempt_started_at->gt(now()->subSeconds(config('integration.card_http_timeout') + 5)),
                409
            );

            // chave nova por tentativa; reaproveitada se a anterior deu timeout. O gateway é gravado pelo servidor.
            $p->update([
                'card_attempt_key' => $p->card_attempt_key ?? (string) Str::uuid(),
                'card_attempt_started_at' => now(),
                'card_gateway' => $gateway,
            ]);

            return $p;
        });

        $cardRequest = new CardRequest(
            paymentId: $payment->id,
            amountCents: (int) round($payment->amount * 100),
            installments: (int) $validated['installments'],
            cardToken: $validated['card_token'],
            customer: $payment->customer,
            refExternal: (string) $payment->refExternal,
            billingAddress: $validated['billing_address'] ?? null,
            paymentMethodId: $validated['payment_method_id'] ?? null,
            issuerId: isset($validated['issuer_id']) ? (string) $validated['issuer_id'] : null,
            items: $payment->itemsForGateway(),
            deviceId: isset($validated['device_id']) ? (string) $validated['device_id'] : null,
        );

        // Fase 2 — chamar o gateway (sem lock)
        try {
            $result = $tenant->gateway($gateway)->chargeCard($cardRequest, $payment->card_attempt_key);
        } catch (GatewayUnavailableException $e) {
            Log::error("Cartão: gateway indisponível ({$e->getMessage()}); gateway={$gateway->value} payment={$payment->id}");

            $tenant->reportFailure($gateway);

            $fallback = $this->tryInFlightFallback($tenant, $payment, $gateway);

            if ($fallback instanceof JsonResponse) {
                return $fallback;
            }

            if ($fallback === null) {
                return $this->processingResponse();
            }

            // a reconciliação achou a cobrança que o Pagar.me chegou a criar: segue com ela
            $result = $fallback;
        }

        // Fase 3 — aplicar o resultado sob lock
        return DB::transaction(function () use ($payment, $result) {
            $p = Payment::withoutGlobalScopes()->lockForUpdate()->findOrFail($payment->id);

            switch ($result->outcome) {
                case ChargeOutcome::PAID:
                    $p->markCardPaid($result);

                    return response()->json(['status' => 'paid', 'message' => 'Pagamento aprovado.']);

                case ChargeOutcome::DECLINED:
                    Log::warning("Cartão recusado; gateway={$p->cardGatewayOwner()->value} payment={$p->id} order={$result->orderId} return_code={$result->acquirerReturnCode}");
                    $p->releaseCardAttempt();

                    return response()->json([
                        'status' => 'declined',
                        'message' => 'Cartão recusado. Verifique os dados ou use outro cartão.',
                    ], 422);

                default:
                    Log::warning("Cartão em análise/desconhecido; gateway={$p->cardGatewayOwner()->value} payment={$p->id} order={$result->orderId} tx_status={$result->transactionStatus}");
                    $p->recordProviderStatus($result->rawStatus, $result->rawDetail);
                    $p->update(['card_order_id' => $result->orderId]);

                    return response()->json([
                        'status' => 'processing',
                        'message' => 'Pagamento em análise.',
                    ], 202);
            }
        });
    }

    /**
     * Falha do Pagar.me no meio da cobrança. Só passa para o Mercado Pago com certeza de que o Pagar.me não criou a cobrança
     * (reconciliação por `code`): duplicar cobrança é pior que falhar.
     *
     * @return JsonResponse|ChargeResult|null resposta de fallback | cobrança que existe no Pagar.me | em dúvida (fica "processando")
     */
    private function tryInFlightFallback(TenantGateways $tenant, Payment $payment, GatewayEnum $failed): JsonResponse|ChargeResult|null
    {
        if ($failed !== GatewayEnum::PAGARME) {
            return null;
        }

        $target = $tenant->cardCheckoutConfigFor(GatewayEnum::MERCADOPAGO);

        if ($target === null) {
            return null;
        }

        try {
            $existing = $tenant->gateway(GatewayEnum::PAGARME)->findCardAttempt($payment->id);
        } catch (GatewayUnavailableException) {
            return null; // não deu para provar: o polling reconcilia depois
        }

        if ($existing !== null) {
            $payment->update(['card_order_id' => $existing->orderId ?? $payment->card_order_id]);

            return $existing;
        }

        $payment->releaseCardAttempt();

        Log::warning("Cartão: Pagar.me indisponível e sem cobrança criada; oferecendo Mercado Pago; payment={$payment->id}");

        return $this->fallbackResponse($target, $payment);
    }

    private function fallbackResponse(?CardCheckoutConfig $config, Payment $payment): JsonResponse
    {
        abort_if($config === null, 409);

        // 503: um cliente que não conheça o fallback trata como "processando" e nunca como pago
        return response()->json(['status' => 'fallback'] + $this->configPayload($config, $payment), 503);
    }

    private function configPayload(CardCheckoutConfig $config, Payment $payment): array
    {
        return $config->toArray() + [
            // parcelamento no MP só 1x até o spike S4: o valor cobrado nunca diverge do Payment
            'maxInstallments' => $config->gateway === GatewayEnum::MERCADOPAGO ? 1 : $payment->installmentLimit(),
        ];
    }

    private function processingResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'processing',
            'message' => 'Pagamento em processamento. Tente novamente em instantes.',
        ], 503);
    }
}
