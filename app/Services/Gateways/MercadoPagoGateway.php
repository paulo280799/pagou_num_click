<?php

namespace App\Services\Gateways;

use App\Enums\GatewayEnum;
use App\Enums\StatusPaymentEnum;
use App\Support\ChargeOutcome;
use App\Support\ChargeResult;
use App\Support\Gateways\CardCheckoutConfig;
use App\Support\Gateways\CardRequest;
use App\Support\Gateways\GatewayRequestException;
use App\Support\Gateways\GatewayUnavailableException;
use App\Support\Gateways\NormalizedStatus;
use App\Support\Gateways\PixCharge;
use App\Support\Gateways\PixRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Gateway de redundância, sobre a Orders API (`/v1/orders`). Contrato em docs/mercadopago/. */
class MercadoPagoGateway implements PaymentGateway
{
    public function name(): GatewayEnum
    {
        return GatewayEnum::MERCADOPAGO;
    }

    /** Failover só existe com credencial: sem MP_ACCESS_TOKEN o sistema se comporta como antes. */
    public function isEnabled(): bool
    {
        return (bool) config('gateways.mercadopago.enabled') && filled(config('gateways.mercadopago.access_token'));
    }

    private function url(string $path): string
    {
        return rtrim(config('gateways.mercadopago.url'), '/').$path;
    }

    private function http(int $timeout, ?string $idempotencyKey = null, ?string $deviceId = null): PendingRequest
    {
        $request = Http::withToken((string) config('gateways.mercadopago.access_token'))->acceptJson()->timeout($timeout);

        if ($idempotencyKey !== null) {
            $request = $request->withHeaders(['X-Idempotency-Key' => $idempotencyKey]);
        }

        // Device ID gerado pelo MercadoPago.js no navegador do pagador (antifraude/aprovação)
        return $deviceId !== null && $deviceId !== '' ? $request->withHeaders(['X-meli-session-id' => $deviceId]) : $request;
    }

    /**
     * Itens da ordem (centavos → reais em string). `title`, `unit_price` e `quantity` são os campos que a Orders API mede.
     *
     * @param  list<array{amount: int, description: string, quantity: int}>  $items
     */
    private function orderItems(array $items): array
    {
        return array_values(array_map(fn (array $item) => [
            'title' => mb_substr((string) ($item['description'] ?? 'Item'), 0, 256),
            'unit_price' => $this->amount((int) $item['amount']),
            'quantity' => (int) $item['quantity'],
            'category_id' => (string) config('gateways.mercadopago.item_category'),
        ], $items));
    }

    private function queryTimeout(): int
    {
        return (int) config('gateways.mercadopago.http_timeout');
    }

    /** Conexão, 5xx, 429 e credencial recusada (401/403 é problema de configuração nosso, não do pagador). */
    private function isInfraFailure(Response $response): bool
    {
        return $response->serverError() || in_array($response->status(), [401, 403, 429], true);
    }

    private function unavailable(string $reason, ?Response $response = null, ?ConnectionException $previous = null): GatewayUnavailableException
    {
        if ($response !== null && in_array($response->status(), [401, 403], true)) {
            Log::error("Mercado Pago: credencial recusada (HTTP {$response->status()}); verifique MP_ACCESS_TOKEN");
        }

        return new GatewayUnavailableException("Mercado Pago: {$reason}", $this->name(), $response?->status(), $response?->json(), $previous);
    }

