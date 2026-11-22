<?php

namespace App\Http\Middleware;

use App\Models\Config;
use App\Support\TenantContext;
use Closure;
use Illuminate\Support\Facades\RateLimiter;
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
        // um contexto de uma requisição anterior (testes, workers) nunca vale para esta
        app(TenantContext::class)->set(null);

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

        // cota por CONTA (um cliente barulhento não consome a dos outros). Fica aqui, e não em `throttle:`, porque o
        // Laravel ordena o throttle antes de api.token e a conta ainda não seria conhecida.
        $limiterKey = 'account-api:'.$config->account_id;
        $limit = (int) config('tenancy.api_rate_limit_per_minute');

        if (RateLimiter::tooManyAttempts($limiterKey, $limit)) {
            return response()->json(['error' => 'Too Many Requests'], 429, [
                'Retry-After' => RateLimiter::availableIn($limiterKey),
                'X-RateLimit-Limit' => $limit,
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        RateLimiter::hit($limiterKey, 60);

        // marca o uso no máximo a cada 5 min (sem eventos/updated_at) para não gravar a cada requisição
        if (! $config->api_token_last_used_at || $config->api_token_last_used_at->lt(now()->subMinutes(5))) {
            Config::withoutGlobalScopes()->whereKey($config->id)->toBase()->update(['api_token_last_used_at' => now()]);
        }

        return $next($request);
    }
}
