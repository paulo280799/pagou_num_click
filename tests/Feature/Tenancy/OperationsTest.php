<?php

namespace Tests\Feature\Tenancy;

use App\Enums\GatewayEnum;
use App\Enums\StatusPaymentEnum;
use App\Models\Account;
use App\Models\Config;
use App\Models\GatewayCredential;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\FakesGateways;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use FakesGateways, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gateways.env_credentials_account' => null]);
    }

    private function tenant(string $token): Account
    {
        $account = Account::factory()->create();
        User::factory()->create(['account_id' => $account->id]);
        Config::factory()->create(['account_id' => $account->id, 'api_token' => $token, 'duration' => '900']);
        GatewayCredential::withoutGlobalScopes()->create(['account_id' => $account->id, 'gateway' => GatewayEnum::PAGARME, 'credentials' => ['user' => 'sk_test_'.$token], 'enabled' => true]);

        return $account;
    }

    private function createAs(string $token)
    {
        return $this->postJson('/api/create-payment', $this->validPayload(), ['Authorization' => 'Bearer '.$token]);
    }

    public function test_rate_limit_is_per_account_not_per_ip(): void
    {
        config(['tenancy.api_rate_limit_per_minute' => 2]);
        $this->tenant('tok-a');
        $this->tenant('tok-b');
        Http::fake(['*/v5/orders' => Http::response($this->pagarmePix(), 200)]);

        $this->createAs('tok-a')->assertStatus(201);
        $this->createAs('tok-a')->assertStatus(201);
        $this->createAs('tok-a')->assertStatus(429)->assertHeader('Retry-After');   // A estourou a cota dela
        $this->createAs('tok-b')->assertStatus(201);        // B, no mesmo IP, não foi afetada
    }

    public function test_the_ip_ceiling_is_high_by_default_so_it_does_not_cap_busy_accounts(): void
    {
        $this->assertGreaterThan((int) config('tenancy.api_rate_limit_per_minute'), (int) config('tenancy.api_ip_rate_limit_per_minute'));
    }

    public function test_api_requests_tag_the_logs_with_the_account(): void
    {
        $account = $this->tenant('tok-a');
        Http::fake(['*/v5/orders' => Http::response($this->pagarmePix(), 200)]);
        $log = Log::spy();

        $this->createAs('tok-a')->assertStatus(201);

        $log->shouldHaveReceived('withContext')->with(['account_id' => $account->id]);
    }

    public function test_scheduler_tags_the_logs_with_each_payments_account(): void
    {
        $a = $this->tenant('tok-a');
        $b = $this->tenant('tok-b');
        foreach ([$a, $b] as $i => $acc) {
            Payment::factory()->pending()->create(['account_id' => $acc->id, 'ide' => 'order_'.$i, 'pix_gateway' => GatewayEnum::PAGARME, 'expirationDate' => now()->addMinutes(10)]);
        }
        Http::fake(['*/v5/orders/*' => Http::response(['id' => 'x', 'status' => 'pending', 'charges' => [['status' => 'pending', 'last_transaction' => ['status' => 'waiting_payment']]]], 200)]);
        $log = Log::spy();

        $this->artisan('app:process-payment')->assertSuccessful();

        $log->shouldHaveReceived('withContext')->with(['account_id' => $a->id]);
        $log->shouldHaveReceived('withContext')->with(['account_id' => $b->id]);
        $this->assertSame(StatusPaymentEnum::PENDING, Payment::withoutGlobalScopes()->first()->status);
    }

    public function test_telescope_is_only_for_super_admins(): void
    {
        $owner = User::factory()->create(['account_id' => Account::factory()->create()->id]);
        $admin = User::factory()->create(['account_id' => Account::factory()->create()->id]);
        $admin->forceFill(['is_super_admin' => true])->save();

        $this->assertFalse(Gate::forUser($owner)->allows('viewTelescope'));
        $this->assertTrue(Gate::forUser($admin)->allows('viewTelescope'));
        $this->assertFalse(Gate::forUser(null)->allows('viewTelescope'));
    }

    public function test_telescope_menu_item_is_hidden_from_account_owners(): void
    {
        $this->actingAs(User::factory()->create(['account_id' => Account::factory()->create()->id]))
            ->get('/admin')->assertOk()->assertDontSee('/telescope');
    }
}
