<?php

namespace Tests\Feature\Platform;

use App\Enums\GatewayEnum;
use App\Filament\Platform\Resources\PlatformAccountResource\Pages\CreatePlatformAccount;
use App\Filament\Platform\Resources\PlatformAccountResource\Pages\ListPlatformAccounts;
use App\Models\Account;
use App\Models\Config;
use App\Models\GatewayCredential;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\AccountInvitation;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\FakesGateways;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    use FakesGateways, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gateways.env_credentials_account' => null]);
        $this->admin = User::factory()->create(['account_id' => Account::factory()->create(['name' => 'Plataforma'])->id]);
        $this->admin->forceFill(['is_super_admin' => true, 'password_set_at' => now()])->save();
    }

    private function asAdmin(): void
    {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('platform'));
    }

    public function test_only_super_admins_reach_the_platform_panel(): void
    {
        $this->get('/platform')->assertRedirect('/platform/login');

        $this->actingAs(User::factory()->create(['account_id' => Account::factory()->create()->id]))
            ->get('/platform/accounts')->assertForbidden();

        $this->actingAs($this->admin)->get('/platform/accounts')->assertOk()->assertSee('Contas');
    }

    public function test_super_admin_flag_cannot_be_mass_assigned(): void
    {
        $user = User::create(['name' => 'X', 'email' => 'x@example.test', 'password' => 'senha-forte-1', 'account_id' => $this->admin->account_id, 'is_super_admin' => true]);

        $this->assertFalse($user->fresh()->is_super_admin);
    }

    public function test_account_owners_still_use_the_account_panel_and_super_admins_too(): void
    {
        $this->actingAs(User::factory()->create(['account_id' => Account::factory()->create()->id]))->get('/admin')->assertOk();
        $this->actingAs($this->admin)->get('/admin')->assertOk();
    }

    public function test_creating_an_account_invites_the_owner(): void
    {
        Notification::fake();
        $this->asAdmin();

        Livewire::test(CreatePlatformAccount::class)
            ->fillForm(['name' => 'Loja Nova', 'owner_name' => 'Maria', 'owner_email' => 'maria@loja.test'])
            ->call('create')
            ->assertHasNoFormErrors();

        $account = Account::where('name', 'Loja Nova')->firstOrFail();
        $owner = User::where('email', 'maria@loja.test')->firstOrFail();
        $this->assertSame($account->id, $owner->account_id);
        $this->assertNull($owner->password_set_at);
        $this->assertFalse($owner->is_super_admin);
        Notification::assertSentTo($owner, AccountInvitation::class);
        $this->assertSame(0, Config::withoutGlobalScopes()->where('account_id', $account->id)->count());
    }

    public function test_owner_email_must_be_unique(): void
    {
        $this->asAdmin();

        Livewire::test(CreatePlatformAccount::class)
            ->fillForm(['name' => 'Outra', 'owner_name' => 'Fulano', 'owner_email' => $this->admin->email])
            ->call('create')
            ->assertHasFormErrors(['owner_email']);
    }

    public function test_invitation_lets_the_owner_set_the_password_once(): void
    {
        $owner = User::factory()->create(['account_id' => Account::factory()->create()->id]);
        $owner->forceFill(['password_set_at' => null])->save();
        $url = AccountInvitation::urlFor($owner);

        $this->get($url)->assertOk()->assertSee('Defina sua senha');

        $this->post($url, ['password' => 'senha-bem-forte', 'password_confirmation' => 'senha-bem-forte'])->assertRedirect('/admin');

        $this->assertAuthenticatedAs($owner);
        $owner->refresh();
        $this->assertNotNull($owner->password_set_at);
        $this->assertTrue(Hash::check('senha-bem-forte', $owner->password));

        // uso único
        $this->get($url)->assertStatus(410);
        $this->post($url, ['password' => 'outra-senha-forte', 'password_confirmation' => 'outra-senha-forte'])->assertStatus(410);
        $this->assertTrue(Hash::check('senha-bem-forte', $owner->fresh()->password));
    }

    public function test_invitation_rejects_tampered_expired_and_weak_passwords(): void
    {
        $owner = User::factory()->create(['account_id' => Account::factory()->create()->id]);
        $owner->forceFill(['password_set_at' => null])->save();
        $url = AccountInvitation::urlFor($owner);

        $this->get(route('invitation.show', $owner))->assertForbidden();                       // sem assinatura
        $this->get($url.'x')->assertForbidden();                                               // assinatura adulterada
        $this->post($url, ['password' => 'curta', 'password_confirmation' => 'curta'])->assertSessionHasErrors('password');
        $this->post($url, ['password' => 'senha-bem-forte', 'password_confirmation' => 'diferente'])->assertSessionHasErrors('password');
        $this->assertNull($owner->fresh()->password_set_at);

        $this->travel(AccountInvitation::VALID_DAYS + 1)->days();
        $this->get($url)->assertForbidden();                                                   // expirado
    }

    public function test_listing_shows_status_and_numbers_of_every_account_without_tenant_scope(): void
    {
        $pending = Account::factory()->create(['name' => 'Conta Pendente']);
        $owner = User::factory()->create(['account_id' => $pending->id]);
        $owner->forceFill(['password_set_at' => null])->save();
        $active = Account::factory()->create(['name' => 'Conta Ativa']);
        User::factory()->create(['account_id' => $active->id])->forceFill(['password_set_at' => now()])->save();
        Payment::factory()->paid()->create(['account_id' => $active->id, 'amount' => 150]);
        $this->asAdmin();

        $this->get('/platform/accounts')
            ->assertOk()->assertSee('Conta Pendente')->assertSee('Convite pendente')->assertSee('Conta Ativa')->assertSee('R$')->assertSee('150');
    }

    public function test_suspended_account_gets_403_on_the_api_and_reactivation_restores_it(): void
    {
        $account = Account::factory()->create();
        User::factory()->create(['account_id' => $account->id]);
        Config::factory()->create(['account_id' => $account->id, 'api_token' => 'tok-s', 'duration' => '900']);
        GatewayCredential::withoutGlobalScopes()->create(['account_id' => $account->id, 'gateway' => GatewayEnum::PAGARME, 'credentials' => ['user' => 'sk_test_X'], 'enabled' => true]);
        Http::fake(['*/v5/orders' => Http::response($this->pagarmePix(), 200)]);
        $call = fn () => $this->postJson('/api/create-payment', $this->validPayload(), ['Authorization' => 'Bearer tok-s']);
        $this->asAdmin();

        Livewire::test(ListPlatformAccounts::class)->callTableAction('suspend', $account->id);
        $this->assertNotNull($account->fresh()->suspended_at);
        $call()->assertForbidden()->assertJson(['error' => 'Account suspended']);
        Http::assertNothingSent();

        Livewire::test(ListPlatformAccounts::class)->callTableAction('reactivate', $account->id);
        $this->assertNull($account->fresh()->suspended_at);
        $call()->assertStatus(201);
    }

    public function test_checkouts_already_created_keep_working_for_a_suspended_account(): void
    {
        $account = Account::factory()->create(['suspended_at' => now()]);
        $payment = Payment::factory()->pending()->create(['account_id' => $account->id, 'expirationDate' => now()->addMinutes(10)]);

        $this->get('/checkout/'.$payment->id)->assertOk();
    }

    public function test_a_super_admin_cannot_suspend_their_own_account(): void
    {
        $this->asAdmin();

        Livewire::test(ListPlatformAccounts::class)->assertTableActionHidden('suspend', $this->admin->account);
    }

    public function test_suspended_account_sees_a_banner_in_its_panel(): void
    {
        $account = Account::factory()->create(['suspended_at' => now()]);

        $this->actingAs(User::factory()->create(['account_id' => $account->id]))
            ->get('/admin')->assertOk()->assertSee('Esta conta está suspensa');
    }

    public function test_make_admin_command_promotes_existing_and_creates_new_users(): void
    {
        $existing = User::factory()->create(['account_id' => Account::factory()->create()->id]);

        $this->artisan('platform:make-admin', ['email' => $existing->email])->assertSuccessful();
        $this->assertTrue($existing->fresh()->is_super_admin);

        $this->artisan('platform:make-admin', ['email' => 'novo@plataforma.test', '--password' => 'senha-forte-123'])->assertSuccessful();
        $new = User::where('email', 'novo@plataforma.test')->firstOrFail();
        $this->assertTrue($new->is_super_admin);
        $this->assertTrue(Hash::check('senha-forte-123', $new->password));
        $this->assertNotNull($new->password_set_at);
    }
}
