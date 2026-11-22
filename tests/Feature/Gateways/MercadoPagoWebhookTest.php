<?php

namespace Tests\Feature\Gateways;

use App\Enums\GatewayEnum;
use App\Enums\StatusPaymentEnum;
use App\Models\Account;
use App\Models\Config;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesGateways;
use Tests\TestCase;

class MercadoPagoWebhookTest extends TestCase
{
    use FakesGateways, RefreshDatabase;

    private const SECRET = 'whsec-test';

    private const ORDER = 'ORDTST00000000000000000AAA';

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMercadoPago();
        config(['gateways.mercadopago.webhook_secret' => self::SECRET]);
        $account = Account::factory()->create();
        Config::factory()->create(['account_id' => $account->id]);
        $this->payment = Payment::factory()->pending()->create([
            'account_id' => $account->id, 'ide' => self::ORDER, 'pix_gateway' => GatewayEnum::MERCADOPAGO, 'amount' => 100,
        ]);
    }

    private Payment $payment;

    private function notify(array $headers = [], string $id = self::ORDER, ?string $signId = null)
    {
        $ts = '1700000000';
        $requestId = 'req-1';
        $manifest = 'id:'.strtolower($signId ?? $id).";request-id:{$requestId};ts:{$ts};";
        $headers += [
            'x-request-id' => $requestId,
            'x-signature' => "ts={$ts},v1=".hash_hmac('sha256', $manifest, self::SECRET),
        ];

        return $this->postJson('/api/webhooks/mercadopago', ['type' => 'order', 'data' => ['id' => $id]], $headers);
    }

    public function test_valid_notification_reevaluates_the_payment_at_the_provider(): void
    {
        Http::fake(['*mercadopago.test/v1/orders/'.self::ORDER => Http::response($this->mpPix(['status' => 'processed', 'status_detail' => 'accredited']), 200)]);

        $this->notify()->assertOk();

        $this->assertSame(StatusPaymentEnum::PAID, $this->payment->refresh()->status);
    }

    public function test_the_signature_is_computed_over_the_lowercase_id(): void
    {
        Http::fake(['*mercadopago.test/v1/orders/*' => Http::response($this->mpPix(['status' => 'processed', 'status_detail' => 'accredited']), 200)]);

        $this->notify(signId: strtolower(self::ORDER))->assertOk();

        $this->assertSame(StatusPaymentEnum::PAID, $this->payment->refresh()->status);
    }

    public function test_tampered_signature_is_rejected_and_nothing_is_fetched(): void
    {
        Http::fake();

        $this->notify(['x-signature' => 'ts=1700000000,v1=deadbeef'])->assertStatus(401);

        Http::assertNothingSent();
        $this->assertSame(StatusPaymentEnum::PENDING, $this->payment->refresh()->status);
    }

    public function test_missing_signature_header_is_rejected(): void
    {
        $this->postJson('/api/webhooks/mercadopago', ['data' => ['id' => self::ORDER]])->assertStatus(401);
    }

    public function test_without_configured_secret_everything_is_rejected(): void
    {
        config(['gateways.mercadopago.webhook_secret' => null]);

        $this->notify()->assertStatus(401);
    }

    public function test_signature_of_another_order_id_is_rejected(): void
    {
        $this->notify(signId: 'ORDTSTOTHER')->assertStatus(401);
    }

    public function test_unknown_order_answers_200_so_the_provider_stops_retrying(): void
    {
        Http::fake();

        $this->notify(id: 'ORDTST_UNKNOWN')->assertOk();

        Http::assertNothingSent();
    }

    public function test_provider_outage_while_handling_the_notification_still_answers_200(): void
    {
        Http::fake(['*mercadopago.test/*' => Http::response('boom', 502)]);

        $this->notify()->assertOk();

        $this->assertSame(StatusPaymentEnum::PENDING, $this->payment->refresh()->status);
    }
}
