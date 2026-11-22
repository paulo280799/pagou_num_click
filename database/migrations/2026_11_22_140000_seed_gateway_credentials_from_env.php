<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Preserva o comportamento de antes do multi-tenant: até aqui TODAS as contas existentes cobravam com as credenciais do
 * .env. Copia-as (criptografadas) para `gateway_credentials` de cada conta que já existe e ainda não tem linha.
 * Contas criadas depois nascem sem gateway e cadastram as próprias no painel. Roda sem SSH, junto do `migrate`.
 * Idempotente. Sem credencial no .env (instalação nova) não faz nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        $envs = [
            'PAGARME' => filled(config('integration.user_integration')) ? array_filter([
                'user' => config('integration.user_integration'),
                'password' => config('integration.password_integration'),
                'public_key' => config('integration.public_key'),
            ], fn ($v) => $v !== null) : null,
            'MERCADOPAGO' => config('gateways.mercadopago.enabled') && filled(config('gateways.mercadopago.access_token')) ? array_filter([
                'access_token' => config('gateways.mercadopago.access_token'),
                'public_key' => config('gateways.mercadopago.public_key'),
                'webhook_secret' => config('gateways.mercadopago.webhook_secret'),
            ], fn ($v) => $v !== null) : null,
        ];

        foreach (DB::table('accounts')->pluck('id') as $accountId) {
            foreach ($envs as $gateway => $credentials) {
                if ($credentials === null || DB::table('gateway_credentials')->where('account_id', $accountId)->where('gateway', $gateway)->exists()) {
                    continue;
                }

                DB::table('gateway_credentials')->insert([
                    'id' => (string) Str::uuid(),
                    'account_id' => $accountId,
                    'gateway' => $gateway,
                    // mesmo formato do cast `encrypted:array`
                    'credentials' => Crypt::encryptString(json_encode($credentials)),
                    'enabled' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // dados copiados do .env: nada a desfazer (o .env continua valendo para GATEWAYS_ENV_ACCOUNT_ID)
    }
};
