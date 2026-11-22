<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Super-admin opera a plataforma, não uma conta de cliente: o painel /admin (Dashboard, Pagamentos, Gateways da
 * "conta" que existe só porque users.account_id é obrigatório) não faz sentido para ele. Quem logar por /admin vai
 * para /platform. O logout continua passando.
 */
class KeepSuperAdminsOnPlatform
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_super_admin && ! $request->routeIs('filament.admin.auth.logout')) {
            return redirect('/platform');
        }

        return $next($request);
    }
}
