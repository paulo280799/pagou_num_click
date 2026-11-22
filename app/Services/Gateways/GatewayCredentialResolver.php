<?php

namespace App\Services\Gateways;

use App\Enums\GatewayEnum;
use App\Models\GatewayCredential;
use App\Support\Gateways\MercadoPagoCredentials;
use App\Support\Gateways\PagarmeCredentials;

/**
 * Credenciais do provedor de uma conta. Fonte: `gateway_credentials`. "Desativar" vale só para NOVAS cobranças (`$includeDisabled = false`); as que já existem continuam sendo consultadas com as credenciais do gateway que as criou (`true`). Compatibilidade: a conta indicada em
 * `gateways.env_credentials_account` (GATEWAYS_ENV_ACCOUNT_ID; `*` só em dev/teste) cai nas variáveis do .env quando
 * não cadastrou nada no painel. Qualquer outra conta sem credencial fica SEM o gateway: nunca se usa credencial alheia.
 */
class GatewayCredentialResolver
{
    private function row(string $accountId, GatewayEnum $gateway): ?GatewayCredential
    {
        return GatewayCredential::withoutGlobalScopes()
            ->where('account_id', $accountId)->where('gateway', $gateway->value)->first();
    }

    private function usesEnv(string $accountId): bool
    {
        $owner = config('gateways.env_credentials_account');

        return filled($owner) && ($owner === '*' || $owner === $accountId);
    }

    /** @param bool $includeDisabled credenciais também de um gateway desativado: cobranças que já existem seguem sendo acompanhadas por ele */
    public function pagarme(string $accountId, bool $includeDisabled = false): ?PagarmeCredentials
    {
        $row = $this->row($accountId, GatewayEnum::PAGARME);

        if ($row) {
            $c = $row->credentials;

            return ($row->enabled || $includeDisabled) && filled($c['user'] ?? null)
                ? new PagarmeCredentials($c['user'], $c['password'] ?? null, $c['public_key'] ?? null)
                : null;
        }

        // o Pagar.me é o provedor original da instalação: com a conta autorizada a usar o .env ele sempre existe
        return $this->usesEnv($accountId)
            ? new PagarmeCredentials(
                (string) config('integration.user_integration'),
                config('integration.password_integration'),
                config('integration.public_key'),
            )
            : null;
    }

    public function mercadoPago(string $accountId, bool $includeDisabled = false): ?MercadoPagoCredentials
    {
        $row = $this->row($accountId, GatewayEnum::MERCADOPAGO);

        if ($row) {
            $c = $row->credentials;

            return ($row->enabled || $includeDisabled) && filled($c['access_token'] ?? null)
                ? new MercadoPagoCredentials($c['access_token'], $c['public_key'] ?? null, $c['webhook_secret'] ?? null)
                : null;
        }

        return $this->usesEnv($accountId) && config('gateways.mercadopago.enabled') && filled(config('gateways.mercadopago.access_token'))
            ? new MercadoPagoCredentials(
                (string) config('gateways.mercadopago.access_token'),
                config('gateways.mercadopago.public_key'),
                config('gateways.mercadopago.webhook_secret'),
            )
            : null;
    }

    public function for(string $accountId, GatewayEnum $gateway): PagarmeCredentials|MercadoPagoCredentials|null
    {
        return $gateway === GatewayEnum::PAGARME ? $this->pagarme($accountId) : $this->mercadoPago($accountId);
    }
}
