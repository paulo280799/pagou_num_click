<?php

namespace App\Services\Gateways;

use App\Enums\GatewayEnum;
use App\Enums\PaymentMethodEnum;
use App\Enums\StatusPaymentEnum;
use App\Support\ChargeResult;
use App\Support\Gateways\CardCheckoutConfig;
use App\Support\Gateways\CardRequest;
use App\Support\Gateways\GatewayCredentialsRejectedException;
use App\Support\Gateways\GatewayRequestException;
use App\Support\Gateways\GatewayUnavailableException;
use App\Support\Gateways\NormalizedStatus;
use App\Support\Gateways\PixCharge;
use App\Support\Gateways\PagarmeCredentials;
use App\Support\Gateways\PixRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PagarmeGateway implements PaymentGateway
{
    /** As credenciais são da conta (tenant) que está cobrando; null = conta sem Pagar.me configurado. */
    public function __construct(private ?PagarmeCredentials $credentials = null, private bool $active = true) {}

    /**
     * Entra em decisões de NOVAS cobranças? Só com credencial e ativo. Desativado, ainda consulta o que já existe
     * (as credenciais continuam aqui), mas não é escolhido para cobrar nem oferece tokenização de cartão.
     */
    public function isConfigured(): bool
    {
        return $this->credentials !== null && $this->active;
    }

    public function name(): GatewayEnum
    {
        return GatewayEnum::PAGARME;
    }

    private function url(string $path): string
    {
        return config('integration.url_integration').$path;
    }

    private function http(?int $timeout = null): PendingRequest
    {
        $request = Http::withBasicAuth((string) $this->credentials?->user, (string) $this->credentials?->password);

        return $timeout === null ? $request : $request->timeout($timeout);
    }

    private function queryTimeout(): int
    {
        return (int) config('integration.card_http_timeout');
    }

    /** Falha de infra: conexão, 5xx, 429. */
    private function isInfraFailure(Response $response): bool
    {
        return $response->serverError() || $response->status() === 429;
    }

    public function createPix(PixRequest $request): PixCharge
    {
        $body = [
            'code' => $request->paymentId,
            'items' => $request->items,
            'customer' => $request->customer,
            'payments' => [[
                'payment_method' => 'pix',
                // o Pagar.me recebe o instante em UTC (Brasília é UTC-3, sem horário de verão)
                'pix' => ['expires_at' => $request->expiresAt->copy()->utc()->format('Y-m-d H:i:s')],
                'amount' => $request->amountCents,
            ]],
            'metadata' => [
                'payment_id' => $request->paymentId,
                'refExternal' => $request->refExternal,
            ],
        ];

        try {
            $response = $this->http((int) config('gateways.create_timeout'))->post($this->url('/v5/orders'), $body);
        } catch (ConnectionException $e) {
            throw new GatewayUnavailableException('Pagar.me: falha de conexão', $this->name(), null, null, $e);
        }

        if ($this->isInfraFailure($response)) {
            throw new GatewayUnavailableException("Pagar.me: HTTP {$response->status()}", $this->name(), $response->status(), $response->json());
        }

        if ($response->failed()) {
            throw new GatewayRequestException('Pagar.me: pedido recusado', $this->name(), $response->status(), $response->json());
        }

        $data = $response->json();

        // HTTP 200 com charge failed ou sem QR também é falha do provedor
        if (data_get($data, 'charges.0.status') === 'failed'
            || empty(data_get($data, 'charges.0.last_transaction.qr_code_url'))
            || empty(data_get($data, 'charges.0.last_transaction.qr_code'))) {
            Log::error('Pedido PIX sem QR code ou com charge failed no provedor; order='.data_get($data, 'id').' charge_status='.data_get($data, 'charges.0.status'));

            throw new GatewayUnavailableException(
                'Pagar.me: pedido sem QR code',
                $this->name(),
                422,
                data_get($data, 'charges.0.last_transaction.gateway_response.errors', []),
            );
        }

        $method = data_get($data, 'charges.0.payment_method');
        $normalized = PagarmeStatusMap::pix($data);
        $methodEnum = PaymentMethodEnum::tryFrom(strtoupper((string) $method));

        if (! $methodEnum || ! $normalized->status) {
            Log::warning('Pedido '.data_get($data, 'id')." criado no provedor com payment_method/status não mapeado ({$method}/{$normalized->rawStatus}); usando fallback PIX/PENDING.");
        }

        return new PixCharge(
            gateway: $this->name(),
            externalId: (string) data_get($data, 'id'),
            qrCodeImage: data_get($data, 'charges.0.last_transaction.qr_code_url'),
            copyPaste: data_get($data, 'charges.0.last_transaction.qr_code'),
            amountCents: (int) data_get($data, 'amount'),
            status: $normalized->status ?? StatusPaymentEnum::PENDING,
            paymentMethod: $methodEnum ?? PaymentMethodEnum::PIX,
            rawStatus: $normalized->rawStatus,
            rawDetail: $normalized->rawDetail,
        );
    }

    public function chargeCard(CardRequest $request, string $idempotencyKey): ChargeResult
    {
        $card = ['card_token' => $request->cardToken];
        if ($request->billingAddress !== null) {
            $card['card'] = ['billing_address' => $request->billingAddress];
        }

        $body = [
            'code' => $request->paymentId,
            'items' => [[
                'amount' => $request->amountCents,
                'description' => "Pedido {$request->refExternal}",
                'quantity' => 1,
                'code' => $request->paymentId,
            ]],
            'customer' => $request->customer,
            'payments' => [[
                'payment_method' => 'credit_card',
                'amount' => $request->amountCents,
                'credit_card' => [
                    'operation_type' => 'auth_and_capture',
                    'installments' => $request->installments,
                    'statement_descriptor' => config('integration.card_statement_descriptor'),
                ] + $card,
            ]],
            'metadata' => [
                'payment_id' => $request->paymentId,
                'refExternal' => $request->refExternal,
            ],
        ];

        try {
            $response = $this->http($this->queryTimeout())
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post($this->url('/v5/orders'), $body);
        } catch (ConnectionException $e) {
            throw new GatewayUnavailableException('Pagar.me: falha de conexão', $this->name(), null, null, $e);
        }

        if ($response->serverError()) {
            throw new GatewayUnavailableException("Pagar.me: HTTP {$response->status()}", $this->name(), $response->status());
        }

        $data = $response->json();

        return ChargeResult::fromOrderResponse(is_array($data) ? $data : []);
    }

    public function getPixStatus(string $externalId): NormalizedStatus
    {
        try {
            $response = $this->http($this->queryTimeout())->get($this->url("/v5/orders/{$externalId}"));
        } catch (ConnectionException $e) {
            throw new GatewayUnavailableException('Pagar.me: falha de conexão', $this->name(), null, null, $e);
        }

        // credencial recusada (401/403) é configuração quebrada, não "o PIX não existe": nunca pode virar cancelamento
        if (in_array($response->status(), [401, 403], true)) {
            throw new GatewayCredentialsRejectedException("Pagar.me: credencial recusada (HTTP {$response->status()})", $this->name(), $response->status());
        }

        if ($this->isInfraFailure($response)) {
            throw new GatewayUnavailableException("Pagar.me: HTTP {$response->status()}", $this->name(), $response->status());
        }

        $data = $response->json();

        return PagarmeStatusMap::pix(is_array($data) ? $data : []);
    }

    public function getCardStatus(string $externalId): ?ChargeResult
    {
        try {
            $response = $this->http($this->queryTimeout())->get($this->url("/v5/orders/{$externalId}"));
        } catch (ConnectionException $e) {
            throw new GatewayUnavailableException('Pagar.me: falha de conexão', $this->name(), null, null, $e);
        }

        return $response->successful() ? ChargeResult::fromOrderResponse($response->json() ?? []) : null;
    }

    public function findCardAttempt(string $paymentId): ?ChargeResult
    {
        try {
            $response = $this->http($this->queryTimeout())->get($this->url('/v5/orders'), ['code' => $paymentId]);
        } catch (ConnectionException $e) {
            throw new GatewayUnavailableException('Pagar.me: falha de conexão', $this->name(), null, null, $e);
        }

        if (! $response->successful()) {
            throw new GatewayUnavailableException("Pagar.me: busca por code falhou (HTTP {$response->status()})", $this->name(), $response->status());
        }

        foreach ($response->json('data') ?? [] as $order) {
            if (data_get($order, 'metadata.payment_id') === $paymentId) {
                return ChargeResult::fromOrderResponse($order);
            }
        }

        return null;
    }

    /** PIX do Pagar.me não é cancelável: só expira. */
    public function cancelPix(string $externalId): bool
    {
        return false;
    }

    public function cardCheckoutConfig(): ?CardCheckoutConfig
    {
        $key = $this->active ? $this->credentials?->publicKey : null;

        return filled($key)
            ? new CardCheckoutConfig(GatewayEnum::PAGARME, $key, 'https://api.pagar.me/core/v5/tokens')
            : null;
    }
}
