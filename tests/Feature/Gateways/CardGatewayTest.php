<?php

namespace Tests\Feature\Gateways;

use App\Enums\GatewayEnum;
use App\Enums\PaymentMethodEnum;
use App\Enums\StatusPaymentEnum;
use App\Models\Account;
use App\Models\Payment;
use App\Services\Gateways\CircuitBreaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\FakesGateways;
use Tests\TestCase;

class CardGatewayTest extends TestCase
{
    use FakesGateways, RefreshDatabase;

    private const MP_TOKEN = '0a1b2c3d4e5f60718293a4b5c6d7e8f9';

    protected function setUp(): void
    {
        parent::setUp();

        config(['integration.public_key' => 'pk_test_abc123']);
        $this->enableMercadoPago();
    }

    private ?Account $account = null;

    /** Uma conta por teste: o breaker é por conta (a falha de um tenant não abre o circuito de outro). */
    private function account(): Account
    {
        return $this->account ??= Account::factory()->create();
    }

    private function payment(string $state = 'withCustomer', array $attrs = []): Payment
    {
        return Payment::factory()->{$state}()->create($attrs + ['account_id' => $this->account()->id, 'amount' => 10.5]);
    }

    private function openPagarmeBreaker(): void
    {
        Cache::put(CircuitBreaker::key($this->account()->id, GatewayEnum::PAGARME, 'opened_at'), now()->timestamp);
    }

    private function pagarmePayload(array $o = []): array
    {
        return array_merge([
            'gateway' => 'pagarme',
            'card_token' => 'token_abc123',
            'installments' => 1,
            'billing_address' => ['line_1' => 'Rua A, 10', 'zip_code' => '01001000', 'city' => 'São Paulo', 'state' => 'SP', 'country' => 'BR'],
        ], $o);
    }

    private function mpPayload(array $o = []): array
    {
        return array_merge([
            'gateway' => 'mercadopago',
            'card_token' => self::MP_TOKEN,
            'payment_method_id' => 'visa',
            'issuer_id' => '310',
            'installments' => 1,
        ], $o);
    }

    private function pay(Payment $payment, array $payload)
    {
        return $this->postJson("/checkout/{$payment->id}/pay-card", $payload);
    }

