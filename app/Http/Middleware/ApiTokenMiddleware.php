<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Config;
use App\Models\User;

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
        if (!$token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $token = str_replace('Bearer ', '', $token);

        // Verificar se o token pertence a um usuário autenticado
        $config = Config::withoutGlobalScopes()->where('api_token', $token)->first();

        if (!$config) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $user = User::where('account_id', $config->account_id)->first();
        // Autenticar o usuário via API
        auth()->loginUsingId($user->id);

        return $next($request);
    }
}
