<?php

namespace App\Support\Gateways;

/** Credenciais do Pagar.me de UMA conta: chave secreta (Basic Auth) e chave pública (tokenização no navegador). */
final class PagarmeCredentials
{
    public function __construct(
        public readonly string $user,
        public readonly ?string $password = null,
        public readonly ?string $publicKey = null,
    ) {}

    /** sk_test_... / pk_test_... = ambiente de teste. */
    public function isTest(): bool
    {
        return str_contains($this->user, '_test_');
    }
}
