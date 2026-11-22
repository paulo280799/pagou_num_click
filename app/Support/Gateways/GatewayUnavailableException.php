<?php

namespace App\Support\Gateways;

use App\Enums\GatewayEnum;
use RuntimeException;
use Throwable;

/** Falha de infra do gateway (conexão, timeout, 5xx, 429, charge failed sem QR): habilita o failover. */
class GatewayUnavailableException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?GatewayEnum $gateway = null,
        public readonly ?int $httpStatus = null,
        public readonly mixed $body = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
