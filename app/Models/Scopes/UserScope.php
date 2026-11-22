<?php

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class UserScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $accountId = app(TenantContext::class)->accountId();

        // sem tenant (nem API, nem usuário logado) não há o que mostrar: falha fechada em vez de 500 ou vazamento.
        // Quem roda fora de uma requisição de tenant (scheduler, checkout público) usa withoutGlobalScopes().
        if ($accountId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('account_id'), '=', $accountId);
    }
}
