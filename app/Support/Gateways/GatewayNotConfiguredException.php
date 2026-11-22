<?php

namespace App\Support\Gateways;

use RuntimeException;

/** A conta não cadastrou credencial de nenhum gateway: não há onde criar a cobrança. */
class GatewayNotConfiguredException extends RuntimeException
{
    public function __construct(string $message = 'Nenhum gateway de pagamento configurado para esta conta.')
    {
        parent::__construct($message);
    }
}
