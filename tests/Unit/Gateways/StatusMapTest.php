<?php

namespace Tests\Unit\Gateways;

use App\Enums\StatusPaymentEnum;
use App\Services\Gateways\MercadoPagoStatusMap;
use App\Services\Gateways\PagarmeStatusMap;
use App\Support\ChargeOutcome;
use App\Support\ChargeResult;
use PHPUnit\Framework\TestCase;

class StatusMapTest extends TestCase
{
    /** @dataProvider pagarmePix */
    public function test_pagarme_pix_status(?string $raw, ?StatusPaymentEnum $status, bool $known, bool $unsupported = false): void
    {
        $n = PagarmeStatusMap::pix($raw === null ? [] : ['status' => $raw, 'charges' => [['last_transaction' => ['status' => 'waiting_payment']]]]);

        $this->assertSame($status, $n->status);
        $this->assertSame($known, $n->isKnown());
        $this->assertSame($unsupported, $n->isUnsupported());
        $this->assertSame($raw, $n->rawStatus);
    }

    public static function pagarmePix(): array
    {
        return [
            'pending' => ['pending', StatusPaymentEnum::PENDING, true],
            'paid' => ['paid', StatusPaymentEnum::PAID, true],
            'failed' => ['failed', StatusPaymentEnum::FAILED, true],
            'canceled' => ['canceled', StatusPaymentEnum::CANCELED, true],
            'refunded' => ['refunded', null, true, true],
            'pending_refund' => ['pending_refund', null, true, true],
            'unknown' => ['something_else', null, false],
            'absent' => [null, null, false],
        ];
    }

    public function test_pagarme_pix_keeps_last_transaction_status_as_detail(): void
    {
        $n = PagarmeStatusMap::pix(['status' => 'pending', 'charges' => [['last_transaction' => ['status' => 'waiting_payment']]]]);

        $this->assertSame('waiting_payment', $n->rawDetail);
    }

    /** @dataProvider mercadoPagoPix */
    public function test_mercado_pago_pix_status(string $raw, ?string $detail, ?StatusPaymentEnum $status, bool $known, bool $unsupported = false): void
    {
        $n = MercadoPagoStatusMap::pix(['status' => $raw, 'status_detail' => $detail]);

        $this->assertSame($status, $n->status);
        $this->assertSame($known, $n->isKnown());
        $this->assertSame($unsupported, $n->isUnsupported());
        $this->assertSame($raw, $n->rawStatus);
        $this->assertSame($detail, $n->rawDetail);
    }

    public static function mercadoPagoPix(): array
    {
        return [
            'created' => ['created', 'created', StatusPaymentEnum::PENDING, true],
            'processing' => ['processing', 'in_process', StatusPaymentEnum::PENDING, true],
            'in review (cancel in progress)' => ['in_review', 'in_review', StatusPaymentEnum::PENDING, true],
            'waiting transfer' => ['action_required', 'waiting_transfer', StatusPaymentEnum::PENDING, true],
            'waiting payment' => ['action_required', 'waiting_payment', StatusPaymentEnum::PENDING, true],
            'processed' => ['processed', 'accredited', StatusPaymentEnum::PAID, true],
            'processed partially refunded' => ['processed', 'partially_refunded', StatusPaymentEnum::PAID, true],
            'failed' => ['failed', 'failed', StatusPaymentEnum::FAILED, true],
            'failed expired' => ['failed', 'expired', StatusPaymentEnum::CANCELED, true],
            'canceled expired' => ['canceled', 'expired', StatusPaymentEnum::CANCELED, true],
            'canceled' => ['canceled', 'canceled', StatusPaymentEnum::CANCELED, true],
            'refunded' => ['refunded', 'refunded', null, true, true],
            'charged_back' => ['charged_back', 'settled', null, true, true],
            'unknown' => ['brand_new_status', null, null, false],
        ];
    }

    /** @dataProvider pagarmeCard */
    public function test_pagarme_card_outcome(?string $tx, ?string $charge, ChargeOutcome $expected): void
    {
        $order = ['id' => 'or_1', 'charges' => [array_filter(['status' => $charge, 'last_transaction' => $tx ? ['status' => $tx] : null])]];

        $this->assertSame($expected, PagarmeStatusMap::cardOutcome($order));
        $this->assertSame($expected, ChargeResult::fromOrderResponse($order)->outcome);
    }

    public static function pagarmeCard(): array
    {
        return [
            'captured' => ['captured', 'paid', ChargeOutcome::PAID],
            'not_authorized' => ['not_authorized', 'failed', ChargeOutcome::DECLINED],
            'failed' => ['failed', 'failed', ChargeOutcome::DECLINED],
            'with_error' => ['with_error', 'failed', ChargeOutcome::DECLINED],
            'charge failed without transaction' => [null, 'failed', ChargeOutcome::DECLINED],
            'authorized_pending_capture' => ['authorized_pending_capture', 'pending', ChargeOutcome::PENDING_REVIEW],
            'waiting_capture' => ['waiting_capture', 'pending', ChargeOutcome::PENDING_REVIEW],
            'partial_capture' => ['partial_capture', 'pending', ChargeOutcome::PENDING_REVIEW],
            'voided' => ['voided', 'canceled', ChargeOutcome::UNKNOWN],
            'refunded' => ['refunded', 'canceled', ChargeOutcome::UNKNOWN],
            'waiting_cancellation' => ['waiting_cancellation', 'pending', ChargeOutcome::UNKNOWN],
            'error_on_voiding' => ['error_on_voiding', 'paid', ChargeOutcome::UNKNOWN],
        ];
    }

