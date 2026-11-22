<?php

namespace App\Services\Gateways;

use App\Enums\GatewayEnum;
use Illuminate\Support\Facades\Cache;

/**
 * Circuit breaker por gateway sobre o Cache do Laravel (nunca Redis direto: produção usa `file`, testes `array`).
 * N falhas dentro da janela abrem o circuito; passado o cooldown ele fica meio-aberto (a próxima chamada é a sonda):
 * sucesso fecha, falha reabre na hora.
 */
class CircuitBreaker
{
    private function key(GatewayEnum $gateway, string $name): string
    {
        return "gateways:circuit:{$gateway->value}:{$name}";
    }

    public function isOpen(GatewayEnum $gateway): bool
    {
        $openedAt = Cache::get($this->key($gateway, 'opened_at'));

        return $openedAt !== null && now()->timestamp < $openedAt + (int) config('gateways.circuit.cooldown');
    }

    /** Qualquer sinal de falha recente (circuito aberto, meio-aberto ou falhas dentro da janela). */
    public function isDegraded(GatewayEnum $gateway): bool
    {
        return Cache::get($this->key($gateway, 'opened_at')) !== null
            || (int) Cache::get($this->key($gateway, 'failures'), 0) > 0;
    }

    public function recordFailure(GatewayEnum $gateway): void
    {
        $opened = $this->key($gateway, 'opened_at');
        $failures = $this->key($gateway, 'failures');

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

    public function recordSuccess(GatewayEnum $gateway): void
    {
        Cache::forget($this->key($gateway, 'failures'));
        Cache::forget($this->key($gateway, 'opened_at'));
    }
}
