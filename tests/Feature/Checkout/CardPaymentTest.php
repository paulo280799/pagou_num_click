<?php

namespace Tests\Feature\Checkout;

use App\Enums\PaymentMethodEnum;
use App\Enums\StatusPaymentEnum;
use App\Models\Account;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class CardPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'card_token' => 'token_abc123',
            'installments' => 1,
            'billing_address' => [
                'line_1' => 'Rua A, 10', 'zip_code' => '01001000', 'city' => 'São Paulo', 'state' => 'SP', 'country' => 'BR',
            ],
        ], $overrides);
    }

    private function payment(string $state = 'withCustomer', array $attrs = []): Payment
    {
        return Payment::factory()->{$state}()->create(array_merge(['account_id' => Account::factory()->create()->id], $attrs));
    }

    private function orderResponse(string $txStatus, string $chargeStatus = 'paid'): array
    {
        return [
            'id' => 'or_card1',
            'status' => $chargeStatus,
            'charges' => [[
                'status' => $chargeStatus,
                'last_transaction' => [
                    'status' => $txStatus,
                    'acquirer_tid' => 'tid', 'acquirer_nsu' => 'nsu', 'acquirer_auth_code' => 'auth',
                    'acquirer_return_code' => '0000', 'installments' => 1,
                    'card' => ['brand' => 'Visa', 'last_four_digits' => '1111'],
                ],
            ]],
        ];
    }

    private function pay(Payment $payment, array $overrides = [])
    {
        return $this->postJson("/checkout/{$payment->id}/pay-card", $this->payload($overrides));
    }

    public function test_approved_card_marks_payment_paid(): void
    {
        $payment = $this->payment();
        Http::fake(['*/v5/orders' => Http::response($this->orderResponse('captured'), 200)]);

        $this->pay($payment, ['installments' => 3])->assertOk()->assertExactJson(['status' => 'paid', 'message' => 'Pagamento aprovado.']);

        $payment->refresh();
        $this->assertSame(StatusPaymentEnum::PAID, $payment->status);
        $this->assertSame(PaymentMethodEnum::CREDIT_CARD, $payment->payment_method);
        $this->assertNotNull($payment->paymentDate);
        $this->assertSame('or_card1', $payment->card_order_id);
        $this->assertSame('Visa', $payment->card_brand);
        $this->assertSame('1111', $payment->card_last_four);
        $this->assertSame('tid', $payment->acquirer_tid);
        $this->assertNull($payment->card_attempt_key);
        $this->assertFalse((bool) $payment->is_notified);
        Http::assertSentCount(1);
    }

    public function test_declined_card_returns_422_and_keeps_pending(): void
    {
        $payment = $this->payment();
        Http::fake(['*/v5/orders' => Http::response($this->orderResponse('failed', 'failed'), 200)]);

        $this->pay($payment)->assertStatus(422)->assertJsonPath('status', 'declined');

        $payment->refresh();
        $this->assertSame(StatusPaymentEnum::PENDING, $payment->status);
        $this->assertNull($payment->card_attempt_key);
        $this->assertNull($payment->card_order_id);
    }

    public function test_new_attempt_after_decline_uses_a_different_idempotency_key(): void
    {
        $payment = $this->payment();
        Http::fake(['*/v5/orders' => Http::response($this->orderResponse('failed', 'failed'), 200)]);

        $this->pay($payment)->assertStatus(422);
        $this->pay($payment)->assertStatus(422);

        $keys = Http::recorded()->map(fn ($pair) => $pair[0]->header('Idempotency-Key')[0])->all();
        $this->assertCount(2, $keys);
        $this->assertNotSame($keys[0], $keys[1]);
    }

    public function test_pending_review_returns_202_and_blocks_second_call(): void
    {
        $payment = $this->payment();
        Http::fake(['*/v5/orders' => Http::response($this->orderResponse('authorized_pending_capture', 'pending'), 200)]);

        $this->pay($payment)->assertStatus(202);
        $this->assertNotNull($payment->refresh()->card_attempt_key);
        $this->assertSame('or_card1', $payment->card_order_id);

        $this->pay($payment)->assertStatus(409);
        Http::assertSentCount(1);
    }

    public function test_timeout_returns_503_and_retry_reuses_idempotency_key(): void
    {
        $payment = $this->payment();
        $calls = 0;
        Http::fake(['*/v5/orders' => function () use (&$calls) {
            if ($calls++ === 0) {
                throw new \Illuminate\Http\Client\ConnectionException('timeout');
            }

            return Http::response($this->orderResponse('captured'), 200);
        }]);

        $this->pay($payment)->assertStatus(503);
        $payment->refresh();
        $key = $payment->card_attempt_key;
        $this->assertNotNull($key);
        $this->assertNull($payment->card_order_id);

        // dentro da janela: outra requisição em voo
        $this->pay($payment)->assertStatus(409);

        // fora da janela: reenvia com a mesma chave
        $payment->update(['card_attempt_started_at' => now()->subMinutes(5)]);

        $this->pay($payment)->assertOk();

        Http::assertSent(fn (Request $r) => $r->header('Idempotency-Key')[0] === $key);
    }

    public function test_second_call_after_paid_is_409_with_single_provider_call(): void
    {
        $payment = $this->payment();
        Http::fake(['*/v5/orders' => Http::response($this->orderResponse('captured'), 200)]);

        $this->pay($payment)->assertOk();
        $this->pay($payment)->assertStatus(409);

        Http::assertSentCount(1);
    }

    /** @dataProvider unpayableStates */
    public function test_unpayable_payment_returns_409(array $attrs): void
    {
        if (($attrs['expirationDate'] ?? null) === 'past') {
            $attrs['expirationDate'] = now()->subMinute();
        }
        $payment = $this->payment('withCustomer', $attrs);
        Http::fake();

        $this->pay($payment)->assertStatus(409);
        Http::assertNothingSent();
    }

    public static function unpayableStates(): array
    {
        return [
            'paid' => [['status' => StatusPaymentEnum::PAID]],
            'failed' => [['status' => StatusPaymentEnum::FAILED]],
            'canceled' => [['status' => StatusPaymentEnum::CANCELED]],
            'expired' => [['expirationDate' => 'past']],
        ];
    }

    public function test_payment_without_customer_returns_409(): void
    {
        $payment = $this->payment('pending');
        Http::fake();

        $this->pay($payment)->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_unknown_payment_returns_404(): void
    {
        $this->postJson('/checkout/'.fake()->uuid().'/pay-card', $this->payload())->assertNotFound();
    }

    public function test_amount_comes_from_database_not_request(): void
    {
        $payment = $this->payment('withCustomer', ['amount' => 12.34]);
        Http::fake(['*/v5/orders' => Http::response($this->orderResponse('captured'), 200)]);

        $this->pay($payment, ['amount' => 1, 'items' => [['amount' => 1]]])->assertOk();

        Http::assertSent(function (Request $r) {
            return $r['payments'][0]['amount'] === 1234
                && $r['items'][0]['amount'] === 1234
                && $r['payments'][0]['credit_card']['operation_type'] === 'auth_and_capture'
                && $r['items'][0]['code'] === $r['metadata']['payment_id']
                && $r['customer']['document'] === '12345678909';
        });
    }

    /** @dataProvider invalidPayloads */
    public function test_validation(array $overrides, string $field): void
    {
        $payment = $this->payment();
        Http::fake();

        $this->pay($payment, $overrides)->assertStatus(422)->assertJsonValidationErrors($field);
        Http::assertNothingSent();
    }

    public static function invalidPayloads(): array
    {
        return [
            'token missing' => [['card_token' => null], 'card_token'],
            'token format' => [['card_token' => 'tok_123'], 'card_token'],
            'installments zero' => [['installments' => 0], 'installments'],
            'installments above max' => [['installments' => 13], 'installments'],
            'address incomplete' => [['billing_address' => ['line_1' => 'x']], 'billing_address.zip_code'],
        ];
    }

    public function test_rate_limit_blocks_sixth_attempt(): void
    {
        $payment = $this->payment();
        Http::fake(['*/v5/orders' => Http::response($this->orderResponse('failed', 'failed'), 200)]);

        for ($i = 0; $i < 5; $i++) {
            $this->pay($payment)->assertStatus(422);
        }

        $this->pay($payment)->assertStatus(429);
    }

    public function test_logs_never_contain_card_token_or_billing_address(): void
    {
        $payment = $this->payment();
        Log::spy();
        Http::fake(['*/v5/orders' => Http::response($this->orderResponse('failed', 'failed'), 200)]);

        $this->pay($payment)->assertStatus(422);

        Log::shouldHaveReceived('warning')->withArgs(
            fn ($msg, $ctx = []) => ! str_contains($msg.json_encode($ctx), 'token_abc123') && ! str_contains($msg.json_encode($ctx), 'Rua A')
        );
    }

    public function test_customer_is_stored_encrypted(): void
    {
        $payment = $this->payment();

        $raw = \DB::table('payments')->where('id', $payment->id)->value('customer');
        $this->assertStringNotContainsString('fulano@example.com', $raw);
        $this->assertSame('fulano@example.com', $payment->refresh()->customer['email']);
        $this->assertArrayNotHasKey('customer', $payment->toArray());
    }
}
