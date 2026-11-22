<?php

namespace Tests\Feature\Tenancy;

use App\Enums\GatewayEnum;
use App\Enums\StatusPaymentEnum;
use App\Models\Account;
use App\Models\Config;
use App\Models\GatewayCredential;
use App\Models\Payment;
use App\Models\User;
use App\Services\Gateways\CircuitBreaker;
use App\Services\Gateways\GatewayManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesGateways;
use Tests\TestCase;

/** Cada conta cobra com as próprias credenciais; sem credencial não há gateway (nunca se empresta a de outra conta). */
class TenantCredentialsTest extends TestCase
{
    use FakesGateways, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // modo estrito: nenhuma conta usa o .env
        config(['gateways.env_credentials_account' => null, 'gateways.mercadopago.url' => 'https://api.mercadopago.test']);
    }

    private function tenant(string $token, array $pagarme = [], array $mp = []): Account
    {
        $account = Account::factory()->create();
        User::factory()->create(['account_id' => $account->id]);
        Config::factory()->create(['account_id' => $account->id, 'api_token' => $token, 'duration' => '900']);

        if ($pagarme) {
            $this->credential($account, GatewayEnum::PAGARME, $pagarme);
        }
        if ($mp) {
            $this->credential($account, GatewayEnum::MERCADOPAGO, $mp);
        }

        return $account;
    }

    private function credential(Account $account, GatewayEnum $gateway, array $credentials, bool $enabled = true): GatewayCredential
    {
        return GatewayCredential::withoutGlobalScopes()->create([
            'account_id' => $account->id, 'gateway' => $gateway, 'credentials' => $credentials, 'enabled' => $enabled,
        ]);
    }

    private function create(string $token, array $overrides = [])
    {
        return $this->postJson('/api/create-payment', $this->validPayload($overrides), ['Authorization' => 'Bearer '.$token]);
    }

    private function sentWithAuth(string $needle): array
    {
        return Http::recorded(fn (Request $r) => str_contains((string) ($r->header('Authorization')[0] ?? ''), $needle))->all();
    }

    public function test_account_without_any_gateway_cannot_create_payments_and_no_provider_is_called(): void
    {
        $this->tenant('tok-a');
        Http::fake();

        $this->create('tok-a')->assertStatus(422)->assertJsonPath('success', false);

        Http::assertNothingSent();
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
    }

    public function test_each_account_charges_with_its_own_pagarme_credentials(): void
    {
        $this->tenant('tok-a', ['user' => 'sk_test_AAAA', 'password' => '']);
        $this->tenant('tok-b', ['user' => 'sk_test_BBBB', 'password' => '']);
        Http::fake(['*/v5/orders' => Http::response($this->pagarmePix(), 200)]);

        $this->create('tok-a')->assertStatus(201);
        $this->create('tok-b')->assertStatus(201);

        $basic = fn (string $user) => 'Basic '.base64_encode($user.':');
        $this->assertCount(1, $this->sentWithAuth($basic('sk_test_AAAA')));
        $this->assertCount(1, $this->sentWithAuth($basic('sk_test_BBBB')));
    }

    public function test_an_account_never_borrows_another_accounts_credentials(): void
    {
        $this->tenant('tok-a', ['user' => 'sk_test_AAAA']);
        $this->tenant('tok-b'); // sem credencial
        Http::fake(['*/v5/orders' => Http::response($this->pagarmePix(), 200)]);

        $this->create('tok-b')->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_env_credentials_only_serve_the_designated_account(): void
    {
        $owner = $this->tenant('tok-a');
        $other = $this->tenant('tok-b');
        config(['gateways.env_credentials_account' => $owner->id, 'integration.user_integration' => 'sk_test_ENV']);
        Http::fake(['*/v5/orders' => Http::response($this->pagarmePix(), 200)]);

        $this->create('tok-a')->assertStatus(201);
        $this->create('tok-b')->assertStatus(422);

        $this->assertSame($owner->id, Payment::withoutGlobalScopes()->firstOrFail()->account_id);
        $this->assertNotSame($other->id, Payment::withoutGlobalScopes()->firstOrFail()->account_id);
    }

    public function test_disabled_credential_counts_as_not_configured(): void
    {
        $account = $this->tenant('tok-a');
        $this->credential($account, GatewayEnum::PAGARME, ['user' => 'sk_test_AAAA'], enabled: false);
        Http::fake();

        $this->create('tok-a')->assertStatus(422);
    }

    public function test_mercado_pago_only_account_uses_it_as_primary(): void
    {
        $this->tenant('tok-a', mp: ['access_token' => 'TEST-mp-A']);
        Http::fake(['*mercadopago.test/*' => Http::response($this->mpPix(), 201)]);

        $this->create('tok-a')->assertStatus(201)->assertJson(['gateway' => 'mercadopago']);

        $this->assertCount(1, $this->sentWithAuth('Bearer TEST-mp-A'));
        $this->assertSame([], Http::recorded(fn (Request $r) => str_contains($r->url(), '/v5/orders'))->all());
    }

    public function test_failover_uses_the_accounts_own_mercado_pago_token(): void
    {
        $this->tenant('tok-a', ['user' => 'sk_test_AAAA'], ['access_token' => 'TEST-mp-A']);
        Http::fake([
            '*/v5/orders' => $this->connectionFails(),
            '*mercadopago.test/*' => Http::response($this->mpPix(), 201),
        ]);

        $this->create('tok-a')->assertStatus(201)->assertJson(['gateway' => 'mercadopago']);

        $this->assertCount(1, $this->sentWithAuth('Bearer TEST-mp-A'));
    }

    public function test_without_mercado_pago_there_is_no_failover_even_if_the_env_has_one(): void
    {
        $this->tenant('tok-a', ['user' => 'sk_test_AAAA']);
        $other = Account::factory()->create();
        // MP configurado no .env para OUTRA conta: a conta A não pode usá-lo
        config(['gateways.env_credentials_account' => $other->id, 'gateways.mercadopago.access_token' => 'TEST-mp-ENV', 'gateways.mercadopago.enabled' => true]);
        Http::fake(['*/v5/orders' => Http::response(['message' => 'boom'], 500)]);

        $this->create('tok-a')->assertStatus(500);

        $this->assertSame([], $this->sentWithAuth('TEST-mp-ENV'));
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
    }

    public function test_circuit_breaker_is_per_account(): void
    {
        $a = $this->tenant('tok-a', ['user' => 'sk_test_AAAA'], ['access_token' => 'TEST-mp-A']);
        $b = $this->tenant('tok-b', ['user' => 'sk_test_BBBB'], ['access_token' => 'TEST-mp-B']);
        Cache::put(CircuitBreaker::key($a->id, GatewayEnum::PAGARME, 'opened_at'), now()->timestamp);
        Http::fake([
            '*/v5/orders' => Http::response($this->pagarmePix(), 200),
            '*mercadopago.test/*' => Http::response($this->mpPix(), 201),
        ]);

        $this->create('tok-a')->assertStatus(201)->assertJson(['gateway' => 'mercadopago']); // circuito de A aberto
        $this->create('tok-b')->assertStatus(201)->assertJson(['gateway' => 'pagarme']);      // o de B segue fechado

        $this->assertFalse(app(CircuitBreaker::class)->isOpen($b->id, GatewayEnum::PAGARME));
    }

    public function test_credentials_are_encrypted_at_rest_and_hidden(): void
    {
        $account = $this->tenant('tok-a');
        $row = $this->credential($account, GatewayEnum::PAGARME, ['user' => 'sk_live_SEGREDO123']);

        $raw = DB::table('gateway_credentials')->where('id', $row->id)->value('credentials');

        $this->assertStringNotContainsString('SEGREDO123', $raw);
        $this->assertArrayNotHasKey('credentials', $row->toArray());
        $this->assertSame('••••O123', $row->hint('user'));
    }

    public function test_credential_scope_isolates_accounts(): void
    {
        $a = $this->tenant('tok-a', ['user' => 'sk_test_AAAA']);
        $b = $this->tenant('tok-b', ['user' => 'sk_test_BBBB']);
        $this->actingAs(User::where('account_id', $a->id)->first());

        $this->assertSame([$a->id], GatewayCredential::pluck('account_id')->all());
        $this->assertSame(2, GatewayCredential::withoutGlobalScopes()->count());
        $this->assertTrue(app(GatewayManager::class)->forAccount($b->id)->hasAnyGateway());
    }

    public function test_redundancy_flag_requires_both_gateways(): void
    {
        $only = $this->tenant('tok-a', ['user' => 'sk_test_AAAA']);
        $both = $this->tenant('tok-b', ['user' => 'sk_test_BBBB'], ['access_token' => 'TEST-mp-B']);

        $this->assertFalse(app(GatewayManager::class)->forAccount($only->id)->hasRedundancy());
        $this->assertTrue(app(GatewayManager::class)->forAccount($both->id)->hasRedundancy());
    }

    public function test_scheduler_polls_each_payment_with_its_own_accounts_credentials(): void
    {
        $a = $this->tenant('tok-a', ['user' => 'sk_test_AAAA']);
        $b = $this->tenant('tok-b', ['user' => 'sk_test_BBBB']);
        foreach ([$a, $b] as $i => $acc) {
            Payment::factory()->pending()->create(['account_id' => $acc->id, 'ide' => 'order_'.$i, 'pix_gateway' => GatewayEnum::PAGARME, 'expirationDate' => now()->addMinutes(10)]);
        }
        Http::fake(['*/v5/orders/*' => Http::response(['id' => 'x', 'status' => 'pending', 'charges' => [['status' => 'pending', 'last_transaction' => ['status' => 'waiting_payment']]]], 200)]);

        $this->artisan('app:process-payment')->assertSuccessful();

        $this->assertCount(1, $this->sentWithAuth('Basic '.base64_encode('sk_test_AAAA:')));
        $this->assertCount(1, $this->sentWithAuth('Basic '.base64_encode('sk_test_BBBB:')));
        $this->assertSame(StatusPaymentEnum::PENDING, Payment::withoutGlobalScopes()->first()->status);
    }
}
