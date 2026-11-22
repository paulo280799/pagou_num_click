<?php

namespace App\Support\Gateways;

/** Entrada da cobrança de cartão. O valor vem sempre do Payment, nunca do browser. */
final class CardRequest
{
    public function __construct(
        public readonly string $paymentId,
        public readonly int $amountCents,
        public readonly int $installments,
        public readonly string $cardToken,
        public readonly array $customer,
        public readonly string $refExternal,
        public readonly ?array $billingAddress = null,
        public readonly ?string $paymentMethodId = null,
        public readonly ?string $issuerId = null,
        /** itens em centavos (`amount`, `description`, `quantity`) */
        public readonly array $items = [],
        /** Device ID do MercadoPago.js (antifraude); só existe quando o navegador o gerou */
        public readonly ?string $deviceId = null,
    ) {}
}
