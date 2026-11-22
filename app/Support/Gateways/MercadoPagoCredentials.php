<?php

namespace App\Support\Gateways;

/** Credenciais do Mercado Pago de UMA conta. `webhookSecret` valida o webhook de entrada dela. */
final class MercadoPagoCredentials
{
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $publicKey = null,
        public readonly ?string $webhookSecret = null,
    ) {}

    /** TEST-... = ambiente de teste. */
    public function isTest(): bool
    {
        return str_starts_with($this->accessToken, 'TEST-');
    }
}
