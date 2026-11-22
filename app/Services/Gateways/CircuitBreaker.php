<?php

namespace App\Services\Gateways;

use App\Enums\GatewayEnum;
use Illuminate\Support\Facades\Cache;

/**
 * Circuit breaker por conta e gateway (a falha de um tenant não abre o circuito de outro) sobre o Cache do Laravel (nunca Redis direto: produção usa `file`, testes `array`).
 * N falhas dentro da janela abrem o circuito; passado o cooldown ele fica meio-aberto (a próxima chamada é a sonda):
 * sucesso fecha, falha reabre na hora.
 */
class CircuitBreaker
{
    public static function key(string $accountId, GatewayEnum $gateway, string $name): string
    {
        return "gateways:circuit:{$accountId}:{$gateway->value}:{$name}";
    }

    public function isOpen(string $accountId, GatewayEnum $gateway): bool
    {
        $openedAt = Cache::get(self::key($accountId, $gateway, 'opened_at'));

        return $openedAt !== null && now()->timestamp < $openedAt + (int) config('gateways.circuit.cooldown');
    }

    /** Qualquer sinal de falha recente (circuito aberto, meio-aberto ou falhas dentro da janela). */
    public function isDegraded(string $accountId, GatewayEnum $gateway): bool
    {
        return Cache::get(self::key($accountId, $gateway, 'opened_at')) !== null
            || (int) Cache::get(self::key($accountId, $gateway, 'failures'), 0) > 0;
    }

    public function recordFailure(string $accountId, GatewayEnum $gateway): void
    {
        $opened = self::key($accountId, $gateway, 'opened_at');
        $failures = self::key($accountId, $gateway, 'failures');

        // sonda do meio-aberto falhou: reabre sem esperar novas falhas
        if (Cache::get($opened) !== null) {
            Cache::forever($opened, now()->timestamp);

            return;
        }

        $count = (int) Cache::get($failures, 0) + 1;

        if ($count >= (int) config('gateways.circuit.failures')) {
            Cache::forever($opened, now()->timestamp);
            Cache::forget($failures);

            return;
        }

        Cache::put($failures, $count, (int) config('gateways.circuit.window'));
    }

    public function recordSuccess(string $accountId, GatewayEnum $gateway): void
    {
        Cache::forget(self::key($accountId, $gateway, 'failures'));
        Cache::forget(self::key($accountId, $gateway, 'opened_at'));
    }
}
