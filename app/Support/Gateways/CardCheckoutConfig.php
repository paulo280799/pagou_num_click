<?php

namespace App\Support\Gateways;

use App\Enums\GatewayEnum;

/** O que o browser precisa para tokenizar o cartão no gateway vigente. */
final class CardCheckoutConfig
{
    public function __construct(
        public readonly GatewayEnum $gateway,
        public readonly string $publicKey,
        public readonly ?string $tokenUrl = null,
    ) {}

    public function toArray(): array
    {
        return [
            'gateway' => strtolower($this->gateway->value),
            'publicKey' => $this->publicKey,
            'tokenUrl' => $this->tokenUrl,
        ];
    }
}
