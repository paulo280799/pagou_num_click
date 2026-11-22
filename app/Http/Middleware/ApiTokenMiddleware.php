<?php

namespace App\Http\Middleware;

use App\Models\Config;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiTokenMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->header('Authorization');

        // Se o token estiver presente, verificar se é válido
        if (! $token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $token = str_replace('Bearer ', '', $token);

        // Verificar se o token pertence a um usuário autenticado
        $config = Config::withoutGlobalScopes()->where('api_token', $token)->first();

        if (! $config) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // O token identifica a conta (tenant); nenhum usuário é logado. UserScope e os creating() leem daqui.
        app(TenantContext::class)->set($config->account_id);

        return $next($request);
    }
}
