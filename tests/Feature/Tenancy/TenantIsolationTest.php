<?php

namespace Tests\Feature\Tenancy;

use App\Models\Account;
use App\Models\Config;
use App\Models\Payment;
use App\Models\User;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Account $a;

    private Account $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Account::factory()->create(['name' => 'Conta A']);
        $this->b = Account::factory()->create(['name' => 'Conta B']);
        Config::withoutGlobalScopes()->forceCreate(Config::factory()->make(['account_id' => $this->a->id, 'api_token' => 'token-a', 'duration' => '900'])->getAttributes() + ['account_id' => $this->a->id]);
        Config::withoutGlobalScopes()->forceCreate(Config::factory()->make(['account_id' => $this->b->id, 'api_token' => 'token-b', 'duration' => '900'])->getAttributes() + ['account_id' => $this->b->id]);
    }

    private function payment(Account $account, array $attrs = []): Payment
    {
        return Payment::factory()->pending()->create(['account_id' => $account->id] + $attrs);
    }

    public function test_scope_shows_only_the_logged_users_account(): void
    {
        $pa = $this->payment($this->a);
        $pb = $this->payment($this->b);
        $this->actingAs(User::factory()->create(['account_id' => $this->a->id]));

        $this->assertSame([$pa->id], Payment::pluck('id')->all());
        $this->assertNull(Payment::find($pb->id));
        $this->assertSame($this->a->id, Config::firstOrFail()->account_id);
    }

    public function test_scope_fails_closed_without_a_tenant(): void
    {
        $this->payment($this->a);

        $this->assertGuest();
        $this->assertNull(app(TenantContext::class)->accountId());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, Config::count());
        $this->assertSame(1, Payment::withoutGlobalScopes()->count());
    }

    public function test_explicit_tenant_context_takes_precedence_over_the_session(): void
    {
        $pb = $this->payment($this->b);
        $this->actingAs(User::factory()->create(['account_id' => $this->a->id]));

        app(TenantContext::class)->set($this->b->id);

        $this->assertSame([$pb->id], Payment::pluck('id')->all());
    }

    public function test_api_token_creates_payments_in_its_own_account_only(): void
    {
        Http::fake(['*/v5/orders' => Http::response([
            'id' => 'order_1', 'amount' => 1050, 'status' => 'pending',
            'charges' => [['payment_method' => 'pix', 'last_transaction' => ['qr_code_url' => 'https://qr.test', 'qr_code' => 'cp']]],
        ], 200)]);

        $payload = fn (string $ref) => [
            'refExternal' => $ref,
            'items' => [['amount' => '10.50', 'description' => 'Item', 'quantity' => 1]],
            'customer' => [
                'name' => 'Fulano', 'email' => 'f@example.test', 'type' => 'individual', 'document' => '12345678900',
                'phones' => ['home_phone' => ['country_code' => '55', 'area_code' => '11', 'number' => '999999999']],
            ],
        ];

        $this->postJson('/api/create-payment', $payload('mesmo-ref'), ['Authorization' => 'Bearer token-a'])->assertStatus(201);
        // o mesmo refExternal em outra conta não conflita; na mesma conta, sim
        $this->postJson('/api/create-payment', $payload('mesmo-ref'), ['Authorization' => 'Bearer token-b'])->assertStatus(201);
        $this->postJson('/api/create-payment', $payload('mesmo-ref'), ['Authorization' => 'Bearer token-a'])->assertStatus(422);

        $this->assertSame(1, Payment::withoutGlobalScopes()->where('account_id', $this->a->id)->count());
        $this->assertSame(1, Payment::withoutGlobalScopes()->where('account_id', $this->b->id)->count());
    }

    public function test_panel_lists_only_own_payments_and_account(): void
    {
        $this->payment($this->a, ['refExternal' => 'pedido-da-a']);
        $this->payment($this->b, ['refExternal' => 'pedido-da-b']);
        $this->actingAs(User::factory()->create(['account_id' => $this->a->id]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->get('/admin/payments')->assertOk()->assertSee('pedido-da-a')->assertDontSee('pedido-da-b');
        $this->get('/admin/accounts/'.$this->a->id.'/edit')->assertOk()->assertDontSee('Conta B');
    }

    public function test_panel_cannot_open_another_accounts_account_or_payment_by_id(): void
    {
        $pb = $this->payment($this->b);
        $this->actingAs(User::factory()->create(['account_id' => $this->a->id]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->get('/admin/accounts/'.$this->b->id.'/edit')->assertNotFound();
        $this->get('/admin/payments/'.$pb->id)->assertNotFound();
    }
}
