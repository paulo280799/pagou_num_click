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

        $token = trim(str_replace('Bearer ', '', $token));

        // só o hash do token existe no banco; ver Config::findByToken (aceita o token anterior durante a rotação)
        $config = $token === '' ? null : Config::findByToken($token);

        if (! $config) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if ($config->account()->value('suspended_at') !== null) {
            return response()->json(['error' => 'Account suspended'], 403);
        }

        // O token identifica a conta (tenant); nenhum usuário é logado. UserScope e os creating() leem daqui.
        app(TenantContext::class)->set($config->account_id);

        // marca o uso no máximo a cada 5 min (sem eventos/updated_at) para não gravar a cada requisição
        if (! $config->api_token_last_used_at || $config->api_token_last_used_at->lt(now()->subMinutes(5))) {
            Config::withoutGlobalScopes()->whereKey($config->id)->toBase()->update(['api_token_last_used_at' => now()]);
        }

        return $next($request);
    }
}
