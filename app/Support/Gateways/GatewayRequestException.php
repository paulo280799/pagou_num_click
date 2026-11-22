<?php

namespace App\Support\Gateways;

use App\Enums\GatewayEnum;
use RuntimeException;

/** Resposta de negócio do gateway (4xx de validação): não aciona failover, carrega o corpo do provedor. */
class GatewayRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly GatewayEnum $gateway,
        public readonly int $httpStatus,
        public readonly mixed $body = null,
    ) {
        parent::__construct($message);
    }
}
