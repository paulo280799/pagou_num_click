<?php

namespace Tests\Feature\Gateways;

use App\Enums\GatewayEnum;
use App\Enums\PaymentMethodEnum;
use App\Enums\StatusPaymentEnum;
use App\Models\Account;
use App\Models\Config;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\FakesGateways;
use Tests\TestCase;

class PollingTest extends TestCase
{
    use FakesGateways, RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMercadoPago();
        $this->account = Account::factory()->create();
        Config::factory()->create([
            'account_id' => $this->account->id,
            'pix_fee_percent' => 0.75,
            'card_fee_percent_1x' => 3.5,
            'mp_pix_fee_percent' => 1.5,
            'mp_card_fee_percent_1x' => 4.99,
        ]);
    }

    private function payment(string $state = 'pending', array $attrs = []): Payment
    {
        return Payment::factory()->{$state}()->create(['account_id' => $this->account->id] + $attrs);
    }

    private function pagarmeCalls(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/v5/orders'))->all();
    }

    public function test_mercado_pago_pix_is_polled_at_mercado_pago_and_uses_its_fee_table(): void
    {
        $payment = $this->payment('pending', ['ide' => '123', 'pix_gateway' => GatewayEnum::MERCADOPAGO, 'amount' => 100]);
        Http::fake(['*mercadopago.test/v1/orders/123' => Http::response(['id' => 123, 'status' => 'processed', 'status_detail' => 'accredited'], 200)]);

        $this->artisan('app:process-payment');

        $payment->refresh();
        $this->assertSame(StatusPaymentEnum::PAID, $payment->status);
        $this->assertSame(1.5, $payment->fee_percent);
        $this->assertSame(1.5, $payment->fee_amount);
        $this->assertSame('processed', $payment->provider_status);
        $this->assertSame('accredited', $payment->provider_status_detail);
        $this->assertSame([], $this->pagarmeCalls());
    }

    public function test_pagarme_pix_keeps_pagarme_fee_table(): void
    {
        $payment = $this->payment('pending', ['ide' => 'order_1', 'pix_gateway' => GatewayEnum::PAGARME, 'amount' => 100]);
        Http::fake(['*/v5/orders/order_1' => Http::response(['status' => 'paid'], 200)]);

        $this->artisan('app:process-payment');

        $this->assertSame(0.75, $payment->refresh()->fee_percent);
    }

    public function test_legacy_payment_without_gateway_columns_is_polled_at_pagarme(): void
    {
        $payment = $this->payment('pending', ['ide' => 'order_1', 'pix_gateway' => null]);
        Http::fake(['*/v5/orders/order_1' => Http::response(['status' => 'paid'], 200)]);

        $this->artisan('app:process-payment');

        $this->assertSame(StatusPaymentEnum::PAID, $payment->refresh()->status);
    }

    public function test_card_attempt_routes_by_card_gateway_even_when_pix_belongs_to_another_gateway(): void
    {
        // payment_method continua PIX enquanto o cartão está em análise: o accessor `gateway` apontaria o PIX (Pagar.me)
        $payment = $this->payment('cardAttemptPending', [
            'ide' => 'order_pix',
            'pix_gateway' => GatewayEnum::PAGARME,
            'card_gateway' => GatewayEnum::MERCADOPAGO,
            'card_order_id' => '987',
            'amount' => 100,
        ]);
        $this->assertSame(PaymentMethodEnum::PIX, $payment->payment_method);
        $this->assertSame(GatewayEnum::PAGARME, $payment->gateway);

        Http::fake([
            '*mercadopago.test/v1/orders/987' => Http::response($this->mpCard('processed', 'accredited', ['id' => 987]), 200),
            // depois de paga no cartão, o PIX aberto do Pagar.me passa a ser vigiado
            '*/v5/orders/order_pix' => Http::response(['status' => 'pending'], 200),
        ]);

        $this->artisan('app:process-payment');

        $payment->refresh();
        $this->assertSame(StatusPaymentEnum::PAID, $payment->status);
        $this->assertSame(PaymentMethodEnum::CREDIT_CARD, $payment->payment_method);
        $this->assertSame('987', $payment->card_order_id);
        $this->assertSame(4.99, $payment->fee_percent);
        $this->assertSame(GatewayEnum::MERCADOPAGO, $payment->gateway);
        // o cartão não passou pelo Pagar.me: a única chamada a ele é a vigilância do PIX aberto
        $this->assertCount(1, $this->pagarmeCalls());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v5/orders/order_pix'));
    }

    public function test_mercado_pago_declined_card_releases_attempt_and_never_fails_the_payment(): void
    {
        $payment = $this->payment('cardAttemptPending', ['card_gateway' => GatewayEnum::MERCADOPAGO, 'card_order_id' => '987', 'ide' => 'order_pix']);
        Http::fake([
            '*mercadopago.test/v1/orders/987' => Http::response($this->mpCard('failed', 'cc_rejected_insufficient_amount'), 200),
            '*/v5/orders/order_pix' => Http::response(['status' => 'pending'], 200),
        ]);

        $this->artisan('app:process-payment');

        $payment->refresh();
        $this->assertSame(StatusPaymentEnum::PENDING, $payment->status);
        $this->assertNull($payment->card_attempt_key);
    }

    public function test_timed_out_mercado_pago_attempt_is_never_released_because_orders_api_cannot_search_by_reference(): void
    {
        $payment = $this->payment('cardTimedOut', ['card_gateway' => GatewayEnum::MERCADOPAGO]);
        Http::fake();

        $this->artisan('app:process-payment')->assertSuccessful();

        $this->assertNotNull($payment->refresh()->card_attempt_key);
    }

    public function test_pix_paid_during_pagarme_outage_is_not_canceled_by_expiration(): void
    {
        // venceu há 5 min, mas o Pagar.me está fora: dentro da janela de graça (15 min) o cancelamento é adiado
        $payment = $this->payment('expired', ['ide' => 'order_1']);
        Http::fake(['*/v5/orders/order_1' => Http::response('down', 503)]);

        $this->artisan('app:process-payment')->assertSuccessful();

        $this->assertSame(StatusPaymentEnum::PENDING, $payment->refresh()->status);
    }

    public function test_pix_paid_at_the_last_second_is_recognized_instead_of_canceled(): void
    {
        $payment = $this->payment('expired', ['ide' => 'order_1']);
        Http::fake(['*/v5/orders/order_1' => Http::response(['status' => 'paid'], 200)]);

        $this->artisan('app:process-payment');

        $payment->refresh();
        $this->assertSame(StatusPaymentEnum::PAID, $payment->status);
        $this->assertNotNull($payment->paymentDate);
    }

    public function test_expired_pix_is_canceled_once_the_grace_window_is_over(): void
    {
        $payment = $this->payment('expired', ['ide' => 'order_1', 'expirationDate' => now()->subMinutes(20)]);
        Http::fake(['*/v5/orders/order_1' => $this->connectionFails()]);

        $this->artisan('app:process-payment')->assertSuccessful();

        $this->assertSame(StatusPaymentEnum::CANCELED, $payment->refresh()->status);
    }

    public function test_expired_mercado_pago_pix_paid_is_recognized(): void
    {
        $payment = $this->payment('expired', ['ide' => '123', 'pix_gateway' => GatewayEnum::MERCADOPAGO]);
        Http::fake(['*mercadopago.test/v1/orders/123' => Http::response(['status' => 'processed', 'status_detail' => 'accredited'], 200)]);

        $this->artisan('app:process-payment');

        $this->assertSame(StatusPaymentEnum::PAID, $payment->refresh()->status);
    }

    public function test_unknown_status_keeps_payment_pending_and_logs_only_when_it_changes(): void
    {
        $payment = $this->payment('pending', ['ide' => '123', 'pix_gateway' => GatewayEnum::MERCADOPAGO]);
        Log::spy();
        $raw = 'brand_new';
        Http::fake(['*mercadopago.test/v1/orders/123' => function () use (&$raw) {
            return Http::response(['status' => $raw, 'status_detail' => 'x'], 200);
        }]);

        $this->artisan('app:process-payment');
        $this->artisan('app:process-payment');
        $this->artisan('app:process-payment');

        $this->assertSame(StatusPaymentEnum::PENDING, $payment->refresh()->status);
        $this->assertSame('brand_new', $payment->provider_status);
        Log::shouldHaveReceived('warning')->once();

        // muda o status cru: loga de novo
        $raw = 'another_new';
        $this->artisan('app:process-payment');
        Log::shouldHaveReceived('warning')->twice();
    }

    public function test_refunded_logs_error_once_and_does_not_change_the_payment(): void
    {
        $payment = $this->payment('pending', ['ide' => 'order_1']);
        Log::spy();
        Http::fake(['*/v5/orders/order_1' => Http::response(['status' => 'refunded'], 200)]);

        $this->artisan('app:process-payment');
        $this->artisan('app:process-payment');

        $this->assertSame(StatusPaymentEnum::PENDING, $payment->refresh()->status);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_known_pending_status_does_not_log(): void
    {
        $this->payment('pending', ['ide' => '123', 'pix_gateway' => GatewayEnum::MERCADOPAGO]);
        $this->payment('pending', ['ide' => 'order_1']);
        Log::spy();
        Http::fake([
            '*mercadopago.test/v1/orders/123' => Http::response(['status' => 'action_required', 'status_detail' => 'waiting_transfer'], 200),
            '*/v5/orders/order_1' => Http::response(['status' => 'pending', 'charges' => [['last_transaction' => ['status' => 'waiting_payment']]]], 200),
        ]);

        $this->artisan('app:process-payment');

        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function test_paid_payment_is_not_reprocessed_so_date_and_fee_are_written_once(): void
    {
        $payment = $this->payment('pending', ['ide' => '123', 'pix_gateway' => GatewayEnum::MERCADOPAGO, 'amount' => 100]);
        Http::fake(['*mercadopago.test/v1/orders/123' => Http::response(['status' => 'processed'], 200)]);

        Carbon::setTestNow('2026-10-17 10:00:00');
        $this->artisan('app:process-payment');
        $paidAt = $payment->refresh()->paymentDate;

        // fee mudou depois: o snapshot não pode ser sobrescrito
        Config::withoutGlobalScopes()->update(['mp_pix_fee_percent' => 9.9]);
        Carbon::setTestNow('2026-10-17 10:05:00');
        $this->artisan('app:process-payment');

        $payment->refresh();
        $this->assertEquals($paidAt, $payment->paymentDate);
        $this->assertSame(1.5, $payment->fee_percent);
    }

    public function test_open_pix_of_card_paid_payment_is_watched_at_its_own_gateway(): void
    {
        $this->payment('cardPaid', ['ide' => '123', 'pix_gateway' => GatewayEnum::MERCADOPAGO, 'card_gateway' => GatewayEnum::PAGARME]);
        Log::spy();
        Http::fake(['*mercadopago.test/v1/orders/123' => Http::response(['status' => 'processed'], 200)]);

        $this->artisan('app:process-payment');

        Log::shouldHaveReceived('error')->once();
        $this->assertSame([], $this->pagarmeCalls());
    }

    public function test_mercado_pago_outage_during_polling_does_not_block_other_payments(): void
    {
        $down = $this->payment('pending', ['ide' => '123', 'pix_gateway' => GatewayEnum::MERCADOPAGO]);
        $up = $this->payment('pending', ['ide' => 'order_up']);
        Http::fake([
            '*mercadopago.test/v1/orders/123' => Http::response('boom', 502),
            '*/v5/orders/order_up' => Http::response(['status' => 'paid'], 200),
        ]);

        $this->artisan('app:process-payment')->assertSuccessful();

        $this->assertSame(StatusPaymentEnum::PENDING, $down->refresh()->status);
        $this->assertSame(StatusPaymentEnum::PAID, $up->refresh()->status);
    }
}
