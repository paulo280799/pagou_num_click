<?php

namespace Tests\Feature\Models;

use App\Enums\PaymentMethodEnum;
use App\Enums\StatusPaymentEnum;
use App\Models\Account;
use App\Models\Config;
use App\Models\Payment;
use App\Support\ChargeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentFeeTest extends TestCase
{
    use RefreshDatabase;

    private function account(array $config = []): Account
    {
        $account = Account::factory()->create();
        Config::factory()->create(['account_id' => $account->id] + $config);

        return $account;
    }

    private array $cardFees = ['card_fee_percent_1x' => 3.5, 'card_fee_percent_2_6x' => 4.5, 'card_fee_percent_7_12x' => 5.5];

    public function test_config_fee_percent_by_method_and_installment_tier(): void
    {
        $config = new Config(['pix_fee_percent' => 0.99] + $this->cardFees);

        $this->assertSame(0.99, $config->feePercentFor(PaymentMethodEnum::PIX));
        $this->assertSame(3.5, $config->feePercentFor(PaymentMethodEnum::CREDIT_CARD, 1));
        $this->assertSame(3.5, $config->feePercentFor(PaymentMethodEnum::CREDIT_CARD, null));
        $this->assertSame(4.5, $config->feePercentFor(PaymentMethodEnum::CREDIT_CARD, 2));
        $this->assertSame(4.5, $config->feePercentFor(PaymentMethodEnum::CREDIT_CARD, 6));
        $this->assertSame(5.5, $config->feePercentFor(PaymentMethodEnum::CREDIT_CARD, 7));
        $this->assertSame(5.5, $config->feePercentFor(PaymentMethodEnum::CREDIT_CARD, 12));
    }

    public function test_card_fee_is_null_when_not_configured(): void
    {
        $config = new Config(['pix_fee_percent' => 0.75]);

        $this->assertNull($config->feePercentFor(PaymentMethodEnum::CREDIT_CARD, 3));
    }

    public function test_mark_card_paid_snapshots_fee_for_installment_tier(): void
    {
        $account = $this->account($this->cardFees);
        $payment = Payment::factory()->withCustomer()->create(['account_id' => $account->id, 'amount' => 200]);

        $payment->markCardPaid(new ChargeResult(\App\Support\ChargeOutcome::PAID, installments: 3));

        $payment->refresh();
        $this->assertSame(4.5, $payment->fee_percent);
        $this->assertSame(9.0, $payment->fee_amount);
    }

    public function test_mark_card_paid_without_configured_card_fee_stores_null(): void
    {
        $account = $this->account();
        $payment = Payment::factory()->withCustomer()->create(['account_id' => $account->id, 'amount' => 200]);

        $payment->markCardPaid(new ChargeResult(\App\Support\ChargeOutcome::PAID, installments: 1));

        $this->assertNull($payment->refresh()->fee_percent);
        $this->assertNull($payment->fee_amount);
    }

    public function test_polling_pix_paid_snapshots_pix_fee_from_config(): void
    {
        $account = $this->account(['pix_fee_percent' => 1.5]);
        $payment = Payment::factory()->create(['account_id' => $account->id, 'amount' => 100, 'ide' => 'or_pix']);
        Http::fake(['*/v5/orders/or_pix' => Http::response(['status' => 'paid'], 200)]);

        $this->artisan('app:process-payment');

        $payment->refresh();
        $this->assertSame(StatusPaymentEnum::PAID, $payment->status);
        $this->assertSame(1.5, $payment->fee_percent);
        $this->assertSame(1.5, $payment->fee_amount);
    }

    public function test_fee_snapshot_is_not_changed_by_later_config_edit(): void
    {
        $account = $this->account(['pix_fee_percent' => 1.5]);
        $payment = Payment::factory()->create(['account_id' => $account->id, 'amount' => 100, 'ide' => 'or_pix']);
        Http::fake(['*/v5/orders/or_pix' => Http::response(['status' => 'paid'], 200)]);
        $this->artisan('app:process-payment');

        Config::withoutGlobalScopes()->where('account_id', $account->id)->update(['pix_fee_percent' => 9]);

        $this->assertSame(1.5, $payment->refresh()->feeSummary()['percent']);
    }

    public function test_fee_summary_legacy_paid_pix_uses_historic_rate(): void
    {
        $account = $this->account(['pix_fee_percent' => 9]);
        $payment = Payment::factory()->paid()->create(['account_id' => $account->id, 'amount' => 100]);

        $this->assertSame(['percent' => 0.75, 'amount' => 0.75, 'estimated' => false], $payment->feeSummary());
    }

    public function test_fee_summary_pending_is_estimated_from_current_config(): void
    {
        $account = $this->account(['pix_fee_percent' => 2]);
        $payment = Payment::factory()->create(['account_id' => $account->id, 'amount' => 100]);

        $this->assertSame(['percent' => 2.0, 'amount' => 2.0, 'estimated' => true], $payment->feeSummary());
    }

    public function test_fee_summary_card_paid_without_snapshot_is_unconfigured(): void
    {
        $account = $this->account($this->cardFees);
        $payment = Payment::factory()->cardPaid()->create(['account_id' => $account->id, 'amount' => 100]);

        $this->assertSame(['percent' => null, 'amount' => null, 'estimated' => false], $payment->feeSummary());
    }
}
