<?php

namespace App\Support\Gateways;

use App\Enums\StatusPaymentEnum;
use App\Support\ChargeOutcome;

/**
 * Status de um gateway já traduzido para o vocabulário interno (PIX: `status`; cartão: `outcome`),
 * mais o status/detalhe crus para auditoria. `known` = o gateway devolveu um status que conhecemos;
 * `unsupported` = conhecido mas sem equivalente no sistema (estorno, chargeback): só vira log.
 */
final class NormalizedStatus
{
    public function __construct(
        public readonly ?StatusPaymentEnum $status = null,
        public readonly ?ChargeOutcome $outcome = null,
        public readonly ?string $rawStatus = null,
        public readonly ?string $rawDetail = null,
        public readonly bool $unsupported = false,
    ) {}

    public function isKnown(): bool
    {
        return $this->status !== null || $this->outcome !== null || $this->unsupported;
    }

    public function isUnsupported(): bool
    {
        return $this->unsupported;
    }
}
