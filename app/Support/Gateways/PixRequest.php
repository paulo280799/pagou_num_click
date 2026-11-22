<?php

namespace App\Support\Gateways;

use Illuminate\Support\Carbon;

/** Entrada da criação de PIX. `items` e valores em centavos; `customer` já normalizado (documento só dígitos). */
final class PixRequest
{
    public function __construct(
        public readonly string $paymentId,
        public readonly int $amountCents,
        public readonly array $items,
        public readonly array $customer,
        public readonly Carbon $expiresAt,
        public readonly string $refExternal,
    ) {}
}
