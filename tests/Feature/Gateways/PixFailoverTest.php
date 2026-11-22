<?php

namespace Tests\Feature\Gateways;

use App\Enums\GatewayEnum;
use App\Enums\StatusPaymentEnum;
use App\Models\Payment;
use App\Services\Gateways\GatewayManager;
use App\Services\Gateways\CircuitBreaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesGateways;
use Tests\TestCase;

class PixFailoverTest extends TestCase
{
    use FakesGateways, RefreshDatabase;

    private function sentToMercadoPago(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'mercadopago.test'))->all();
    }

    private function sentToPagarme(): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/v5/orders'))->all();
    }

    public function test_pagarme_pix_sends_payment_id_as_code_and_metadata(): void
    {
        $this->fakeAccountWithToken();
        Http::fake(['*/v5/orders' => Http::response($this->pagarmePix(), 200)]);

        $this->createPayment(['refExternal' => 'ref-1'])->assertStatus(201)->assertJson(['gateway' => 'pagarme']);

        $payment = $this->onlyPayment();
        Http::assertSent(fn (Request $r) => $r['code'] === $payment->id
            && $r['metadata'] === ['payment_id' => $payment->id, 'refExternal' => 'ref-1']);
        $this->assertSame(GatewayEnum::PAGARME, $payment->pix_gateway);
        $this->assertNull($payment->card_gateway);
    }

    public static function pagarmeInfraFailures(): array
    {
        return [
            'http 500' => [fn () => Http::response(['message' => 'boom'], 500)],
            'http 503' => [fn () => Http::response('down', 503)],
            'http 429' => [fn () => Http::response(['message' => 'rate'], 429)],
            'connection error' => [fn () => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')],
            'http 200 with failed charge and no qr' => [fn () => Http::response([
                'id' => 'order_failed', 'status' => 'failed',
                'charges' => [['status' => 'failed', 'payment_method' => 'pix', 'last_transaction' => []]],
            ], 200)],
        ];
    }

    /** @dataProvider pagarmeInfraFailures */
    public function test_pagarme_infra_failure_creates_the_pix_on_mercado_pago(\Closure $pagarmeResponse): void
    {
        $this->enableMercadoPago();
        $this->fakeAccountWithToken();
        Http::fake([
            '*/v5/orders' => $pagarmeResponse(),
            '*mercadopago.test/v1/orders' => Http::response($this->mpPix(), 201),
        ]);

        $this->createPayment()->assertStatus(201)->assertJson(['gateway' => 'mercadopago']);

        $payment = $this->onlyPayment();
        $this->assertSame(GatewayEnum::MERCADOPAGO, $payment->pix_gateway);
        $this->assertSame('ORD00000000000000000000123', $payment->ide);
        $this->assertSame('data:image/png;base64,iVBORw0KGgo=', $payment->qrCode);
        $this->assertSame('00020126MERCADOPAGO', $payment->copyPaste);
        $this->assertSame(10.5, $payment->amount);
        $this->assertSame(StatusPaymentEnum::PENDING, $payment->status);
        $this->assertSame('action_required', $payment->provider_status);
        $this->assertSame('waiting_transfer', $payment->provider_status_detail);
    }

    public function test_mercado_pago_request_uses_idempotency_key_reais_and_payer_split_name(): void
    {
        $this->enableMercadoPago();
        $this->fakeAccountWithToken();
        $this->pagarmeDownMercadoPagoUp();

        $this->createPayment(['refExternal' => 'ref-9'])->assertStatus(201);

        $payment = $this->onlyPayment();
        [$sent] = $this->sentToMercadoPago();
        $request = $sent[0];

        $this->assertSame($payment->id, $request->header('X-Idempotency-Key')[0]);
        $this->assertSame('Bearer TEST-mp-token', $request->header('Authorization')[0]);
        $this->assertSame('10.50', $request['total_amount']);
        $this->assertSame('pix', $request['transactions']['payments'][0]['payment_method']['id']);
        $this->assertSame('bank_transfer', $request['transactions']['payments'][0]['payment_method']['type']);
        $this->assertSame($payment->id, $request['external_reference']);
        $this->assertSame('Fulano', $request['payer']['first_name']);
        $this->assertSame('de Tal', $request['payer']['last_name']);
        $this->assertSame(['type' => 'CPF', 'number' => '12345678909'], $request['payer']['identification']);
    }

    public function test_mercado_pago_pix_order_carries_the_items_and_they_are_stored(): void
    {
        $this->enableMercadoPago();
        $this->fakeAccountWithToken();
        $this->pagarmeDownMercadoPagoUp();

        $this->createPayment()->assertStatus(201);

        [$sent] = $this->sentToMercadoPago();
        $item = $sent[0]['items'][0];
        $this->assertArrayHasKey('title', $item);
        $this->assertSame('10.50', $item['unit_price']);
        $this->assertSame(1, $item['quantity']);
        $this->assertSame('others', $item['category_id']);
        $this->assertNotEmpty($this->onlyPayment()->items);
    }

    public function test_pagarme_validation_error_does_not_fall_back(): void
    {
        $this->enableMercadoPago();
        $this->fakeAccountWithToken();
        Http::fake([
            '*/v5/orders' => Http::response(['message' => 'invalid request'], 422),
            '*mercadopago.test/*' => Http::response($this->mpPix(), 201),
        ]);

        $this->createPayment()->assertStatus(422)->assertJson(['success' => false, 'gateway' => 'pagarme']);

        $this->assertSame([], $this->sentToMercadoPago());
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
    }

    public function test_both_gateways_down_returns_503_and_creates_no_payment(): void
    {
        $this->enableMercadoPago();
        $this->fakeAccountWithToken();
        Http::fake([
            '*/v5/orders' => Http::response(['message' => 'boom'], 500),
            '*mercadopago.test/*' => Http::response(['message' => 'boom'], 502),
        ]);

        $this->createPayment()
            ->assertStatus(503)
            ->assertJson(['success' => false, 'message' => 'Erro ao processar pagamento', 'gateway' => 'mercadopago'])
            ->assertJsonStructure(['errors']);

        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
    }

    public function test_mercado_pago_validation_error_after_pagarme_down_is_returned_without_payment(): void
    {
        $this->enableMercadoPago();
        $this->fakeAccountWithToken();
        Http::fake([
            '*/v5/orders' => $this->connectionFails(),
            '*mercadopago.test/*' => Http::response(['message' => 'invalid', 'cause' => []], 400),
        ]);

        $this->createPayment()->assertStatus(400)->assertJson(['success' => false, 'gateway' => 'mercadopago']);
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
    }

    public function test_mercado_pago_rejected_pix_counts_as_unavailable(): void
    {
        $this->enableMercadoPago();
        $this->fakeAccountWithToken();
        Http::fake([
            '*/v5/orders' => $this->connectionFails(),
            '*mercadopago.test/*' => Http::response($this->mpPix(['status' => 'failed', 'status_detail' => 'failed']), 201),
        ]);

        $this->createPayment()->assertStatus(503);
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
    }

    public function test_without_mercado_pago_credentials_behaviour_is_unchanged(): void
    {
        $account = $this->fakeAccountWithToken();
        Http::fake(['*/v5/orders' => Http::response(['message' => 'boom'], 500)]);

        $this->assertFalse(app(GatewayManager::class)->forAccount($account->id)->fallbackEnabled());

        // o status do provedor sobe como sempre, sem tentar outro gateway
        $this->createPayment()->assertStatus(500)->assertJson(['success' => false, 'errors' => ['message' => 'boom']]);
        $this->assertSame([], $this->sentToMercadoPago());
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
    }

    public function test_disabled_flag_turns_failover_off_even_with_credentials(): void
    {
        $this->enableMercadoPago();
        config(['gateways.mercadopago.enabled' => false]);
        $this->fakeAccountWithToken();
        Http::fake(['*/v5/orders' => $this->connectionFails()]);

        $this->createPayment()->assertStatus(503);
        $this->assertSame([], $this->sentToMercadoPago());
    }

    public function test_mercado_pago_pix_expiration_is_at_least_30_minutes_and_matches_what_was_sent(): void
    {
        Carbon::setTestNow('2026-10-17 10:00:00');
        $this->enableMercadoPago();
        $this->fakeAccountWithToken('valid-token', '900');
        $this->pagarmeDownMercadoPagoUp();

        $this->createPayment()->assertStatus(201);

        [$sent] = $this->sentToMercadoPago();
        $this->assertSame('PT30M', $sent[0]['transactions']['payments'][0]['expiration_time']);
        $this->assertSame('2026-10-17 10:30:00', Carbon::parse($this->onlyPayment()->expirationDate)->format('Y-m-d H:i:s'));
        $this->assertSame(900, $this->onlyPayment()->duration);
    }

    public function test_mercado_pago_pix_keeps_a_longer_configured_duration(): void
    {
        Carbon::setTestNow('2026-10-17 10:00:00');
        $this->enableMercadoPago();
        $this->fakeAccountWithToken('valid-token', '7200');
        $this->pagarmeDownMercadoPagoUp();

        $this->createPayment()->assertStatus(201);

        [$sent] = $this->sentToMercadoPago();
        $this->assertSame('PT120M', $sent[0]['transactions']['payments'][0]['expiration_time']);
        $this->assertSame('2026-10-17 12:00:00', Carbon::parse($this->onlyPayment()->expirationDate)->format('Y-m-d H:i:s'));
    }

    public function test_long_qr_code_and_copy_paste_are_stored_without_truncation(): void
    {
        $this->enableMercadoPago();
        $this->fakeAccountWithToken();
        $copy = '00020126'.str_repeat('A', 1500);
        $base64 = str_repeat('B', 30000);
        Http::fake([
            '*/v5/orders' => $this->connectionFails(),
            '*mercadopago.test/*' => Http::response($this->mpPix(['transactions' => ['payments' => [[
                'payment_method' => ['id' => 'pix', 'qr_code' => $copy, 'qr_code_base64' => $base64],
            ]]]]), 201),
        ]);

        $this->createPayment()->assertStatus(201);

        $payment = $this->onlyPayment()->refresh();
        $this->assertSame($copy, $payment->copyPaste);
        $this->assertSame('data:image/png;base64,'.$base64, $payment->qrCode);
    }

    public function test_open_breaker_skips_pagarme_and_goes_straight_to_mercado_pago(): void
    {
        $this->enableMercadoPago();
        $account = $this->fakeAccountWithToken();
        Cache::put(CircuitBreaker::key($account->id, GatewayEnum::PAGARME, 'opened_at'), now()->timestamp);
        Http::fake([
            '*/v5/orders' => Http::response($this->pagarmePix(), 200),
            '*mercadopago.test/*' => Http::response($this->mpPix(), 201),
        ]);

        $this->createPayment()->assertStatus(201)->assertJson(['gateway' => 'mercadopago']);

        $this->assertSame([], $this->sentToPagarme());
    }

    public function test_consecutive_failures_open_the_breaker(): void
    {
        $this->enableMercadoPago();
        config(['gateways.circuit.failures' => 3]);
        $this->fakeAccountWithToken();
        Http::fake([
            '*/v5/orders' => Http::response(['message' => 'boom'], 500),
            '*mercadopago.test/*' => Http::response($this->mpPix(), 201),
        ]);

        foreach (range(1, 3) as $i) {
            $this->createPayment()->assertStatus(201);
        }
        $this->assertCount(3, $this->sentToPagarme());

        // 4º: o breaker já está aberto, o Pagar.me nem é chamado
        $this->createPayment()->assertStatus(201)->assertJson(['gateway' => 'mercadopago']);
        $this->assertCount(3, $this->sentToPagarme());
    }

    public function test_half_open_breaker_probes_pagarme_and_closes_on_success(): void
    {
        $this->enableMercadoPago();
        $account = $this->fakeAccountWithToken();
        // aberto há mais que o cooldown (120 s): a próxima chamada é a sonda
        Cache::put(CircuitBreaker::key($account->id, GatewayEnum::PAGARME, 'opened_at'), now()->subSeconds(300)->timestamp);
        Http::fake([
            '*/v5/orders' => Http::response($this->pagarmePix(), 200),
            '*mercadopago.test/*' => Http::response($this->mpPix(), 201),
        ]);

        $this->createPayment()->assertStatus(201)->assertJson(['gateway' => 'pagarme']);

        $this->assertNull(Cache::get(CircuitBreaker::key($account->id, GatewayEnum::PAGARME, 'opened_at')));
    }

    public function test_failed_probe_reopens_the_breaker_immediately(): void
    {
        $this->enableMercadoPago();
        $account = $this->fakeAccountWithToken();
        Cache::put(CircuitBreaker::key($account->id, GatewayEnum::PAGARME, 'opened_at'), now()->subSeconds(300)->timestamp);
        Http::fake([
            '*/v5/orders' => Http::response(['message' => 'boom'], 500),
            '*mercadopago.test/*' => Http::response($this->mpPix(), 201),
        ]);

        $this->createPayment()->assertStatus(201)->assertJson(['gateway' => 'mercadopago']);

        $this->assertGreaterThanOrEqual(now()->subSeconds(5)->timestamp, Cache::get(CircuitBreaker::key($account->id, GatewayEnum::PAGARME, 'opened_at')));
        $this->assertTrue(app(CircuitBreaker::class)->isOpen($account->id, GatewayEnum::PAGARME));
    }

    public function test_breaker_failures_expire_with_the_window(): void
    {
        $this->enableMercadoPago();
        config(['gateways.circuit.failures' => 3, 'gateways.circuit.window' => 60]);
        $account = $this->fakeAccountWithToken();
        Http::fake([
            '*/v5/orders' => Http::response(['message' => 'boom'], 500),
            '*mercadopago.test/*' => Http::response($this->mpPix(), 201),
        ]);

        $this->createPayment()->assertStatus(201);
        $this->createPayment()->assertStatus(201);

        // as 2 falhas saíram da janela de 60 s: a 3ª conta como a primeira e o circuito segue fechado
        Carbon::setTestNow(now()->addSeconds(120));

        $this->createPayment()->assertStatus(201);
        $this->assertFalse(app(CircuitBreaker::class)->isOpen($account->id, GatewayEnum::PAGARME));
        $this->assertSame(1, Cache::get(CircuitBreaker::key($account->id, GatewayEnum::PAGARME, 'failures')));
    }

    public function test_old_env_format_still_drives_pagarme_config(): void
    {
        // R20: nenhuma variável do .env é renomeada; o Pagar.me segue lendo config/integration.php
        $this->assertSame(config('integration.url_integration'), env('URL_INTEGRATION'));
        $this->assertArrayHasKey('card_http_timeout', config('integration'));
        $this->assertSame(5, config('gateways.create_timeout'));
        $this->assertFalse(filled(config('gateways.mercadopago.access_token')));
    }
}
