<?php

namespace App\Services\Gateways;

use App\Enums\GatewayEnum;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Valida credenciais com uma chamada de leitura ao provedor (não cria cobrança): o cliente descobre na hora de salvar,
 * e não na primeira venda, que colou a chave errada.
 */
class CredentialTester
{
    /** @return array{0: bool, 1: ?string} [ok, motivo] */
    public function test(GatewayEnum $gateway, array $credentials): array
    {
        try {
            $response = match ($gateway) {
                GatewayEnum::PAGARME => Http::timeout(10)
                    ->withBasicAuth((string) ($credentials['user'] ?? ''), (string) ($credentials['password'] ?? ''))
                    ->get(config('integration.url_integration').'/v5/orders', ['size' => 1]),
                GatewayEnum::MERCADOPAGO => Http::timeout(10)
                    ->withToken((string) ($credentials['access_token'] ?? ''))->acceptJson()
                    ->get(rtrim(config('gateways.mercadopago.url'), '/').'/users/me'),
            };
        } catch (ConnectionException) {
            return [false, 'Não foi possível falar com o provedor agora. Tente de novo em instantes.'];
        }

        if ($response->successful()) {
            return [true, null];
        }

        return [false, in_array($response->status(), [401, 403], true)
            ? 'O provedor recusou a credencial (chave inválida ou sem permissão).'
            : "O provedor respondeu {$response->status()}; confira a credencial."];
    }
}
