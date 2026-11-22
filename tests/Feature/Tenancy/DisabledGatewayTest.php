<?php

namespace Tests\Feature\Tenancy;

use App\Enums\GatewayEnum;
use App\Enums\StatusPaymentEnum;
use App\Models\Account;
use App\Models\Config;
use App\Models\GatewayCredential;
use App\Models\Payment;
use App\Models\User;
use App\Services\Gateways\GatewayManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesGateways;
use Tests\TestCase;

/** Desativar um gateway vale só para cobranças NOVAS: as que já existem seguem sendo acompanhadas por ele. */
class DisabledGatewayTest extends TestCase
{
    use FakesGateways, RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gateways.env_credentials_account' => null, 'gateways.mercadopago.url' => 'https://api.mercadopago.test']);
        $this->account = Account::factory()->create();
        User::factory()->create(['account_id' => $this->account->id]);
        Config::factory()->create(['account_id' => $this->account->id, 'api_token' => 'tok-a', 'duration' => '900']);
    }

    private function gateway(GatewayEnum $gateway, array $credentials, bool $enabled = true): void
    {
        GatewayCredential::withoutGlobalScopes()->create(['account_id' => $this->account->id, 'gateway' => $gateway, 'credentials' => $credentials, 'enabled' => $enabled]);
    }

    private function create()
    {
        return $this->postJson('/api/create-payment', $this->validPayload(), ['Authorization' => 'Bearer tok-a']);
    }

    private function sentWith(string $needle): array
    {
        return Http::recorded(fn (Request $r) => str_contains((string) ($r->header('Authorization')[0] ?? ''), $needle))->all();
    }

    public function test_new_charges_never_use_a_disabled_gateway(): void
    {
        $this->gateway(GatewayEnum::PAGARME, ['user' => 'sk_test_DESATIVADO'], enabled: false);
        $this->gateway(GatewayEnum::MERCADOPAGO, ['access_token' => 'TEST-mp-ativo']);
        Http::fake([
            '*/v5/orders' => Http::response($this->pagarmePix(), 200),
            '*mercadopago.test/*' => Http::response($this->mpPix(), 201),
        ]);

        $this->create()->assertStatus(201)->assertJson(['gateway' => 'mercadopago']);

        $this->assertSame([], Http::recorded(fn (Request $r) => str_contains($r->url(), '/v5/orders'))->all(), 'Pagar.me desativado não é chamado');
        $this->assertCount(1, $this->sentWith('Bearer TEST-mp-ativo'));
    }

    public function test_with_every_gateway_disabled_new_charges_are_refused(): void
    {
        $this->gateway(GatewayEnum::PAGARME, ['user' => 'sk_test_A'], enabled: false);
        $this->gateway(GatewayEnum::MERCADOPAGO, ['access_token' => 'TEST-mp'], enabled: false);
        Http::fake();

        $this->create()->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_existing_payments_keep_being_polled_with_the_disabled_gateways_credentials(): void
    {
        $this->gateway(GatewayEnum::PAGARME, ['user' => 'sk_test_DESATIVADO'], enabled: false);
        $payment = Payment::factory()->pending()->create(['account_id' => $this->account->id, 'ide' => 'or_1', 'pix_gateway' => GatewayEnum::PAGARME, 'expirationDate' => now()->addMinutes(10)]);
        Http::fake(['*/v5/orders/*' => Http::response(['id' => 'or_1', 'status' => 'paid', 'charges' => [['status' => 'paid', 'payment_method' => 'pix', 'last_transaction' => ['status' => 'paid']]]], 200)]);

        $this->artisan('app:process-payment')->assertSuccessful();

        $this->assertCount(1, $this->sentWith('Basic '.base64_encode('sk_test_DESATIVADO:')), 'segue consultando com a credencial do gateway desativado');
        $this->assertSame(StatusPaymentEnum::PAID, $payment->refresh()->status, 'e reconhece o pagamento');
    }

    public function test_an_overdue_pix_is_still_decided_by_the_disabled_gateway_not_canceled_blindly(): void
    {
        $this->gateway(GatewayEnum::PAGARME, ['user' => 'sk_test_DESATIVADO'], enabled: false);
        $payment = Payment::factory()->pending()->create(['account_id' => $this->account->id, 'ide' => 'or_1', 'pix_gateway' => GatewayEnum::PAGARME, 'expirationDate' => now()->subDays(2)]);
        Http::fake(['*/v5/orders/*' => Http::response(['id' => 'or_1', 'status' => 'paid', 'charges' => [['status' => 'paid', 'payment_method' => 'pix', 'last_transaction' => ['status' => 'paid']]]], 200)]);

        $this->artisan('app:process-payment')->assertSuccessful();

        $this->assertSame(StatusPaymentEnum::PAID, $payment->refresh()->status);
    }

    public function test_the_disabled_gateway_offers_no_card_tokenization_and_is_not_redundancy(): void
    {
        $this->gateway(GatewayEnum::PAGARME, ['user' => 'sk_test_A', 'public_key' => 'pk_test_A'], enabled: false);
        $tenant = app(GatewayManager::class)->forAccount($this->account->id);

        $this->assertNull($tenant->cardCheckoutConfig());
        $this->assertFalse($tenant->hasAnyGateway());
        $this->assertFalse($tenant->hasRedundancy());
        $this->assertFalse($tenant->isCardGatewayStillCurrent(GatewayEnum::PAGARME));
        $this->assertTrue($tenant->gateway(GatewayEnum::PAGARME)->name() === GatewayEnum::PAGARME, 'mas o gateway segue acessível para consultas');
    }
}
