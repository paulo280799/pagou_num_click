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
}
