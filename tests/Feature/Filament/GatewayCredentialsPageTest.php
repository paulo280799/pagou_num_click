<?php

namespace Tests\Feature\Filament;

use App\Enums\GatewayEnum;
use App\Filament\Pages\GatewayCredentialsPage;
use App\Models\Account;
use App\Models\GatewayCredential;
use App\Models\Payment;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class GatewayCredentialsPageTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gateways.env_credentials_account' => null, 'gateways.mercadopago.url' => 'https://api.mercadopago.test']);
        $this->account = Account::factory()->create();
        $this->actingAs(User::factory()->create(['account_id' => $this->account->id]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function row(GatewayEnum $gateway): ?GatewayCredential
    {
        return GatewayCredential::withoutGlobalScopes()->where('account_id', $this->account->id)->where('gateway', $gateway->value)->first();
    }

    public function test_page_renders_and_warns_about_missing_redundancy(): void
    {
        $this->get('/admin/gateways')->assertOk()->assertSee('Gateways de pagamento')->assertSee('Sem redundância');
    }

    public function test_saving_pagarme_encrypts_validates_and_never_echoes_the_secret(): void
    {
        Http::fake(['*/v5/orders*' => Http::response(['data' => []], 200)]);

        Livewire::test(GatewayCredentialsPage::class)
            ->fillForm(['pagarme_user' => 'sk_test_SEGREDO9999', 'pagarme_public_key' => 'pk_test_abc'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertSee('••••9999')
            ->assertSee('ambiente de TESTE')
            ->assertDontSee('SEGREDO9999')
            ->assertFormSet(['pagarme_user' => null]);

        $row = $this->row(GatewayEnum::PAGARME);
        $this->assertSame('sk_test_SEGREDO9999', $row->credentials['user']);
        $this->assertSame('pk_test_abc', $row->credentials['public_key']);
        $this->assertNotNull($row->last_validated_at);
    }

    public function test_saved_secrets_show_a_masked_preview_in_their_fields(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $page = Livewire::test(GatewayCredentialsPage::class)
            ->assertSeeHtml('placeholder="Cole a chave secreta"')   // nada salvo ainda
            ->fillForm(['pagarme_user' => 'sk_test_SEGREDO9999', 'pagarme_password' => 'senha-secreta-77', 'pagarme_public_key' => 'pk_test_abc', 'mp_access_token' => 'TEST-mp-token-5555', 'mp_webhook_secret' => 'whsec-abcd'])
            ->call('save');

        $page->assertSeeHtml('placeholder="Salva: ••••9999"')
            ->assertSeeHtml('placeholder="Salva: ••••••••"')          // senha: só "salva", sem caracteres
            ->assertSeeHtml('placeholder="Salva: ••••5555"')
            ->assertSeeHtml('placeholder="Salva: ••••abcd"')
            ->assertDontSee('SEGREDO9999')->assertDontSee('senha-secreta-77')->assertDontSee('mp-token-5555')
            ->assertFormSet(['pagarme_public_key' => 'pk_test_abc']);  // chave pública não é segredo: volta preenchida
    }

    public function test_blank_secret_keeps_the_stored_one(): void
    {
        Http::fake(['*/v5/orders*' => Http::response([], 200)]);
        GatewayCredential::create(['gateway' => GatewayEnum::PAGARME, 'credentials' => ['user' => 'sk_test_KEEP1234'], 'enabled' => true]);

        Livewire::test(GatewayCredentialsPage::class)
            ->fillForm(['pagarme_user' => null, 'pagarme_public_key' => 'pk_test_nova'])
            ->call('save');

        $row = $this->row(GatewayEnum::PAGARME);
        $this->assertSame('sk_test_KEEP1234', $row->credentials['user']);
        $this->assertSame('pk_test_nova', $row->credentials['public_key']);
    }

    public function test_rejected_credential_is_saved_but_marked_as_failed(): void
    {
        Http::fake(['*mercadopago.test/users/me' => Http::response(['message' => 'invalid'], 401)]);

        Livewire::test(GatewayCredentialsPage::class)
            ->fillForm(['mp_access_token' => 'TEST-ruim'])
            ->call('save')
            ->assertSee('última validação falhou');

        $row = $this->row(GatewayEnum::MERCADOPAGO);
        $this->assertNotNull($row);
        $this->assertNull($row->last_validated_at);
        $this->assertNotNull($row->last_validation_error);
    }

    public function test_a_wrong_new_key_never_replaces_the_one_that_works(): void
    {
        Http::fake(['*/v5/orders*' => Http::response(['message' => 'denied'], 401)]);
        GatewayCredential::create(['gateway' => GatewayEnum::PAGARME, 'credentials' => ['user' => 'sk_test_BOA1111'], 'enabled' => true, 'last_validated_at' => now()]);

        Livewire::test(GatewayCredentialsPage::class)->fillForm(['pagarme_user' => 'sk_test_COLADA_ERRADA'])->call('save');

        $row = $this->row(GatewayEnum::PAGARME);
        $this->assertSame('sk_test_BOA1111', $row->credentials['user']);
        $this->assertTrue($row->enabled);
        $this->assertNotNull($row->last_validated_at);
    }

    public function test_a_brand_new_invalid_gateway_is_saved_disabled(): void
    {
        Http::fake(['*mercadopago.test/users/me' => Http::response([], 401)]);

        Livewire::test(GatewayCredentialsPage::class)->fillForm(['mp_access_token' => 'TEST-ruim'])->call('save');

        $this->assertFalse($this->row(GatewayEnum::MERCADOPAGO)->enabled);
        $this->assertFalse(app(\App\Services\Gateways\GatewayManager::class)->forTenant()->hasAnyGateway());
    }

    public function test_cannot_disable_while_an_overdue_pending_pix_or_a_watched_card_pix_depends_on_it(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        GatewayCredential::create(['gateway' => GatewayEnum::PAGARME, 'credentials' => ['user' => 'sk_test_AAAA'], 'enabled' => true]);

        // PENDING já vencido (o scheduler ainda não resolveu): continua dependendo do gateway
        $overdue = Payment::factory()->pending()->create(['account_id' => $this->account->id, 'ide' => 'o1', 'pix_gateway' => GatewayEnum::PAGARME, 'expirationDate' => now()->subMinutes(2)]);
        Livewire::test(GatewayCredentialsPage::class)->fillForm(['pagarme_enabled' => false])->call('save');
        $this->assertTrue($this->row(GatewayEnum::PAGARME)->enabled);
        $overdue->forceFill(['status' => 'CANCELED'])->save();

        // PIX remanescente de cartão pago, ainda vigiado
        Payment::factory()->paid()->create(['account_id' => $this->account->id, 'ide' => 'o2', 'pix_gateway' => GatewayEnum::PAGARME, 'payment_method' => 'CREDIT_CARD', 'expirationDate' => now()->addMinutes(3)]);
        Livewire::test(GatewayCredentialsPage::class)->fillForm(['pagarme_enabled' => false])->call('save');
        $this->assertTrue($this->row(GatewayEnum::PAGARME)->enabled);
    }

    public function test_both_gateways_turn_redundancy_on(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        Livewire::test(GatewayCredentialsPage::class)
            ->fillForm(['pagarme_user' => 'sk_test_AAAA', 'mp_access_token' => 'TEST-mp'])
            ->call('save');

        $this->get('/admin/gateways')->assertSee('Redundância ativa');
    }

    public function test_cannot_disable_a_gateway_with_open_payments(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        GatewayCredential::create(['gateway' => GatewayEnum::PAGARME, 'credentials' => ['user' => 'sk_test_AAAA'], 'enabled' => true]);
        Payment::factory()->pending()->create(['account_id' => $this->account->id, 'ide' => 'o1', 'pix_gateway' => GatewayEnum::PAGARME, 'expirationDate' => now()->addMinutes(10)]);

        Livewire::test(GatewayCredentialsPage::class)->fillForm(['pagarme_enabled' => false])->call('save');

        $this->assertTrue($this->row(GatewayEnum::PAGARME)->enabled);
    }

    public function test_can_disable_when_nothing_is_open(): void
    {
        Http::fake();
        GatewayCredential::create(['gateway' => GatewayEnum::PAGARME, 'credentials' => ['user' => 'sk_test_AAAA'], 'enabled' => true]);

        Livewire::test(GatewayCredentialsPage::class)->fillForm(['pagarme_enabled' => false])->call('save');

        $this->assertFalse($this->row(GatewayEnum::PAGARME)->enabled);
    }

    public function test_page_shows_the_webhook_url_of_this_account_only(): void
    {
        $other = Account::factory()->create();

        $this->get('/admin/gateways')
            ->assertSee('/api/webhooks/mercadopago/'.$this->account->id, false)
            ->assertDontSee($other->id);
    }

    public function test_one_account_never_sees_or_edits_another_accounts_credentials(): void
    {
        $other = Account::factory()->create();
        GatewayCredential::withoutGlobalScopes()->create(['account_id' => $other->id, 'gateway' => GatewayEnum::PAGARME, 'credentials' => ['user' => 'sk_test_OUTRA7777'], 'enabled' => true]);

        $this->get('/admin/gateways')->assertDontSee('7777');

        Http::fake(['*' => Http::response([], 200)]);
        Livewire::test(GatewayCredentialsPage::class)->fillForm(['pagarme_user' => 'sk_test_MINHA1111'])->call('save');

        $this->assertSame('sk_test_OUTRA7777', GatewayCredential::withoutGlobalScopes()->where('account_id', $other->id)->first()->credentials['user']);
        $this->assertSame('sk_test_MINHA1111', $this->row(GatewayEnum::PAGARME)->credentials['user']);
    }
}
