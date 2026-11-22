<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

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
        self::tagLogs($accountId);
    }

    /** Todo log a partir daqui leva `account_id` (requisição de API, scheduler, webhooks): dá para filtrar por cliente. */
    public static function tagLogs(?string $accountId): void
    {
        Log::withContext(['account_id' => $accountId]);
    }

    public function accountId(): ?string
    {
        return $this->accountId ?? auth()->user()?->account_id;
    }

    /**
     * Conta já conhecida, SEM provocar a resolução do usuário autenticado (que faz uma query). Para ganchos que rodam
     * dentro de outra query — ex.: a tag do Telescope, que é chamada ao registrar a própria query do usuário e,
     * com `auth()->user()`, entraria em recursão infinita.
     */
    public function peekAccountId(): ?string
    {
        return $this->accountId ?? (auth()->hasUser() ? auth()->user()->account_id : null);
    }

    /** Para quem grava: sem tenant não se cria registro de conta nenhuma. */
    public function requireAccountId(): string
    {
        return $this->accountId() ?? throw new \LogicException('Nenhuma conta (tenant) definida para gravar este registro.');
    }
}
