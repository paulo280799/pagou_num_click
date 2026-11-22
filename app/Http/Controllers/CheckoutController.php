<?php

namespace App\Http\Controllers;

use App\Enums\GatewayEnum;
use App\Enums\PaymentMethodEnum;
use App\Enums\StatusPaymentEnum;
use App\Http\Requests\CheckoutRequest;
use App\Models\Config;
use App\Models\Payment;
use App\Services\Gateways\GatewayManager;
use App\Support\Gateways\GatewayRequestException;
use App\Support\Gateways\GatewayUnavailableException;
use App\Support\Gateways\PixRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CheckoutController extends Controller
{
    public function __construct(private Config $config, private Payment $payment, private GatewayManager $gateways) {}

    public function store(CheckoutRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $methods = $validated['payment_methods'] ?? CheckoutRequest::METHODS;
        $methods = array_values(array_intersect(CheckoutRequest::METHODS, $methods));
        $cardRequested = in_array('credit_card', $methods, true);

        // Cartão pedido explicitamente mas sem chave pública em nenhum gateway: falha cedo em vez de gerar checkout sem cartão.
        // Se `payment_methods` foi omitido, o padrão (PIX + cartão) degrada para PIX no checkout.
        if ($cardRequested && isset($validated['payment_methods']) && ! $this->gateways->cardEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'Pagamento com cartão indisponível nesta conta.',
                'errors' => ['payment_methods' => ['O meio credit_card não está disponível.']],
            ], 422);
        }

        $this->config = $this->config->first();

        $expiresAt = Carbon::now()->addSeconds($this->config->duration);

        $items = array_map(fn ($item) => [
            'amount' => $this->reaisParaCentavos($item['amount']),
            'description' => $item['description'],
            'quantity' => (int) $item['quantity'],
        ], $validated['items']);

        $totalCents = array_sum(array_map(fn ($item) => $item['amount'] * $item['quantity'], $items));

        $customer = [
            'name' => $validated['customer']['name'],
            'email' => $validated['customer']['email'],
            'type' => $validated['customer']['type'],
            'document' => $this->removerMascaraCpf($validated['customer']['document']),
            'phones' => [
                'home_phone' => [
                    'country_code' => $validated['customer']['phones']['home_phone']['country_code'],
                    'number' => $validated['customer']['phones']['home_phone']['number'],
                    'area_code' => $validated['customer']['phones']['home_phone']['area_code'],
                ],
            ],
        ];

        // UUID antes da chamada: vira `code`/`external_reference` no gateway e permite reconciliar após timeout
        $paymentId = (string) Str::uuid();

        $attributes = [
            'id' => $paymentId,
            'refExternal' => $validated['refExternal'],
            'customer' => $customer,
            'items' => $items,
            'payment_methods' => $methods,
            'max_installments' => $this->config->effectiveMaxInstallments(),
            'duration' => $this->config->duration,
            'expirationDate' => $expiresAt->format('Y-m-d H:i:s'),
            'redirect_url' => $this->config->redirect_url,
            'notification_url' => $this->config->notification_url,
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

        try {
            $charge = $this->gateways->createPix(new PixRequest(
                paymentId: $paymentId,
                amountCents: $totalCents,
                items: $items,
                customer: $customer,
                expiresAt: $expiresAt,
                refExternal: $validated['refExternal'],
            ));
        } catch (GatewayRequestException $e) {
            return $this->gatewayErrorResponse($e->httpStatus, $e->body, $e->gateway);
        } catch (GatewayUnavailableException $e) {
            return $this->gatewayErrorResponse($e->httpStatus ?? 503, $e->body, $e->gateway);
        }

        // o gateway pode impor outra validade (MP: mínimo de 30 min): o timer e o polling seguem o provedor
        $attributes['expirationDate'] = ($charge->expiresAt ?? $expiresAt)->format('Y-m-d H:i:s');

        $payment = $this->payment->create($attributes + [
            'ide' => $charge->externalId,
            'pix_gateway' => $charge->gateway->value,
            'qrCode' => $charge->qrCodeImage,
            'copyPaste' => $charge->copyPaste,
            'amount' => $this->centavosParaReais($charge->amountCents),
            'provider_status' => $charge->rawStatus,
            'provider_status_detail' => $charge->rawDetail,

            'payment_method' => $charge->paymentMethod->value,
            'status' => $charge->status->value,
        ]);

        return $this->paymentLinkResponse($payment);
    }

    private function gatewayErrorResponse(int $status, mixed $errors, ?GatewayEnum $gateway): JsonResponse
    {
        return response()->json(array_filter([
            'success' => false,
            'message' => 'Erro ao processar pagamento',
            'errors' => $errors ?? [],
            'gateway' => $gateway ? strtolower($gateway->value) : null,
        ], fn ($v) => $v !== null), $status);
    }

    private function paymentLinkResponse(Payment $payment): JsonResponse
    {
        return response()->json(array_filter([
            'payment_link' => config('app.url').'/checkout/'.$payment->id,
            // gateway que criou o PIX; checkout só-cartão não chama o provedor na criação
            'gateway' => $payment->pix_gateway ? strtolower($payment->pix_gateway->value) : null,
        ]), 201);
    }

    private function reaisParaCentavos($valor)
    {
        // Remove possíveis caracteres de moeda e espaço
        $valorLimpo = preg_replace('/[^\d,.-]/', '', $valor);

        // Substitui vírgula por ponto (caso esteja no formato brasileiro)
        $valorPonto = str_replace(',', '.', $valorLimpo);

        // Converte para float e depois multiplica por 100
        $centavos = round(floatval($valorPonto) * 100);

        return intval($centavos);
    }

    public function centavosParaReais($centavos)
    {
        return $centavos / 100;
    }

    public function removerMascaraCpf(string $cpfComMascara): string
    {
        // Remove pontos, traços e outros caracteres não numéricos
        return preg_replace('/[^0-9]/', '', $cpfComMascara);
    }
}
