<?php

namespace Tests\Feature\Console;

use App\Enums\StatusPaymentEnum;
use App\Models\Account;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifies_client_and_marks_payment_as_notified_on_success(): void
    {
        $account = Account::factory()->create();
        $payment = Payment::factory()->paid()->create([
            'account_id' => $account->id,
            'notification_url' => 'https://client.test/webhook',
            'is_notified' => false,
        ]);

        Http::fake(['client.test/*' => Http::response(['ok' => true], 200)]);

        $this->artisan('app:process-webhook');

        Http::assertSent(function ($request) use ($payment) {
            return $request->url() === 'https://client.test/webhook'
                && $request['payment_id'] === $payment->refExternal
                && $request->hasHeader('X-Webhook-Secret');
        });

        $this->assertEquals(1, $payment->refresh()->is_notified);
    }

    public function test_pending_payments_are_not_notified(): void
    {
        $account = Account::factory()->create();
        Payment::factory()->create([
            'account_id' => $account->id,
            'notification_url' => 'https://client.test/webhook',
        ]);

        Http::fake();

        $this->artisan('app:process-webhook');

        Http::assertNothingSent();
    }

    public function test_payment_without_notification_url_is_skipped(): void
    {
        $account = Account::factory()->create();
        $payment = Payment::factory()->paid()->create([
            'account_id' => $account->id,
            'notification_url' => null,
        ]);

        Http::fake();

        $this->artisan('app:process-webhook');

        Http::assertNothingSent();
        $this->assertEquals(0, $payment->refresh()->is_notified);
    }

    public function test_failed_notification_increments_attempts_and_sets_last_attempt(): void
    {
        $account = Account::factory()->create();
        $payment = Payment::factory()->paid()->create([
            'account_id' => $account->id,
            'notification_url' => 'https://client.test/webhook',
            'notification_attempts' => 0,
            'last_notification_attempt' => null,
        ]);

        Http::fake(['client.test/*' => Http::response(['error' => 'boom'], 500)]);

        $this->artisan('app:process-webhook');

        $payment->refresh();
        $this->assertEquals(0, $payment->is_notified);
        $this->assertSame(1, $payment->notification_attempts);
        $this->assertNotNull($payment->last_notification_attempt);
    }

    public function test_retry_is_skipped_before_backoff_interval_elapses(): void
    {
        $account = Account::factory()->create();
        $payment = Payment::factory()->paid()->create([
            'account_id' => $account->id,
            'notification_url' => 'https://client.test/webhook',
            'notification_attempts' => 1,
            'last_notification_attempt' => now(),
        ]);

        Http::fake(['client.test/*' => Http::response([], 200)]);

        $this->artisan('app:process-webhook');

        Http::assertNothingSent();
        $this->assertEquals(0, $payment->refresh()->is_notified);
        $this->assertSame(1, $payment->refresh()->notification_attempts);
    }

    public function test_retry_is_attempted_after_backoff_interval_elapses(): void
    {
        $account = Account::factory()->create();
        $payment = Payment::factory()->paid()->create([
            'account_id' => $account->id,
            'notification_url' => 'https://client.test/webhook',
            'notification_attempts' => 1,
            'last_notification_attempt' => now()->subSeconds(301), // backoff[1] = 300s
        ]);

        Http::fake(['client.test/*' => Http::response([], 200)]);

        $this->artisan('app:process-webhook');

        Http::assertSentCount(1);
        $this->assertEquals(1, $payment->refresh()->is_notified);
    }

    public function test_notification_is_abandoned_after_max_attempts(): void
    {
        $account = Account::factory()->create();
        $payment = Payment::factory()->paid()->create([
            'account_id' => $account->id,
            'notification_url' => 'https://client.test/webhook',
            'notification_attempts' => 5,
            'last_notification_attempt' => now()->subDay(),
        ]);

        Http::fake();

        $this->artisan('app:process-webhook');

        Http::assertNothingSent();
        $this->assertSame(5, $payment->refresh()->notification_attempts);
    }

    public function test_webhook_secret_header_matches_config(): void
    {
        config(['services.webhook_secret' => 'test-secret-123']);

        $account = Account::factory()->create();
        Payment::factory()->paid()->create([
            'account_id' => $account->id,
            'notification_url' => 'https://client.test/webhook',
        ]);

        Http::fake(['client.test/*' => Http::response([], 200)]);

        $this->artisan('app:process-webhook');

        Http::assertSent(fn ($request) => $request->header('X-Webhook-Secret')[0] === 'test-secret-123');
    }
}