    private function mpCalls(string $method = 'POST'): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), 'mercadopago.test') && $r->method() === $method)->all();
    }

    private function pagarmeCalls(string $method = 'POST'): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/v5/orders') && $r->method() === $method)->all();
    }

    // ---- GET /checkout/{id}/card-config -------------------------------------------------------

    public function test_card_config_returns_pagarme_by_default(): void
    {
        $p = $this->payment();

        $this->getJson("/checkout/{$p->id}/card-config")
            ->assertOk()
            ->assertExactJson([
                'gateway' => 'pagarme',
                'publicKey' => 'pk_test_abc123',
                'tokenUrl' => 'https://api.pagar.me/core/v5/tokens',
                'maxInstallments' => 12,
            ]);
    }

    public function test_card_config_switches_to_mercado_pago_when_breaker_is_open_and_never_leaks_the_access_token(): void
    {
        $p = $this->payment();
        $this->openPagarmeBreaker();

        $response = $this->getJson("/checkout/{$p->id}/card-config")->assertOk();

        $response->assertJson(['gateway' => 'mercadopago', 'publicKey' => 'TEST-mp-public', 'maxInstallments' => 1]);
        $this->assertStringNotContainsString('TEST-mp-token', $response->getContent());
    }

    public function test_card_config_is_closed_for_payments_that_cannot_pay_by_card(): void
    {
        $this->getJson('/checkout/'.$this->payment('paid')->id.'/card-config')->assertStatus(409);
        $this->getJson('/checkout/'.$this->payment('expired')->id.'/card-config')->assertStatus(409);
        $this->getJson('/checkout/'.$this->payment('pixOnly')->id.'/card-config')->assertStatus(409);
        $this->getJson('/checkout/'.$this->payment('pending')->id.'/card-config')->assertStatus(409); // legado, sem customer
        $this->getJson('/checkout/'.\Illuminate\Support\Str::uuid().'/card-config')->assertNotFound();
    }

    public function test_card_config_is_throttled(): void
    {
        $p = $this->payment();

        foreach (range(1, 30) as $i) {
            $this->getJson("/checkout/{$p->id}/card-config")->assertOk();
        }

        $this->getJson("/checkout/{$p->id}/card-config")->assertStatus(429);
    }

    public function test_card_is_enabled_when_only_mercado_pago_has_a_public_key(): void
    {
        config(['integration.public_key' => null]);
        $p = $this->payment();

        $this->getJson("/checkout/{$p->id}/card-config")->assertOk()->assertJson(['gateway' => 'mercadopago']);

        $this->get("/checkout/{$p->id}")
            ->assertOk()
            ->assertSee('role="tablist"', false)
            ->assertSee('TEST-mp-public')
            ->assertSee('card-config')
            ->assertDontSee('TEST-mp-token');
    }

    public function test_form_config_tells_whether_a_pix_tab_exists_so_messages_do_not_send_the_payer_to_it(): void
    {
        $this->get('/checkout/'.$this->payment('cardOnly')->id)->assertOk()->assertSee('\\u0022pixEnabled\\u0022:false', false);
        $this->get('/checkout/'.$this->payment('withCustomer')->id)->assertOk()->assertSee('\\u0022pixEnabled\\u0022:true', false);
    }

    public function test_credit_card_checkout_is_accepted_when_only_mercado_pago_has_a_public_key(): void
    {
        config(['integration.public_key' => null]);
        $this->fakeAccountWithToken();

        $this->createPayment(['payment_methods' => ['credit_card']])->assertStatus(201);
    }

    public function test_credit_card_checkout_is_rejected_when_no_gateway_has_a_public_key(): void
    {
        config(['integration.public_key' => null, 'gateways.mercadopago.public_key' => null]);
        $this->fakeAccountWithToken();

        $this->createPayment(['payment_methods' => ['credit_card']])->assertStatus(422);
    }

    // ---- validação por gateway ------------------------------------------------------------------

    public function test_mercado_pago_token_only_passes_with_the_mercado_pago_gateway(): void
    {
        $p = $this->payment();
        Http::fake();

        // formato do MP no gateway Pagar.me (regex ^token_...)
        $this->pay($p, $this->pagarmePayload(['card_token' => self::MP_TOKEN]))->assertStatus(422)->assertJsonValidationErrors('card_token');
        // formato do Pagar.me no gateway MP
        $this->pay($p, $this->mpPayload(['card_token' => 'token_abc123']))->assertStatus(422)->assertJsonValidationErrors('card_token');
        // sem payment_method_id no MP
        $this->pay($p, array_diff_key($this->mpPayload(), ['payment_method_id' => 1]))->assertStatus(422)->assertJsonValidationErrors('payment_method_id');
        // MP só 1x
        $this->pay($p, $this->mpPayload(['installments' => 3]))->assertStatus(422)->assertJsonValidationErrors('installments');
        // gateway desconhecido
        $this->pay($p, $this->mpPayload(['gateway' => 'stripe']))->assertStatus(422)->assertJsonValidationErrors('gateway');

        Http::assertNothingSent();
    }

    public function test_pagarme_still_requires_billing_address_and_mercado_pago_does_not(): void
    {
        $p = $this->payment();

        $this->pay($p, array_diff_key($this->pagarmePayload(), ['billing_address' => 1]))->assertStatus(422)->assertJsonValidationErrors('billing_address.line_1');

        $this->openPagarmeBreaker();
        Http::fake(['*mercadopago.test/v1/orders' => Http::response($this->mpCard('processed'), 201)]);
        $this->pay($p, $this->mpPayload())->assertOk();
    }

    public function test_requests_without_gateway_default_to_pagarme(): void
    {
        $p = $this->payment();
        Http::fake(['*/v5/orders' => Http::response($this->pagarmeCard('captured'), 200)]);

        $this->pay($p, array_diff_key($this->pagarmePayload(), ['gateway' => 1]))->assertOk();

        $this->assertSame(GatewayEnum::PAGARME, $p->refresh()->card_gateway);
    }

    // ---- cobrança no Mercado Pago ---------------------------------------------------------------

    public function test_card_is_charged_at_mercado_pago_with_the_amount_from_the_payment(): void
    {
        $p = $this->payment();
        $this->openPagarmeBreaker();
        Http::fake(['*mercadopago.test/v1/orders' => Http::response($this->mpCard('processed'), 201)]);

        $this->pay($p, $this->mpPayload())->assertOk()->assertExactJson(['status' => 'paid', 'message' => 'Pagamento aprovado.']);

        $p->refresh();
        $this->assertSame(StatusPaymentEnum::PAID, $p->status);
        $this->assertSame(PaymentMethodEnum::CREDIT_CARD, $p->payment_method);
        $this->assertSame(GatewayEnum::MERCADOPAGO, $p->card_gateway);
        $this->assertSame('ORD00000000000000000000987', $p->card_order_id);
        $this->assertSame('visa', $p->card_brand);
        // a resposta da Orders API não traz final do cartão nem código de autorização
        $this->assertNull($p->card_last_four);
        $this->assertNull($p->card_attempt_key);
        $this->assertSame([], $this->pagarmeCalls());

        [$sent] = $this->mpCalls();
        $request = $sent[0];
        $method = $request['transactions']['payments'][0]['payment_method'];
        $this->assertSame('10.50', $request['total_amount']);
        $this->assertSame('10.50', $request['transactions']['payments'][0]['amount']);
        $this->assertSame(self::MP_TOKEN, $method['token']);
        $this->assertSame(1, $method['installments']);
        $this->assertSame('visa', $method['id']);
        $this->assertSame('credit_card', $method['type']);
        $this->assertSame($p->id, $request['external_reference']);
        $this->assertSame(['type' => 'CPF', 'number' => '12345678909'], $request['payer']['identification']);
        $this->assertNotEmpty($request->header('X-Idempotency-Key')[0]);
        $this->assertArrayNotHasKey('billing_address', $request->data());
    }

    public function test_the_amount_charged_never_comes_from_the_request(): void
    {
        $p = $this->payment('withCustomer', ['amount' => 42.9]);
        $this->openPagarmeBreaker();
        Http::fake(['*mercadopago.test/v1/orders' => Http::response($this->mpCard('processed', 'accredited', ['total_amount' => '42.90']), 201)]);

        $this->pay($p, $this->mpPayload(['amount' => 1, 'transaction_amount' => 1]))->assertOk();

        [$sent] = $this->mpCalls();
        $this->assertSame('42.90', $sent[0]['total_amount']);
    }

    public function test_mercado_pago_decline_releases_the_attempt_and_does_not_fail_over(): void
    {
        $p = $this->payment();
        $this->openPagarmeBreaker();
        Http::fake(['*mercadopago.test/v1/orders' => Http::response($this->mpCard('failed', 'cc_rejected_insufficient_amount'), 201)]);

        $this->pay($p, $this->mpPayload())->assertStatus(422)->assertJson(['status' => 'declined']);

        $p->refresh();
        $this->assertSame(StatusPaymentEnum::PENDING, $p->status);
        $this->assertNull($p->card_attempt_key);
        $this->assertSame([], $this->pagarmeCalls());
    }

    public function test_mercado_pago_in_process_returns_202_and_blocks_a_second_attempt(): void
    {
        $p = $this->payment();
        $this->openPagarmeBreaker();
        Http::fake(['*mercadopago.test/v1/orders' => Http::response($this->mpCard('processing', 'pending_contingency'), 201)]);

        $this->pay($p, $this->mpPayload())->assertStatus(202);

        $p->refresh();
        $this->assertSame('ORD00000000000000000000987', $p->card_order_id);
        $this->assertNotNull($p->card_attempt_key);
        $this->assertSame('processing', $p->provider_status);

        $this->pay($p, $this->mpPayload())->assertStatus(409);
        $this->assertCount(1, $this->mpCalls());
    }

    public function test_mercado_pago_validation_error_is_a_decline_that_releases_the_attempt(): void
    {
        $p = $this->payment();
        $this->openPagarmeBreaker();
        Http::fake(['*mercadopago.test/v1/orders' => Http::response(['message' => 'invalid token', 'error' => 'bad_request', 'status' => 400, 'cause' => [['code' => 2062]]], 400)]);

        $this->pay($p, $this->mpPayload())->assertStatus(422)->assertJson(['status' => 'declined']);

        $this->assertNull($p->refresh()->card_attempt_key);
    }

    public function test_mercado_pago_order_carries_items_and_the_device_id(): void
    {
        $p = $this->payment('withCustomer', ['items' => [
            ['amount' => 500, 'description' => 'Camiseta', 'quantity' => 1],
            ['amount' => 275, 'description' => 'Boné', 'quantity' => 2],
        ], 'amount' => 10.5]);
        $this->openPagarmeBreaker();
        Http::fake(['*mercadopago.test/v1/orders' => Http::response($this->mpCard('processed'), 201)]);

        // o Device ID real do SDK ('armor.' + hash) passa de 128 caracteres
        $deviceId = 'armor.'.str_repeat('a1B2c3', 30);
        $this->pay($p, $this->mpPayload(['device_id' => $deviceId]))->assertOk();

        [$sent] = $this->mpCalls();
        $this->assertSame($deviceId, $sent[0]->header('X-meli-session-id')[0]);
        $this->assertSame([
            ['title' => 'Camiseta', 'unit_price' => '5.00', 'quantity' => 1, 'category_id' => 'others'],
            ['title' => 'Boné', 'unit_price' => '2.75', 'quantity' => 2, 'category_id' => 'others'],
        ], $sent[0]['items']);
    }

    public function test_mercado_pago_order_without_stored_items_sends_a_single_item_and_no_device_header(): void
    {
        $p = $this->payment();
        $this->openPagarmeBreaker();
        Http::fake(['*mercadopago.test/v1/orders' => Http::response($this->mpCard('processed'), 201)]);

        $this->pay($p, $this->mpPayload())->assertOk();

        [$sent] = $this->mpCalls();
        $this->assertSame([], $sent[0]->header('X-meli-session-id'));
        $this->assertSame('10.50', $sent[0]['items'][0]['unit_price']);
        $this->assertSame(1, $sent[0]['items'][0]['quantity']);
    }

    public function test_device_id_with_odd_characters_is_rejected(): void
    {
        $p = $this->payment();
        $this->openPagarmeBreaker();

        $this->pay($p, $this->mpPayload(['device_id' => "x
Injected: 1"]))->assertStatus(422)->assertJsonValidationErrors('device_id');
    }

    public function test_mercado_pago_402_is_a_decline_that_releases_the_attempt(): void
    {
        $p = $this->payment();
        $this->openPagarmeBreaker();
        Http::fake(['*mercadopago.test/v1/orders' => Http::response([
            'errors' => [['code' => 'failed', 'message' => 'rejected']],
            'data' => $this->mpCard('failed', 'rejected_by_issuer'),
        ], 402)]);

        $this->pay($p, $this->mpPayload())->assertStatus(422)->assertJson(['status' => 'declined']);

        $p->refresh();
        $this->assertSame(StatusPaymentEnum::PENDING, $p->status);
        $this->assertNull($p->card_attempt_key);
    }

    public function test_mercado_pago_outage_during_charge_stays_processing_without_fallback(): void
    {
        $p = $this->payment();
        $this->openPagarmeBreaker();
        Http::fake(['*mercadopago.test/v1/orders' => Http::response('boom', 502)]);

        $this->pay($p, $this->mpPayload())->assertStatus(503)->assertJson(['status' => 'processing']);

        $p->refresh();
        $this->assertNotNull($p->card_attempt_key);
        $this->assertSame(GatewayEnum::MERCADOPAGO, $p->card_gateway);
        $this->assertSame([], $this->pagarmeCalls());
    }

    public function test_total_paid_amount_different_from_the_payment_logs_an_error_but_keeps_the_result(): void
    {
        $p = $this->payment();
        $this->openPagarmeBreaker();
        Log::spy();
        Http::fake(['*mercadopago.test/v1/orders' => Http::response(
            $this->mpCard('processed', 'accredited', ['total_paid_amount' => '12.0']), 201
        )]);

        $this->pay($p, $this->mpPayload())->assertOk();

        Log::shouldHaveReceived('error')->withArgs(fn ($message) => str_contains($message, 'total_paid_amount'))->once();
    }

    public function test_matching_total_paid_amount_does_not_log(): void
    {
        $p = $this->payment();
        $this->openPagarmeBreaker();
        Log::spy();
        Http::fake(['*mercadopago.test/v1/orders' => Http::response(
            $this->mpCard('processed', 'accredited', ['total_paid_amount' => '10.5']), 201
        )]);

        $this->pay($p, $this->mpPayload())->assertOk();

        Log::shouldNotHaveReceived('error');
    }

    // ---- gateway vigente (R15) -------------------------------------------------------------------

    public function test_stale_pagarme_token_after_breaker_opened_asks_the_browser_to_retokenize_without_charging(): void
    {
        $p = $this->payment();
        $this->openPagarmeBreaker();
        Http::fake();

        $this->pay($p, $this->pagarmePayload())
            ->assertStatus(503)
            ->assertJson(['status' => 'fallback', 'gateway' => 'mercadopago', 'publicKey' => 'TEST-mp-public', 'maxInstallments' => 1]);

        Http::assertNothingSent();
        $p->refresh();
        $this->assertNull($p->card_attempt_key);
        $this->assertNull($p->card_gateway);
    }

    public function test_stale_mercado_pago_token_after_pagarme_recovered_asks_to_retokenize_at_pagarme(): void
    {
        $p = $this->payment();
        Http::fake();

        $this->pay($p, $this->mpPayload())
            ->assertStatus(503)
            ->assertJson(['status' => 'fallback', 'gateway' => 'pagarme', 'publicKey' => 'pk_test_abc123']);

        Http::assertNothingSent();
    }

    public function test_attempt_of_another_gateway_blocks_a_new_one(): void
    {
        // tentativa do Pagar.me com timeout, fora da janela, ainda sem reconciliar: não abre outra no MP
        $p = $this->payment('cardTimedOut', ['card_gateway' => GatewayEnum::PAGARME]);
        $this->openPagarmeBreaker();
        Http::fake();

        $this->pay($p, $this->mpPayload())->assertStatus(409);

        Http::assertNothingSent();
    }

    // ---- fallback em voo ------------------------------------------------------------------------

    public function test_pagarme_failure_with_proof_of_no_charge_offers_mercado_pago_and_never_double_charges(): void
    {
        $p = $this->payment();
        Http::fake([
            // criação do pedido de cartão cai; a busca por code prova que nada foi criado
            '*/v5/orders?code=*' => Http::response(['data' => []], 200),
            '*/v5/orders' => Http::response('boom', 500),
            '*mercadopago.test/v1/orders' => Http::response($this->mpCard('processed'), 201),
        ]);

        $this->pay($p, $this->pagarmePayload())
            ->assertStatus(503)
            ->assertJson(['status' => 'fallback', 'gateway' => 'mercadopago', 'publicKey' => 'TEST-mp-public']);

        $p->refresh();
        $this->assertNull($p->card_attempt_key); // tentativa do Pagar.me liberada: uma só tentativa ativa
        $this->assertSame([], $this->mpCalls());

        // o browser re-tokeniza no MP e reenvia
        $this->pay($p, $this->mpPayload())->assertOk()->assertJson(['status' => 'paid']);

        $p->refresh();
        $this->assertSame(StatusPaymentEnum::PAID, $p->status);
        $this->assertSame(GatewayEnum::MERCADOPAGO, $p->card_gateway);
        $this->assertCount(1, $this->mpCalls());
        $this->assertCount(1, $this->pagarmeCalls());
    }

    public function test_pagarme_failure_with_inconclusive_reconciliation_stays_processing(): void
    {
        $p = $this->payment();
        Http::fake([
            '*/v5/orders?code=*' => Http::response('boom', 500),
            '*/v5/orders' => Http::response('boom', 500),
            '*mercadopago.test/*' => Http::response($this->mpCard('processed'), 201),
        ]);

        $this->pay($p, $this->pagarmePayload())->assertStatus(503)->assertJson(['status' => 'processing']);

        $p->refresh();
        $this->assertNotNull($p->card_attempt_key);
        $this->assertSame(GatewayEnum::PAGARME, $p->card_gateway);
        $this->assertSame([], $this->mpCalls());
    }

    public function test_pagarme_failure_when_the_charge_exists_applies_it_instead_of_falling_back(): void
    {
        $p = $this->payment();
        $order = $this->pagarmeCard('captured') + ['metadata' => ['payment_id' => $p->id]];
        Http::fake([
            '*/v5/orders?code=*' => Http::response(['data' => [$order]], 200),
            '*/v5/orders' => Http::response('boom', 500),
            '*mercadopago.test/*' => Http::response($this->mpCard('processed'), 201),
        ]);

        $this->pay($p, $this->pagarmePayload())->assertOk()->assertJson(['status' => 'paid']);

        $p->refresh();
        $this->assertSame(StatusPaymentEnum::PAID, $p->status);
        $this->assertSame('or_card1', $p->card_order_id);
        $this->assertSame(GatewayEnum::PAGARME, $p->card_gateway);
        $this->assertSame([], $this->mpCalls());
    }

    public function test_pagarme_failure_without_mercado_pago_keeps_the_existing_behaviour(): void
    {
        config(['gateways.mercadopago.access_token' => null]);
        $p = $this->payment();
        Http::fake(['*/v5/orders' => Http::response('boom', 500)]);

        $this->pay($p, $this->pagarmePayload())->assertStatus(503)->assertJson(['status' => 'processing']);

        $this->assertNotNull($p->refresh()->card_attempt_key);
        $this->assertSame([], $this->mpCalls());
        // só a criação: sem busca por code porque não há para onde ir
        $this->assertCount(1, $this->pagarmeCalls());
        $this->assertCount(0, $this->pagarmeCalls('GET'));
    }

    public function test_pagarme_decline_does_not_fail_over_to_mercado_pago(): void
    {
        $p = $this->payment();
        Http::fake([
            '*/v5/orders' => Http::response($this->pagarmeCard('not_authorized', 'failed'), 200),
            '*mercadopago.test/*' => Http::response($this->mpCard('processed'), 201),
        ]);

        $this->pay($p, $this->pagarmePayload())->assertStatus(422)->assertJson(['status' => 'declined']);

        $this->assertSame([], $this->mpCalls());
        $this->assertNull($p->refresh()->card_attempt_key);
    }

    public function test_pagarme_failures_in_flight_feed_the_breaker(): void
    {
        config(['gateways.circuit.failures' => 2]);
        $p = $this->payment();
        Http::fake([
            '*/v5/orders?code=*' => Http::response(['data' => []], 200),
            '*/v5/orders' => $this->connectionFails(),
        ]);

        $this->pay($p, $this->pagarmePayload())->assertStatus(503);
        $this->assertFalse(app(CircuitBreaker::class)->isOpen($this->account()->id, GatewayEnum::PAGARME));
        $this->assertTrue(app(CircuitBreaker::class)->isDegraded($this->account()->id, GatewayEnum::PAGARME));
    }
}