    /** @return array{first_name?: string, last_name?: string, email: string, identification: array{type: string, number: string}} */
    private function payer(array $customer): array
    {
        $parts = preg_split('/\s+/', trim((string) ($customer['name'] ?? '')), 2) ?: [];
        $document = preg_replace('/\D+/', '', (string) ($customer['document'] ?? ''));
        $company = ($customer['type'] ?? null) === 'company' || strlen($document) === 14;

        return array_filter([
            'email' => $customer['email'] ?? null,
            'entity_type' => $company ? 'association' : 'individual',
            'first_name' => $parts[0] ?? null,
            'last_name' => $parts[1] ?? null,
            'identification' => $document !== '' ? ['type' => $company ? 'CNPJ' : 'CPF', 'number' => $document] : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** A Orders API recebe valores como string com 2 casas. */
    private function amount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    public function createPix(PixRequest $request): PixCharge
    {
        // validade mínima de 30 min: grava-se o mesmo instante enviado ao MP, para timer, 409 do pay-card e polling concordarem
        $minimum = now()->addSeconds((int) config('gateways.mercadopago.min_pix_expiration'));
        $expiresAt = $request->expiresAt->lt($minimum) ? $minimum : $request->expiresAt->copy();

        $amount = $this->amount($request->amountCents);
        $minutes = max(30, (int) ceil(now()->diffInSeconds($expiresAt, false) / 60));

        $body = [
            'type' => 'online',
            'processing_mode' => 'automatic',
            'total_amount' => $amount,
            'external_reference' => $request->paymentId,
            'description' => "Pedido {$request->refExternal}",
            'items' => $this->orderItems($request->items),
            'transactions' => ['payments' => [[
                'amount' => $amount,
                'payment_method' => ['id' => 'pix', 'type' => 'bank_transfer'],
                'expiration_time' => "PT{$minutes}M",
            ]]],
            'payer' => $this->payer($request->customer),
        ];

        try {
            // chave estável por Payment: reenvio após timeout devolve o mesmo pagamento em vez de duplicar
            $response = $this->http((int) config('gateways.create_timeout'), $request->paymentId)->post($this->url('/v1/orders'), $body);
        } catch (ConnectionException $e) {
            throw $this->unavailable('falha de conexão', previous: $e);
        }

        if ($this->isInfraFailure($response)) {
            throw $this->unavailable("HTTP {$response->status()}", $response);
        }

        if ($response->failed()) {
            throw new GatewayRequestException('Mercado Pago: pedido recusado', $this->name(), $response->status(), $response->json());
        }

        $data = $response->json() ?? [];
        $method = data_get($data, 'transactions.payments.0.payment_method', []);
        $copyPaste = data_get($method, 'qr_code');
        $image = data_get($method, 'qr_code_base64');
        $normalized = MercadoPagoStatusMap::pix($data);

        if (empty($copyPaste) || empty($image) || $normalized->status === StatusPaymentEnum::FAILED) {
            Log::error('Mercado Pago: PIX sem QR code ou rejeitado; order='.data_get($data, 'id').' status='.data_get($data, 'status').' detail='.data_get($data, 'status_detail'));

            throw new GatewayUnavailableException('Mercado Pago: ordem sem QR code', $this->name(), 422, $data);
        }

        return new PixCharge(
            gateway: $this->name(),
            externalId: (string) data_get($data, 'id'),
            qrCodeImage: 'data:image/png;base64,'.$image,
            copyPaste: $copyPaste,
            amountCents: (int) round((float) data_get($data, 'total_amount', $request->amountCents / 100) * 100),
            status: $normalized->status ?? StatusPaymentEnum::PENDING,
            expiresAt: $expiresAt,
            rawStatus: $normalized->rawStatus,
            rawDetail: $normalized->rawDetail,
        );
    }

    public function chargeCard(CardRequest $request, string $idempotencyKey): ChargeResult
    {
        $amount = $this->amount($request->amountCents);

        $body = [
            'type' => 'online',
            'processing_mode' => 'automatic',
            'capture_mode' => 'automatic',
            'total_amount' => $amount,
            'external_reference' => $request->paymentId,
            'description' => "Pedido {$request->refExternal}",
            'items' => $this->orderItems($request->items),
            'transactions' => ['payments' => [[
                'amount' => $amount,
                'payment_method' => array_filter([
                    'id' => $request->paymentMethodId,
                    'type' => 'credit_card',
                    'token' => $request->cardToken,
                    'installments' => $request->installments,
                    'statement_descriptor' => config('integration.card_statement_descriptor'),
                ], fn ($v) => $v !== null && $v !== ''),
            ]]],
            'payer' => $this->payer($request->customer),
        ];

        try {
            $response = $this->http($this->queryTimeout(), $idempotencyKey, $request->deviceId)->post($this->url('/v1/orders'), $body);
        } catch (ConnectionException $e) {
            throw $this->unavailable('falha de conexão', previous: $e);
        }

        if ($this->isInfraFailure($response)) {
            throw $this->unavailable("HTTP {$response->status()}", $response);
        }

        // 402: a ordem foi criada mas a transação falhou (recusa do emissor); o corpo pode trazer a ordem em `data`
        if ($response->status() === 402) {
            $order = is_array($response->json('data')) ? $response->json('data') : [];
            $detail = (string) (data_get($order, 'transactions.payments.0.status_detail') ?? data_get($response->json(), 'errors.0.code'));

            return new ChargeResult(
                outcome: ChargeOutcome::DECLINED,
                orderId: data_get($order, 'id') !== null ? (string) data_get($order, 'id') : null,
                transactionStatus: 'http_402',
                rawStatus: 'http_402',
                rawDetail: $detail,
            );
        }

        // 4xx: o MP não criou o pagamento (token inválido/expirado, parâmetro ruim). Libera a tentativa como recusa.
        if ($response->failed()) {
            Log::warning("Mercado Pago: cartão rejeitado na validação (HTTP {$response->status()}); payment={$request->paymentId} errors=".json_encode(data_get($response->json(), 'errors')));

            return new ChargeResult(
                outcome: ChargeOutcome::DECLINED,
                transactionStatus: 'http_'.$response->status(),
                rawStatus: 'http_'.$response->status(),
                rawDetail: (string) data_get($response->json(), 'errors.0.code', data_get($response->json(), 'error')),
            );
        }

        $data = $response->json() ?? [];

        $paid = data_get($data, 'total_paid_amount');
        if ($paid !== null && abs((float) $paid - $request->amountCents / 100) > 0.009) {
            Log::error("Mercado Pago: total_paid_amount diverge do valor do Payment; payment={$request->paymentId} esperado=".($request->amountCents / 100)." pago={$paid}");
        }

        return ChargeResult::fromMercadoPagoOrder($data);
    }

    public function getPixStatus(string $externalId): NormalizedStatus
    {
        try {
            $response = $this->http($this->queryTimeout())->get($this->url("/v1/orders/{$externalId}"));
        } catch (ConnectionException $e) {
            throw $this->unavailable('falha de conexão', previous: $e);
        }

        if ($this->isInfraFailure($response)) {
            throw $this->unavailable("HTTP {$response->status()}", $response);
        }

        return $response->successful() ? MercadoPagoStatusMap::pix($response->json() ?? []) : new NormalizedStatus;
    }

    public function getCardStatus(string $externalId): ?ChargeResult
    {
        try {
            $response = $this->http($this->queryTimeout())->get($this->url("/v1/orders/{$externalId}"));
        } catch (ConnectionException $e) {
            throw $this->unavailable('falha de conexão', previous: $e);
        }

        return $response->successful() ? ChargeResult::fromMercadoPagoOrder($response->json() ?? []) : null;
    }

    /**
     * A Orders API não tem busca por `external_reference`: sem o id da ordem não dá para PROVAR que não houve cobrança.
     * Sempre inconclusivo (a tentativa fica retida, nunca libera para nova cobrança) — resolver à mão. Ver spike S14.
     */
    public function findCardAttempt(string $paymentId): ?ChargeResult
    {
        throw $this->unavailable('Orders API não permite buscar a ordem por referência');
    }

    /** Cancela uma ordem PIX pendente (`POST /v1/orders/{id}/cancel`). Não usado em fluxo até o spike S5 confirmar o comportamento. */
    public function cancelPix(string $externalId): bool
    {
        try {
            return $this->http($this->queryTimeout(), (string) Str::uuid())
                ->post($this->url("/v1/orders/{$externalId}/cancel"))
                ->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    public function cardCheckoutConfig(): ?CardCheckoutConfig
    {
        $key = config('gateways.mercadopago.public_key');

        return $this->isEnabled() && filled($key)
            ? new CardCheckoutConfig(GatewayEnum::MERCADOPAGO, $key)
            : null;
    }
}
