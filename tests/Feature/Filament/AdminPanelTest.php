<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Models\Account;
use App\Models\Config;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_login_page_loads(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_authenticated_user_can_access_payments_list(): void
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);
        Payment::factory()->create(['account_id' => $account->id]);

        $this->actingAs($user)
            ->get('/admin/payments')
            ->assertOk();
    }

    public function test_payment_view_action_opens_without_error(): void
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);
        $payment = Payment::factory()->create(['account_id' => $account->id, 'amount' => 100]);
        $this->actingAs($user);

        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));

        Livewire::test(ListPayments::class)
            ->callTableAction('view', $payment)
            ->assertHasNoTableActionErrors();
    }

    public function test_account_create_redirects_to_edit_when_account_already_exists(): void
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);

        $this->actingAs($user)
            ->get('/admin/accounts/create')
            ->assertRedirect("/admin/accounts/{$account->id}/edit");
    }

    public function test_account_edit_page_loads_for_owning_user(): void
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);

        $this->actingAs($user)
            ->get("/admin/accounts/{$account->id}/edit")
            ->assertOk();
    }

    public function test_config_create_redirects_to_edit_when_config_already_exists(): void
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);
        $config = Config::factory()->create(['account_id' => $account->id]);

        $this->actingAs($user)
            ->get('/admin/configs/create')
            ->assertRedirect("/admin/configs/{$config->id}/edit");
    }

    public function test_config_edit_page_loads_for_owning_user(): void
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);
        $config = Config::factory()->create(['account_id' => $account->id]);

        $this->actingAs($user)
            ->get("/admin/configs/{$config->id}/edit")
            ->assertOk();
    }

    public function test_config_edit_page_rejects_user_from_another_account(): void
    {
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $otherUser = User::factory()->create(['account_id' => $otherAccount->id]);
        $config = Config::factory()->create(['account_id' => $account->id]);

        $this->actingAs($otherUser)
            ->get("/admin/configs/{$config->id}/edit")
            ->assertStatus(500);
    }

    private function configEditor(array $configAttrs = [])
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);
        $config = Config::factory()->create(['account_id' => $account->id] + $configAttrs);
        $this->actingAs($user);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));

        return [$config, \Livewire\Livewire::test(\App\Filament\Resources\ConfigResource\Pages\EditConfig::class, ['record' => $config->id])];
    }

    public function test_config_regenerate_token_replaces_api_token(): void
    {
        [$config, $form] = $this->configEditor();
        $config->forceFill(['api_token' => 'token-antigo'])->save();

        $form->callFormComponentAction('integration', 'regenerateToken');

        $new = $config->fresh()->api_token;
        $this->assertNotSame('token-antigo', $new);
        $this->assertSame(30, strlen($new));
    }

    public function test_config_form_saves_installment_settings(): void
    {
        [$config, $form] = $this->configEditor();

        $form->assertFormSet(['card_installments_enabled' => true, 'card_max_installments' => 12])
            ->fillForm(['card_installments_enabled' => true, 'card_max_installments' => 6])
            ->call('save')
            ->assertHasNoFormErrors();

        $config->refresh();
        $this->assertTrue($config->card_installments_enabled);
        $this->assertSame(6, $config->card_max_installments);
    }

    public function test_config_form_can_disable_installments(): void
    {
        [$config, $form] = $this->configEditor();

        $form->fillForm(['card_installments_enabled' => false])->call('save')->assertHasNoFormErrors();

        $this->assertFalse($config->refresh()->card_installments_enabled);
        $this->assertSame(1, $config->effectiveMaxInstallments());
    }

    /** @dataProvider invalidInstallmentLimits */
    public function test_config_form_rejects_installment_limit_out_of_range(mixed $value): void
    {
        [$config, $form] = $this->configEditor();

        $form->fillForm(['card_installments_enabled' => true, 'card_max_installments' => $value])
            ->call('save')
            ->assertHasFormErrors(['card_max_installments']);

        $this->assertSame(12, $config->refresh()->card_max_installments);
    }

    public static function invalidInstallmentLimits(): array
    {
        return ['zero' => [0], 'treze' => [13], 'vazio' => [null]];
    }

    private function viewPayment(string $state, array $paymentAttrs, array $configAttrs = [])
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);
        Config::factory()->create(['account_id' => $account->id] + $configAttrs);
        $payment = Payment::factory()->{$state}()->create(['account_id' => $account->id] + $paymentAttrs);
        $this->actingAs($user);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));

        return \Livewire\Livewire::test(\App\Filament\Resources\PaymentResource\Pages\ListPayments::class)
            ->mountTableAction('view', $payment);
    }

    public function test_payment_details_show_pix_method_and_fee(): void
    {
        $this->viewPayment('paid', ['amount' => 100, 'payment_methods' => ['pix'], 'fee_percent' => 0.75, 'fee_amount' => 0.75])
            ->assertSee('Meio de pagamento')
            ->assertSee('Pix')
            ->assertSee('0,75%')
            ->assertSee('R$ 0,75')
            ->assertSee('R$ 99,25')
            ->assertDontSee('Parcelamento');
    }

    public function test_payment_details_show_card_installments_brand_and_fee(): void
    {
        $this->viewPayment('cardPaid', [
            'amount' => 300, 'installments' => 3, 'card_brand' => 'Visa', 'card_last_four' => '1111',
            'fee_percent' => 4.5, 'fee_amount' => 13.5,
        ])
            ->assertSee('Cartão de crédito')
            ->assertSee('Visa final 1111')
            ->assertSee('3x de R$ 100,00 sem juros')
            ->assertSee('4,50%')
            ->assertSee('R$ 13,50')
            ->assertSee('R$ 286,50');
    }

    public function test_payment_details_show_single_payment_for_card_in_one_installment(): void
    {
        $this->viewPayment('cardPaid', ['amount' => 100, 'installments' => 1, 'fee_percent' => 3.5, 'fee_amount' => 3.5])
            ->assertSee('À vista');
    }

    public function test_payment_details_card_without_configured_fee(): void
    {
        $this->viewPayment('cardPaid', ['amount' => 100, 'installments' => 1])
            ->assertSee('Não configurada')
            ->assertSee('R$ 100,00');
    }

    public function test_pending_payment_with_both_methods_shows_estimated_pix_fee(): void
    {
        $this->viewPayment('pending', ['amount' => 100, 'payment_methods' => ['pix', 'credit_card']], ['pix_fee_percent' => 2])
            ->assertSee('Pix ou cartão (a definir)')
            ->assertSee('2,00% (estimada)');
    }

    public function test_config_form_saves_fee_settings(): void
    {
        [$config, $form] = $this->configEditor();

        $form->assertFormSet(['pix_fee_percent' => 0.75, 'card_fee_percent_1x' => null])
            ->fillForm(['pix_fee_percent' => 0.99, 'card_fee_percent_1x' => 3.5, 'card_fee_percent_2_6x' => 4.5, 'card_fee_percent_7_12x' => 5.5])
            ->call('save')
            ->assertHasNoFormErrors();

        $config->refresh();
        $this->assertSame(0.99, $config->pix_fee_percent);
        $this->assertSame(3.5, $config->card_fee_percent_1x);
        $this->assertSame(5.5, $config->card_fee_percent_7_12x);
    }

    public function test_config_form_rejects_invalid_fee(): void
    {
        [$config, $form] = $this->configEditor();

        $form->fillForm(['pix_fee_percent' => 101, 'card_fee_percent_1x' => -1])
            ->call('save')
            ->assertHasFormErrors(['pix_fee_percent', 'card_fee_percent_1x']);
    }
}
