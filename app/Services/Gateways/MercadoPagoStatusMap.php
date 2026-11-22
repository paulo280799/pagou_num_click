<?php

namespace App\Services\Gateways;

use App\Enums\StatusPaymentEnum;
use App\Support\ChargeOutcome;
use App\Support\Gateways\NormalizedStatus;

/**
 * Status cru do Mercado Pago (Orders API, `/v1/orders`) → vocabulário interno.
 * Lê o status da ORDEM (`status` + `status_detail`); o detalhe da recusa vem da transação (`transactions.payments[0]`).
 */
final class MercadoPagoStatusMap
{
    private const UNSUPPORTED = ['refunded', 'charged_back'];

    private static function str(array $order, string $key): ?string
    {
        $v = data_get($order, $key);

        return is_string($v) && $v !== '' ? $v : null;
    }

    /** Detalhe mais específico: o da transação (ex.: rejected_by_issuer), senão o da ordem. */
    private static function detail(array $order): ?string
    {
        return self::str($order, 'transactions.payments.0.status_detail') ?? self::str($order, 'status_detail');
    }

    private static function expired(array $order): bool
    {
        return self::str($order, 'status_detail') === 'expired'
            || self::str($order, 'transactions.payments.0.status_detail') === 'expired';
    }

    public static function pix(array $order): NormalizedStatus
    {
        $raw = self::str($order, 'status');

        $status = match ($raw) {
            'created', 'processing', 'in_review', 'action_required' => StatusPaymentEnum::PENDING,
            'processed' => StatusPaymentEnum::PAID,
            'canceled' => StatusPaymentEnum::CANCELED,
            // order expirada deixa de ser paga: o fluxo de expiração do sistema decide o cancelamento
            'failed' => self::expired($order) ? StatusPaymentEnum::CANCELED : StatusPaymentEnum::FAILED,
            default => null,
        };

        return new NormalizedStatus(
            status: $status,
            rawStatus: $raw,
            rawDetail: self::detail($order),
            unsupported: $raw !== null && in_array($raw, self::UNSUPPORTED, true),
        );
    }

    public static function cardOutcome(array $order): ChargeOutcome
    {
        return match (self::str($order, 'status')) {
            'processed' => ChargeOutcome::PAID,
            'failed', 'canceled' => ChargeOutcome::DECLINED,
            'created', 'processing', 'in_review', 'action_required' => ChargeOutcome::PENDING_REVIEW,
            default => ChargeOutcome::UNKNOWN,
        };
    }

    public static function cardNormalized(array $order): NormalizedStatus
    {
        $raw = self::str($order, 'status');

        return new NormalizedStatus(
            outcome: self::cardOutcome($order),
            rawStatus: $raw,
            rawDetail: self::detail($order),
            unsupported: $raw !== null && in_array($raw, self::UNSUPPORTED, true),
        );
    }
}
