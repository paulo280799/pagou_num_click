<?php

namespace Tests\Unit;

use App\Support\ChargeOutcome;
use App\Support\ChargeResult;
use PHPUnit\Framework\TestCase;

class ChargeResultTest extends TestCase
{
    private function order(?string $txStatus, string $chargeStatus = 'paid', bool $withTx = true): array
    {
        $charge = ['status' => $chargeStatus];
        if ($withTx) {
            $charge['last_transaction'] = [
                'status' => $txStatus,
                'acquirer_tid' => 'tid1',
                'acquirer_nsu' => 'nsu1',
                'acquirer_auth_code' => 'auth1',
                'acquirer_return_code' => '0000',
                'installments' => 3,
                'card' => ['brand' => 'Visa', 'last_four_digits' => '1111'],
            ];
        }

        return ['id' => 'or_1', 'charges' => [$charge]];
    }

    /** @dataProvider statuses */
    public function test_maps_transaction_status(?string $status, ChargeOutcome $expected): void
    {
        $this->assertSame($expected, ChargeResult::fromOrderResponse($this->order($status))->outcome);
    }

    public static function statuses(): array
    {
        return [
            'captured' => ['captured', ChargeOutcome::PAID],
            'not_authorized' => ['not_authorized', ChargeOutcome::DECLINED],
            'failed' => ['failed', ChargeOutcome::DECLINED],
            'with_error' => ['with_error', ChargeOutcome::DECLINED],
            'authorized_pending_capture' => ['authorized_pending_capture', ChargeOutcome::PENDING_REVIEW],
            'waiting_capture' => ['waiting_capture', ChargeOutcome::PENDING_REVIEW],
            'partial_capture' => ['partial_capture', ChargeOutcome::PENDING_REVIEW],
            'voided' => ['voided', ChargeOutcome::UNKNOWN],
            'refunded' => ['refunded', ChargeOutcome::UNKNOWN],
            'unknown status' => ['xyz', ChargeOutcome::UNKNOWN],
            'null status' => [null, ChargeOutcome::UNKNOWN],
        ];
    }

    public function test_extracts_card_data(): void
    {
        $r = ChargeResult::fromOrderResponse($this->order('captured'));

        $this->assertSame('or_1', $r->orderId);
        $this->assertSame('Visa', $r->brand);
        $this->assertSame('1111', $r->lastFour);
        $this->assertSame(3, $r->installments);
        $this->assertSame('tid1', $r->tid);
        $this->assertSame('nsu1', $r->nsu);
        $this->assertSame('auth1', $r->authCode);
    }

    public function test_failed_charge_without_last_transaction_is_declined(): void
    {
        $this->assertSame(ChargeOutcome::DECLINED, ChargeResult::fromOrderResponse($this->order(null, 'failed', false))->outcome);
    }

    public function test_response_without_charges_is_unknown(): void
    {
        $this->assertSame(ChargeOutcome::UNKNOWN, ChargeResult::fromOrderResponse(['id' => 'or_1'])->outcome);
        $this->assertSame(ChargeOutcome::UNKNOWN, ChargeResult::fromOrderResponse([])->outcome);
    }
}
