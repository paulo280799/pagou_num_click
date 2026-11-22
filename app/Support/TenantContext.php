<?php

namespace App\Support;

/**
 * Conta (tenant) da requisição atual. Duas origens, nesta ordem:
 *  1. definida explicitamente (API: `ApiTokenMiddleware` resolve a conta pelo token, sem logar usuário);
 *  2. conta do usuário autenticado (painel Filament).
 * Sem nenhuma das duas, `accountId()` é null e o `UserScope` não devolve nada (falha fechada).
 * Registrado como `scoped` no container: um contexto novo por requisição/job.
 */
class TenantContext
{
    private ?string $accountId = null;

    public function set(?string $accountId): void
    {
        $this->accountId = $accountId;
    }

    public function accountId(): ?string
    {
        return $this->accountId ?? auth()->user()?->account_id;
    }

    /** Para quem grava: sem tenant não se cria registro de conta nenhuma. */
    public function requireAccountId(): string
    {
        return $this->accountId() ?? throw new \LogicException('Nenhuma conta (tenant) definida para gravar este registro.');
    }
}
