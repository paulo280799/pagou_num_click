<?php

namespace Tests\Feature\Models;

use App\Enums\GatewayEnum;
use App\Models\Account;
use App\Models\GatewayCredential;
use App\Services\Gateways\GatewayManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** O deploy do multi-tenant não pode tirar os gateways da conta que já cobra com o .env. */
class SeedGatewayCredentialsFromEnvTest extends TestCase
{
    use RefreshDatabase;

    private function migrate(): void
    {
        (require database_path('migrations/2026_11_22_140000_seed_gateway_credentials_from_env.php'))->up();
    }

    public function test_existing_accounts_keep_charging_with_the_env_credentials_after_migrating(): void
    {
        config([
            'gateways.env_credentials_account' => null, // nenhuma variável nova foi definida no deploy
            'integration.user_integration' => 'sk_live_ENV', 'integration.password_integration' => 'pw', 'integration.public_key' => 'pk_live_ENV',
            'gateways.mercadopago.enabled' => true, 'gateways.mercadopago.access_token' => 'APP_USR-mp', 'gateways.mercadopago.webhook_secret' => 'whsec',
        ]);
        $existing = Account::factory()->create();

        $this->migrate();

        $gateways = app(GatewayManager::class)->forAccount($existing->id);
        $this->assertTrue($gateways->hasRedundancy());
        $pagarme = GatewayCredential::withoutGlobalScopes()->where('account_id', $existing->id)->where('gateway', 'PAGARME')->firstOrFail();
        $this->assertSame(['user' => 'sk_live_ENV', 'password' => 'pw', 'public_key' => 'pk_live_ENV'], $pagarme->credentials);
        $this->assertTrue($pagarme->enabled);
        $this->assertStringNotContainsString('sk_live_ENV', \DB::table('gateway_credentials')->where('id', $pagarme->id)->value('credentials'));
    }

    public function test_accounts_created_after_the_migration_start_without_gateways_and_it_is_idempotent(): void
    {
        config(['gateways.env_credentials_account' => null, 'integration.user_integration' => 'sk_live_ENV']);
        $this->migrate();
        $this->migrate();

        $later = Account::factory()->create();

        $this->assertSame(0, GatewayCredential::withoutGlobalScopes()->count());
        $this->assertFalse(app(GatewayManager::class)->forAccount($later->id)->hasAnyGateway());
    }

    public function test_it_never_overwrites_credentials_an_account_already_registered(): void
    {
        config(['gateways.env_credentials_account' => null, 'integration.user_integration' => 'sk_live_ENV']);
        $account = Account::factory()->create();
        GatewayCredential::withoutGlobalScopes()->create(['account_id' => $account->id, 'gateway' => GatewayEnum::PAGARME, 'credentials' => ['user' => 'sk_live_PROPRIA'], 'enabled' => true]);

        $this->migrate();

        $this->assertSame('sk_live_PROPRIA', GatewayCredential::withoutGlobalScopes()->firstOrFail()->credentials['user']);
    }

    public function test_nothing_happens_without_env_credentials(): void
    {
        config(['gateways.env_credentials_account' => null, 'integration.user_integration' => null, 'gateways.mercadopago.access_token' => null]);
        Account::factory()->create();

        $this->migrate();

        $this->assertSame(0, GatewayCredential::withoutGlobalScopes()->count());
    }
}
