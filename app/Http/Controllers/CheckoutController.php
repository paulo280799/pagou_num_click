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

        $this->config = $this->config->first();

        $expirationDate = Carbon::now()->addSeconds($this->config->duration)->format('Y-m-d H:i:s');

        $expiresAt = Carbon::now()->addHours(3)->addSeconds($this->config->duration)->format('Y-m-d H:i:s');

        $body = [
            "items" => [
                [
                    "amount" => $this->reaisParaCentavos($validated['items'][0]['amount']),
                    "description" => $validated['items'][0]['description'],
                    "quantity" => 1
                ]
            ],
            "customer" => [
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
            ],
            "payments" => [
                [
                    "payment_method" => "pix",
                    "pix" => [
                        "expires_at" => (string)$expiresAt
                    ],
                    "amount" => $this->reaisParaCentavos($validated['items'][0]['amount']),
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

        if (empty($responseData['charges'][0]['last_transaction']['qr_code_url'])) {
            Log::error("Pedido {$responseData['id']} criado no provedor mas charge não retornou qr_code_url; resposta completa: ".json_encode($responseData));
        }

        $paymentMethodFromApi = $response['charges'][0]['payment_method'] ?? null;
        $paymentEnum = PaymentMethodEnum::tryFrom(strtoupper((string) $paymentMethodFromApi));

        $paymentStatusFromApi = $response['status'] ?? null;
        $paymentStatusEnum = StatusPaymentEnum::tryFrom(strtoupper((string) $paymentStatusFromApi));

        if (!$paymentEnum || !$paymentStatusEnum) {
            Log::warning("Pedido {$responseData['id']} criado no provedor com payment_method/status não mapeado ({$paymentMethodFromApi}/{$paymentStatusFromApi}); usando fallback PIX/PENDING.");
        }

        $payment = $this->payment->create([
            'ide' => $responseData['id'],
            'qrCode' => $responseData['charges'][0]['last_transaction']['qr_code_url'],
            'copyPaste' => $responseData['charges'][0]['last_transaction']['qr_code'],
            'amount' =>  $this->centavosParaReais($responseData['amount']),

            'payment_method' => ($paymentEnum ?? PaymentMethodEnum::PIX)->value,
            'status' => ($paymentStatusEnum ?? StatusPaymentEnum::PENDING)->value,

            'refExternal' => $validated['refExternal'],

            'duration' => $this->config->duration,
            'expirationDate' => $expirationDate,
            'redirect_url' => $this->config->redirect_url,
            'notification_url' => $this->config->notification_url
        ]);

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
