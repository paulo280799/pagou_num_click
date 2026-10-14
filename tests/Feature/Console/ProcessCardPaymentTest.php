<?php

namespace Tests\Feature\Console;

use App\Enums\PaymentMethodEnum;
use App\Enums\StatusPaymentEnum;
use App\Models\Account;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ProcessCardPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function cardOrder(string $txStatus, string $id = 'or_card'): array
    {
        return ['id' => $id, 'charges' => [['status' => 'paid', 'last_transaction' => [
            'status' => $txStatus, 'installments' => 1, 'card' => ['brand' => 'Visa', 'last_four_digits' => '1111'],
        ]]]];
    }

    private function pending(string $state, array $attrs = []): Payment
    {
        return Payment::factory()->{$state}()->create(array_merge(['account_id' => Account::factory()->create()->id], $attrs));
    }

    public function test_card_under_review_becomes_paid(): void
    {
        $payment = $this->pending('cardAttemptPending', ['card_order_id' => 'or_card']);
        Http::fake(['*/v5/orders/or_card' => Http::response($this->cardOrder('captured'), 200)]);

        $this->artisan('app:process-payment');

        $payment->refresh();
        $this->assertSame(StatusPaymentEnum::PAID, $payment->status);
        $this->assertSame(PaymentMethodEnum::CREDIT_CARD, $payment->payment_method);
        $this->assertNull($payment->card_attempt_key);
    }

    public function test_card_under_review_declined_releases_attempt(): void
    {
        $payment = $this->pending('cardAttemptPending', ['card_order_id' => 'or_card']);
        Http::fake([
            '*/v5/orders/or_card' => Http::response($this->cardOrder('not_authorized'), 200),
            '*/v5/orders/'.$payment->ide => Http::response(['status' => 'pending'], 200),
        ]);

        $this->artisan('app:process-payment');

        $payment->refresh();
        $this->assertSame(StatusPaymentEnum::PENDING, $payment->status);
        $this->assertNull($payment->card_attempt_key);
    }

    public function test_card_under_review_with_expired_payment_is_not_canceled(): void
    {
        $payment = $this->pending('cardAttemptPending', ['card_order_id' => 'or_card', 'expirationDate' => now()->subMinute()]);
        Http::fake(['*/v5/orders/or_card' => Http::response($this->cardOrder('waiting_capture'), 200)]);

        $this->artisan('app:process-payment');

        $this->assertSame(StatusPaymentEnum::PENDING, $payment->refresh()->status);
    }

    public function test_timed_out_attempt_is_reconciled_by_code(): void
    {
        $payment = $this->pending('cardTimedOut');
        $order = $this->cardOrder('captured', 'or_found') + ['metadata' => ['payment_id' => $payment->id]];
        Http::fake(['*/v5/orders?code=*' => Http::response(['data' => [
            ['id' => 'or_other', 'metadata' => ['payment_id' => 'x']],
            $order,
        ]], 200)]);

        $this->artisan('app:process-payment');

        $payment->refresh();
        $this->assertSame(StatusPaymentEnum::PAID, $payment->status);
        $this->assertSame('or_found', $payment->card_order_id);
    }

    public function test_timed_out_attempt_without_order_releases_key(): void
    {
        $payment = $this->pending('cardTimedOut');
        Http::fake([
            '*/v5/orders?code=*' => Http::response(['data' => []], 200),
            '*/v5/orders/'.$payment->ide => Http::response(['status' => 'pending'], 200),
        ]);

        $this->artisan('app:process-payment');

        $payment->refresh();
        $this->assertNull($payment->card_attempt_key);
        $this->assertSame(StatusPaymentEnum::PENDING, $payment->status);
    }

    public function test_pix_paid_after_card_logs_error_for_manual_refund(): void
    {
        $payment = $this->pending('cardPaid', ['ide' => 'order_pix']);
        Log::spy();
        Http::fake(['*/v5/orders/order_pix' => Http::response(['status' => 'paid'], 200)]);

        $this->artisan('app:process-payment');

        Log::shouldHaveReceived('error')->once();
        $this->assertSame(PaymentMethodEnum::CREDIT_CARD, $payment->refresh()->payment_method);
    }

    public function test_card_only_payment_is_not_polled_at_pix_and_cancels_on_expiry(): void
    {
        $live = $this->pending('cardOnly');
        $expired = $this->pending('cardOnly', ['expirationDate' => now()->subMinute()]);
        Http::fake();

        $this->artisan('app:process-payment');

        Http::assertNothingSent();
        $this->assertSame(StatusPaymentEnum::PENDING, $live->refresh()->status);
        $this->assertSame(StatusPaymentEnum::CANCELED, $expired->refresh()->status);
    }

    public function test_card_only_paid_payment_is_not_watched_for_open_pix(): void
    {
        Payment::factory()->cardOnly()->paid()->create([
            'account_id' => Account::factory()->create()->id,
            'expirationDate' => now()->addMinutes(5),
        ]);
        Http::fake();

        $this->artisan('app:process-payment');

        Http::assertNothingSent();
    }
}
