<?php

namespace App\Services\Gateways;

use App\Enums\StatusPaymentEnum;
use App\Support\ChargeOutcome;
use App\Support\Gateways\NormalizedStatus;

/** Status cru do Pagar.me → vocabulário interno. Nenhum controller lê status de provedor direto. */
final class PagarmeStatusMap
{
    private const UNSUPPORTED_PIX = ['pending_refund', 'refunded'];

    /** PIX: `order.status` decide; `charges.0.last_transaction.status` fica como detalhe. */
    public static function pix(array $order): NormalizedStatus
    {
        $raw = data_get($order, 'status');
        $detail = data_get($order, 'charges.0.last_transaction.status');
        $raw = is_string($raw) && $raw !== '' ? $raw : null;
        $detail = is_string($detail) && $detail !== '' ? $detail : null;

        if ($raw === null) {
            return new NormalizedStatus(rawDetail: $detail);
        }

        $status = StatusPaymentEnum::tryFrom(strtoupper($raw));

        return new NormalizedStatus(
            status: $status,
            rawStatus: $raw,
            rawDetail: $detail,
            unsupported: $status === null && in_array($raw, self::UNSUPPORTED_PIX, true),
        );
    }

    /** Cartão: fonte da verdade é `charges[0].last_transaction.status`. Acesso defensivo (charge `failed` pode vir sem last_transaction). */
    public static function cardOutcome(array $order): ChargeOutcome
    {
        $charge = data_get($order, 'charges.0');
        $txStatus = data_get($charge, 'last_transaction.status');

        return match (true) {
            ! is_array($charge) => ChargeOutcome::UNKNOWN,
            $txStatus === 'captured' => ChargeOutcome::PAID,
            in_array($txStatus, ['not_authorized', 'failed', 'with_error'], true) => ChargeOutcome::DECLINED,
            in_array($txStatus, ['authorized_pending_capture', 'waiting_capture', 'partial_capture'], true) => ChargeOutcome::PENDING_REVIEW,
            $txStatus === null && data_get($charge, 'status') === 'failed' => ChargeOutcome::DECLINED,
            default => ChargeOutcome::UNKNOWN,
        };
    }

    /** Status cru do cartão para auditoria: (transação, charge). */
    public static function cardRaw(array $order): array
    {
        $tx = data_get($order, 'charges.0.last_transaction.status');
        $charge = data_get($order, 'charges.0.status');

        return [
            is_string($tx) ? $tx : (is_string($charge) ? $charge : null),
            is_string($tx) && is_string($charge) ? $charge : null,
        ];
    }
}
