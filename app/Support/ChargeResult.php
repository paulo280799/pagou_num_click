<?php

namespace App\Support;

use App\Services\Gateways\MercadoPagoStatusMap;
use App\Services\Gateways\PagarmeStatusMap;

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
        public readonly ?string $rawStatus = null,
        public readonly ?string $rawDetail = null,
    ) {}

    /**
     * Mapeia a resposta de um pedido de cartão do Pagar.me (status em PagarmeStatusMap).
     */
    public static function fromOrderResponse(array $order): self
    {
        $tx = data_get($order, 'charges.0.last_transaction');
        [$rawStatus, $rawDetail] = PagarmeStatusMap::cardRaw($order);

        $lastFour = data_get($tx, 'card.last_four_digits');
        $installments = data_get($tx, 'installments');

        return new self(
            outcome: PagarmeStatusMap::cardOutcome($order),
            orderId: data_get($order, 'id'),
            brand: data_get($tx, 'card.brand'),
            lastFour: $lastFour !== null ? (string) $lastFour : null,
            installments: $installments !== null ? (int) $installments : null,
            tid: data_get($tx, 'acquirer_tid'),
            nsu: data_get($tx, 'acquirer_nsu'),
            authCode: data_get($tx, 'acquirer_auth_code'),
            acquirerReturnCode: data_get($tx, 'acquirer_return_code') !== null ? (string) data_get($tx, 'acquirer_return_code') : null,
            transactionStatus: data_get($tx, 'status'),
            rawStatus: $rawStatus,
            rawDetail: $rawDetail,
        );
    }

    /**
     * Mapeia um pagamento de cartão do Mercado Pago (Payments API). O resultado vem de `status`/`status_detail`.
     * TID/NSU não têm campo confirmado (spike S7): só o código de autorização é preenchido.
     */
    /** Ordem do Mercado Pago (Orders API); a transação de cartão fica em `transactions.payments[0]`. */
    public static function fromMercadoPagoOrder(array $order): self
    {
        $normalized = MercadoPagoStatusMap::cardNormalized($order);
        $method = data_get($order, 'transactions.payments.0.payment_method', []);
        $installments = data_get($method, 'installments');
        $id = data_get($order, 'id');

        return new self(
            outcome: $normalized->outcome,
            orderId: $id !== null ? (string) $id : null,
            brand: data_get($method, 'id'),
            lastFour: null,
            installments: $installments !== null ? (int) $installments : null,
            authCode: null,
            acquirerReturnCode: $normalized->rawDetail,
            transactionStatus: $normalized->rawStatus,
            rawStatus: $normalized->rawStatus,
            rawDetail: $normalized->rawDetail,
        );
    }
}
