<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\AccountResource\Pages\EditAccount;
use App\Filament\Resources\AccountResource\RelationManagers\UsersRelationManager;
use App\Models\Account;
use App\Models\Config;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class AccountPageTest extends TestCase
{
    use RefreshDatabase;

    private function actAs(): array
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return [$account, $user];
    }

    public function test_edit_page_loads(): void
    {
        [$account, $user] = $this->actAs();

        $this->get("/admin/accounts/{$account->id}/edit")->assertOk()->assertSee('Minha conta');
    }

    public function test_panel_brand_shows_account_name(): void
    {
        [$account] = $this->actAs();
        $account->update(['name' => 'Loja Brand']);
        $this->actingAs($account->users()->first()->fresh());

        $this->assertSame('Loja Brand', Filament::getBrandName());
    }

    public function test_panel_brand_falls_back_to_app_name_for_guests(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->assertSame(config('app.name'), Filament::getBrandName());
    }

    public function test_other_account_is_not_reachable_by_uuid(): void
    {
        [, $user] = $this->actAs();
        $other = Account::factory()->create();

        $this->get("/admin/accounts/{$other->id}/edit")->assertNotFound();
    }

    public function test_profile_fields_are_saved_with_digits_only(): void
    {
        [$account] = $this->actAs();

        Livewire::test(EditAccount::class, ['record' => $account->id])
            ->fillForm([
                'name' => 'Loja X',
                'legal_name' => 'Loja X LTDA',
                'document' => '12.345.678/0001-95',
                'phone' => '(11) 98765-4321',
                'contact_email' => 'contato@lojax.test',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $account->refresh();
        $this->assertSame('Loja X LTDA', $account->legal_name);
        $this->assertSame('12345678000195', $account->document);
        $this->assertSame('11987654321', $account->phone);
        $this->assertSame('contato@lojax.test', $account->contact_email);
    }

    public function test_phone_is_shown_masked_when_loaded(): void
    {
        [$account] = $this->actAs();
        $account->update(['phone' => '88998534446']);

        Livewire::test(EditAccount::class, ['record' => $account->id])
            ->assertFormSet(['phone' => '(88) 99853-4446']);
    }

    public function test_invalid_document_and_phone_are_rejected(): void
    {
        [$account] = $this->actAs();

        Livewire::test(EditAccount::class, ['record' => $account->id])
            ->fillForm(['name' => 'Loja', 'document' => '123', 'phone' => '99', 'contact_email' => 'nao-e-email'])
            ->call('save')
            ->assertHasFormErrors(['document', 'phone', 'contact_email']);
    }

    public function test_change_password_requires_current_password(): void
    {
        [$account, $user] = $this->actAs();

        Livewire::test(EditAccount::class, ['record' => $account->id])
            ->callAction('changePassword', [
                'current_password' => 'errada',
                'password' => 'novasenha123',
                'password_confirmation' => 'novasenha123',
            ])
            ->assertHasActionErrors(['current_password']);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_change_password_succeeds(): void
    {
        [$account, $user] = $this->actAs();

        Livewire::test(EditAccount::class, ['record' => $account->id])
            ->callAction('changePassword', [
                'current_password' => 'password',
                'password' => 'novasenha123',
                'password_confirmation' => 'novasenha123',
            ])
            ->assertHasNoActionErrors();

        $this->assertTrue(Hash::check('novasenha123', $user->fresh()->password));
    }

    public function test_users_relation_manager_creates_user_in_same_account(): void
    {
        [$account] = $this->actAs();

        Livewire::test(UsersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
            ->callTableAction('create', data: [
                'name' => 'Novo',
                'email' => 'novo@test.com',
                'password' => 'senhaforte123',
            ])
            ->assertHasNoTableActionErrors();

        $created = User::where('email', 'novo@test.com')->first();
        $this->assertSame($account->id, $created->account_id);
        $this->assertTrue(Hash::check('senhaforte123', $created->password));
    }

    public function test_cannot_delete_self_or_last_user(): void
    {
        [$account, $user] = $this->actAs();

        Livewire::test(UsersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
            ->callTableAction('delete', $user);

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_can_delete_another_user(): void
    {
        [$account, $user] = $this->actAs();
        $other = User::factory()->create(['account_id' => $account->id]);

        Livewire::test(UsersRelationManager::class, ['ownerRecord' => $account, 'pageClass' => EditAccount::class])
            ->callTableAction('delete', $other);

        $this->assertDatabaseMissing('users', ['id' => $other->id]);
    }
}
