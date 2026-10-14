<?php

namespace App\Support;

final class ChargeResult
{
    public function __construct(
        public readonly ChargeOutcome $outcome,
        public readonly ?string $orderId = null,
        public readonly ?string $brand = null,
        public readonly ?string $lastFour = null,
        public readonly ?int $installments = null,
        public readonly ?string $tid = null,
        public readonly ?string $nsu = null,
        public readonly ?string $authCode = null,
        public readonly ?string $acquirerReturnCode = null,
        public readonly ?string $transactionStatus = null,
    ) {}

    /**
     * Mapeia a resposta de um pedido de cartão. Fonte da verdade: charges[0].last_transaction.status.
     * Acesso sempre defensivo: charge `failed` com HTTP 200 pode vir sem last_transaction.
     */
    public static function fromOrderResponse(array $order): self
    {
        $charge = data_get($order, 'charges.0');
        $tx = data_get($charge, 'last_transaction');
        $txStatus = data_get($tx, 'status');

        $outcome = match (true) {
            ! is_array($charge) => ChargeOutcome::UNKNOWN,
            $txStatus === 'captured' => ChargeOutcome::PAID,
            in_array($txStatus, ['not_authorized', 'failed', 'with_error'], true) => ChargeOutcome::DECLINED,
            in_array($txStatus, ['authorized_pending_capture', 'waiting_capture', 'partial_capture'], true) => ChargeOutcome::PENDING_REVIEW,
            $txStatus === null && data_get($charge, 'status') === 'failed' => ChargeOutcome::DECLINED,
            default => ChargeOutcome::UNKNOWN,
        };

        $lastFour = data_get($tx, 'card.last_four_digits');
        $installments = data_get($tx, 'installments');

        return new self(
            outcome: $outcome,
            orderId: data_get($order, 'id'),
            brand: data_get($tx, 'card.brand'),
            lastFour: $lastFour !== null ? (string) $lastFour : null,
            installments: $installments !== null ? (int) $installments : null,
            tid: data_get($tx, 'acquirer_tid'),
            nsu: data_get($tx, 'acquirer_nsu'),
            authCode: data_get($tx, 'acquirer_auth_code'),
            acquirerReturnCode: data_get($tx, 'acquirer_return_code') !== null ? (string) data_get($tx, 'acquirer_return_code') : null,
            transactionStatus: $txStatus,
        );
    }
}
