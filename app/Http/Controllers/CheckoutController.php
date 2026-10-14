<?php

namespace App\Http\Controllers;

use App\Services\ApiService;
use App\Models\Payment;
use App\Models\Config;
use App\Enums\StatusPaymentEnum;
use App\Enums\PaymentMethodEnum;
use App\Http\Requests\CheckoutRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class CheckoutController extends Controller
{
    function __construct(private Config $config, private Payment $payment, private ApiService $apiService){}

    public function store(CheckoutRequest $request) : JsonResponse
    {
        $validated = $request->validated();

        $methods = $validated['payment_methods'] ?? CheckoutRequest::METHODS;
        $methods = array_values(array_intersect(CheckoutRequest::METHODS, $methods));
        $cardRequested = in_array('credit_card', $methods, true);

        // Cartão pedido explicitamente mas sem chave pública: falha cedo em vez de gerar checkout sem cartão.
        // Se `payment_methods` foi omitido, o padrão (PIX + cartão) degrada para PIX no checkout.
        if ($cardRequested && isset($validated['payment_methods']) && blank(config('integration.public_key'))) {
            return response()->json([
                'success' => false,
                'message' => 'Pagamento com cartão indisponível nesta conta.',
                'errors' => ['payment_methods' => ['O meio credit_card não está disponível.']],
            ], 422);
        }

        $this->config = $this->config->first();

        $expirationDate = Carbon::now()->addSeconds($this->config->duration)->format('Y-m-d H:i:s');

        $expiresAt = Carbon::now()->addHours(3)->addSeconds($this->config->duration)->format('Y-m-d H:i:s');

        $items = array_map(fn ($item) => [
            'amount' => $this->reaisParaCentavos($item['amount']),
            'description' => $item['description'],
            'quantity' => (int) $item['quantity'],
        ], $validated['items']);

        $totalCents = array_sum(array_map(fn ($item) => $item['amount'] * $item['quantity'], $items));

        $customer = [
            "name" => $validated['customer']['name'],
            "email" => $validated['customer']['email'],
            "type" => $validated['customer']['type'],
            "document" => $this->removerMascaraCpf($validated['customer']['document']),
            "phones" => [
                "home_phone" => [
                    "country_code" => $validated['customer']['phones']['home_phone']['country_code'],
                    "number" => $validated['customer']['phones']['home_phone']['number'],
                    "area_code" => $validated['customer']['phones']['home_phone']['area_code']
                ]
            ]
        ];

        $attributes = [
            'refExternal' => $validated['refExternal'],
            'customer' => $customer,
            'payment_methods' => $methods,
            'duration' => $this->config->duration,
            'expirationDate' => $expirationDate,
            'redirect_url' => $this->config->redirect_url,
            'notification_url' => $this->config->notification_url
        ];

        // Checkout só-cartão: nenhum pedido PIX no provedor (o pedido de cartão nasce no pay-card).
        if ($methods === ['credit_card']) {
            $payment = $this->payment->create($attributes + [
                'amount' => $this->centavosParaReais($totalCents),
                'payment_method' => PaymentMethodEnum::CREDIT_CARD->value,
                'status' => StatusPaymentEnum::PENDING->value,
            ]);

            return $this->paymentLinkResponse($payment);
        }

        $body = [
            "items" => $items,
            "customer" => $customer,
            "payments" => [
                [
                    "payment_method" => "pix",
                    "pix" => [
                        "expires_at" => (string)$expiresAt
                    ],
                    "amount" => $totalCents,
                ]
            ]
        ];

        $response = $this->apiService->create_order($body);

        if ($response->failed()) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar pagamento',
                'errors' => $response->json(),
            ], $response->status());
        }

        $responseData = $response->json();

        if (data_get($responseData, 'charges.0.status') === 'failed'
            || empty(data_get($responseData, 'charges.0.last_transaction.qr_code_url'))
            || empty(data_get($responseData, 'charges.0.last_transaction.qr_code'))) {
            Log::error('Pedido PIX sem QR code ou com charge failed no provedor; order='.data_get($responseData, 'id').' charge_status='.data_get($responseData, 'charges.0.status'));

            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar pagamento',
                'errors' => data_get($responseData, 'charges.0.last_transaction.gateway_response.errors', []),
            ], 422);
        }

        $paymentMethodFromApi = $response['charges'][0]['payment_method'] ?? null;
        $paymentEnum = PaymentMethodEnum::tryFrom(strtoupper((string) $paymentMethodFromApi));

        $paymentStatusFromApi = $response['status'] ?? null;
        $paymentStatusEnum = StatusPaymentEnum::tryFrom(strtoupper((string) $paymentStatusFromApi));

        if (!$paymentEnum || !$paymentStatusEnum) {
            Log::warning("Pedido {$responseData['id']} criado no provedor com payment_method/status não mapeado ({$paymentMethodFromApi}/{$paymentStatusFromApi}); usando fallback PIX/PENDING.");
        }

        $payment = $this->payment->create($attributes + [
            'ide' => $responseData['id'],
            'qrCode' => $responseData['charges'][0]['last_transaction']['qr_code_url'],
            'copyPaste' => $responseData['charges'][0]['last_transaction']['qr_code'],
            'amount' =>  $this->centavosParaReais($responseData['amount']),

            'payment_method' => ($paymentEnum ?? PaymentMethodEnum::PIX)->value,
            'status' => ($paymentStatusEnum ?? StatusPaymentEnum::PENDING)->value,
        ]);

        return $this->paymentLinkResponse($payment);
    }

    private function paymentLinkResponse(Payment $payment): JsonResponse
    {
        return response()->json([
            'payment_link' => config('app.url').'/checkout/'.$payment->id
        ], 201);
    }

    private function reaisParaCentavos($valor) {
        // Remove possíveis caracteres de moeda e espaço
        $valorLimpo = preg_replace('/[^\d,.-]/', '', $valor);

        // Substitui vírgula por ponto (caso esteja no formato brasileiro)
        $valorPonto = str_replace(',', '.', $valorLimpo);

        // Converte para float e depois multiplica por 100
        $centavos = round(floatval($valorPonto) * 100);

        return intval($centavos);
    }

    function centavosParaReais($centavos) {
        return $centavos / 100;
    }
    
    function removerMascaraCpf(string $cpfComMascara): string
    {
        // Remove pontos, traços e outros caracteres não numéricos
        return preg_replace('/[^0-9]/', '', $cpfComMascara);
    }
}