    public function test_pagarme_card_without_charges_is_unknown(): void
    {
        $this->assertSame(ChargeOutcome::UNKNOWN, PagarmeStatusMap::cardOutcome(['id' => 'or_1']));
    }

    /** @dataProvider mercadoPagoCard */
    public function test_mercado_pago_card_outcome(string $status, ?string $detail, ChargeOutcome $expected): void
    {
        $payment = ['id' => 55, 'status' => $status, 'status_detail' => $detail];

        $this->assertSame($expected, MercadoPagoStatusMap::cardOutcome($payment));
        $this->assertSame($expected, ChargeResult::fromMercadoPagoOrder($payment)->outcome);
    }

    public static function mercadoPagoCard(): array
    {
        return [
            'processed' => ['processed', 'accredited', ChargeOutcome::PAID],
            'failed' => ['failed', 'failed', ChargeOutcome::DECLINED],
            'failed expired' => ['failed', 'expired', ChargeOutcome::DECLINED],
            'canceled' => ['canceled', 'canceled', ChargeOutcome::DECLINED],
            'processing' => ['processing', 'in_process', ChargeOutcome::PENDING_REVIEW],
            'created' => ['created', 'created', ChargeOutcome::PENDING_REVIEW],
            'waiting capture' => ['action_required', 'waiting_capture', ChargeOutcome::PENDING_REVIEW],
            'waiting retry' => ['action_required', 'waiting_retry', ChargeOutcome::PENDING_REVIEW],
            'refunded' => ['refunded', 'refunded', ChargeOutcome::UNKNOWN],
            'charged_back' => ['charged_back', 'settled', ChargeOutcome::UNKNOWN],
            'new status' => ['brand_new', null, ChargeOutcome::UNKNOWN],
        ];
    }

    /**
     * Contrato: o mesmo cenário nos dois gateways precisa dar o mesmo resultado interno.
     *
     * @dataProvider pixScenarios
     */
    public function test_pix_contract_same_scenario_same_internal_status(string $scenario, array $pagarme, array $mercadoPago, ?StatusPaymentEnum $expected): void
    {
        $this->assertSame($expected, PagarmeStatusMap::pix($pagarme)->status, "pagarme: {$scenario}");
        $this->assertSame($expected, MercadoPagoStatusMap::pix($mercadoPago)->status, "mercadopago: {$scenario}");
    }

    public static function pixScenarios(): array
    {
        return [
            'paid' => ['paid', ['status' => 'paid'], ['status' => 'processed', 'status_detail' => 'accredited'], StatusPaymentEnum::PAID],
            'pending' => ['pending', ['status' => 'pending'], ['status' => 'action_required', 'status_detail' => 'waiting_transfer'], StatusPaymentEnum::PENDING],
            'expired' => ['expired', ['status' => 'canceled'], ['status' => 'canceled', 'status_detail' => 'expired'], StatusPaymentEnum::CANCELED],
            'failed' => ['failed', ['status' => 'failed'], ['status' => 'failed', 'status_detail' => 'failed'], StatusPaymentEnum::FAILED],
            'refunded has no equivalent' => ['refunded', ['status' => 'refunded'], ['status' => 'refunded'], null],
        ];
    }

    /** @dataProvider cardScenarios */
    public function test_card_contract_same_scenario_same_outcome(string $scenario, array $pagarme, array $mercadoPago, ChargeOutcome $expected): void
    {
        $this->assertSame($expected, PagarmeStatusMap::cardOutcome($pagarme), "pagarme: {$scenario}");
        $this->assertSame($expected, MercadoPagoStatusMap::cardOutcome($mercadoPago), "mercadopago: {$scenario}");
    }

    public static function cardScenarios(): array
    {
        $order = fn (string $tx, string $charge = 'paid') => ['charges' => [['status' => $charge, 'last_transaction' => ['status' => $tx]]]];

        return [
            'approved' => ['approved', $order('captured'), ['status' => 'processed'], ChargeOutcome::PAID],
            'declined' => ['declined', $order('not_authorized', 'failed'), ['status' => 'failed', 'status_detail' => 'failed'], ChargeOutcome::DECLINED],
            'under review' => ['review', $order('waiting_capture', 'pending'), ['status' => 'processing'], ChargeOutcome::PENDING_REVIEW],
            'unknown' => ['unknown', $order('voided', 'canceled'), ['status' => 'charged_back'], ChargeOutcome::UNKNOWN],
        ];
    }

    public function test_mercado_pago_card_result_carries_card_data_and_raw_status(): void
    {
        $r = ChargeResult::fromMercadoPagoOrder([
            'id' => 'ORD1', 'status' => 'processed', 'status_detail' => 'accredited',
            'transactions' => ['payments' => [[
                'status' => 'processed', 'status_detail' => 'accredited',
                'payment_method' => ['id' => 'master', 'type' => 'credit_card', 'installments' => 1],
            ]]],
        ]);

        $this->assertSame('ORD1', $r->orderId);
        $this->assertSame('master', $r->brand);
        $this->assertSame(1, $r->installments);
        $this->assertSame('processed', $r->rawStatus);
        $this->assertSame('accredited', $r->rawDetail);
    }

    public function test_mercado_pago_rejection_detail_comes_from_the_transaction(): void
    {
        $n = MercadoPagoStatusMap::cardNormalized([
            'status' => 'failed', 'status_detail' => 'failed',
            'transactions' => ['payments' => [['status' => 'failed', 'status_detail' => 'rejected_by_issuer']]],
        ]);

        $this->assertSame('rejected_by_issuer', $n->rawDetail);
    }
}
