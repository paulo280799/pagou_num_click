<?php

namespace Tests\Feature\Console;

use App\Enums\StatusPaymentEnum;
use App\Models\Account;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_pending_payment_is_canceled_without_calling_provider(): void
    {
        $account = Account::factory()->create();
        $payment = Payment::factory()->expired()->create(['account_id' => $account->id]);

        Http::fake();

        $this->artisan('app:process-payment');

        Http::assertNothingSent();
        $this->assertSame(StatusPaymentEnum::CANCELED, $payment->refresh()->status);
    }

    public function test_pending_payment_is_marked_paid_when_provider_reports_paid(): void
    {
        $account = Account::factory()->create();
        $payment = Payment::factory()->create(['account_id' => $account->id, 'ide' => 'order_1']);

        Http::fake(['*/v5/orders/order_1' => Http::response(['status' => 'paid'], 200)]);

        $this->artisan('app:process-payment');

        $payment->refresh();
        $this->assertSame(StatusPaymentEnum::PAID, $payment->status);
        $this->assertNotNull($payment->paymentDate);
    }

    /** @dataProvider terminalStatuses */
    public function test_pending_payment_is_updated_for_terminal_statuses(string $providerStatus, StatusPaymentEnum $expected): void
    {
        $account = Account::factory()->create();
        $payment = Payment::factory()->create(['account_id' => $account->id, 'ide' => 'order_1']);

        Http::fake(['*/v5/orders/order_1' => Http::response(['status' => $providerStatus], 200)]);

        $this->artisan('app:process-payment');

        $this->assertSame($expected, $payment->refresh()->status);
    }

    public static function terminalStatuses(): array
    {
        return [
            'failed' => ['failed', StatusPaymentEnum::FAILED],
            'canceled' => ['canceled', StatusPaymentEnum::CANCELED],
        ];
    }

    public function test_pending_payment_is_left_untouched_when_provider_status_is_unknown(): void
    {
        $account = Account::factory()->create();
        $payment = Payment::factory()->create(['account_id' => $account->id, 'ide' => 'order_1']);

        Http::fake(['*/v5/orders/order_1' => Http::response(['status' => 'something_else'], 200)]);

        $this->artisan('app:process-payment');

        $this->assertSame(StatusPaymentEnum::PENDING, $payment->refresh()->status);
    }

    public function test_pending_payment_is_left_untouched_when_provider_response_has_no_status(): void
    {
        $account = Account::factory()->create();
        $payment = Payment::factory()->create(['account_id' => $account->id, 'ide' => 'order_1']);

        Http::fake(['*/v5/orders/order_1' => Http::response([], 200)]);

        $this->artisan('app:process-payment');

        $this->assertSame(StatusPaymentEnum::PENDING, $payment->refresh()->status);
    }

    public function test_pending_payment_still_pending_in_provider_is_untouched(): void
    {
        $account = Account::factory()->create();
        $payment = Payment::factory()->create(['account_id' => $account->id, 'ide' => 'order_1']);

        Http::fake(['*/v5/orders/order_1' => Http::response(['status' => 'pending'], 200)]);

        $this->artisan('app:process-payment');

        $this->assertSame(StatusPaymentEnum::PENDING, $payment->refresh()->status);
    }

    public function test_processes_pending_payments_across_all_accounts(): void
    {
        $accountA = Account::factory()->create();
        $accountB = Account::factory()->create();
        $paymentA = Payment::factory()->create(['account_id' => $accountA->id, 'ide' => 'order_a']);
        $paymentB = Payment::factory()->create(['account_id' => $accountB->id, 'ide' => 'order_b']);

        Http::fake([
            '*/v5/orders/order_a' => Http::response(['status' => 'paid'], 200),
            '*/v5/orders/order_b' => Http::response(['status' => 'failed'], 200),
        ]);

        $this->artisan('app:process-payment');

        $this->assertSame(StatusPaymentEnum::PAID, $paymentA->refresh()->status);
        $this->assertSame(StatusPaymentEnum::FAILED, $paymentB->refresh()->status);
    }

    public function test_non_pending_payments_are_ignored(): void
    {
        $account = Account::factory()->create();
        $payment = Payment::factory()->paid()->create(['account_id' => $account->id, 'ide' => 'order_1']);

        Http::fake();

        $this->artisan('app:process-payment');

        Http::assertNothingSent();
        $this->assertSame(StatusPaymentEnum::PAID, $payment->refresh()->status);
    }
}
