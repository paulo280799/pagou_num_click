<?php

namespace Tests\Feature\Platform;

use App\Filament\Platform\Resources\PlatformAdminResource\Pages\CreatePlatformAdmin;
use App\Filament\Platform\Resources\PlatformAdminResource\Pages\EditPlatformAdmin;
use App\Filament\Platform\Resources\PlatformAdminResource\Pages\ListPlatformAdmins;
use App\Models\Account;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class PlatformAdminsTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = $this->superAdmin('Eu Mesmo', 'eu@plataforma.test');
        $this->actingAs($this->me);
        Filament::setCurrentPanel(Filament::getPanel('platform'));
    }

    private function superAdmin(string $name, string $email): User
    {
        $user = User::factory()->create(['name' => $name, 'email' => $email, 'account_id' => Account::firstOrCreate(['name' => 'Plataforma'])->id]);

        $user->forceFill(['is_super_admin' => true])->save();

        return $user;
    }

    public function test_list_shows_only_super_admins_and_marks_the_current_user(): void
    {
        $owner = User::factory()->create(['name' => 'Dono Cliente', 'account_id' => Account::factory()->create()->id]);
        $other = $this->superAdmin('Outro Admin', 'outro@plataforma.test');

        $this->get('/platform/administradores')->assertOk()
            ->assertSee('Outro Admin')->assertSee('Eu Mesmo')->assertSee('Você')->assertDontSee('Dono Cliente');

        Livewire::test(ListPlatformAdmins::class)->assertCanSeeTableRecords([$this->me, $other])->assertCanNotSeeTableRecords([$owner]);
    }

    public function test_only_super_admins_reach_the_admins_crud(): void
    {
        $this->actingAs(User::factory()->create(['account_id' => Account::factory()->create()->id]))
            ->get('/platform/administradores')->assertForbidden();
    }

    public function test_creating_an_admin_sets_the_flag_in_the_platform_account_and_hashes_the_password(): void
    {
        Livewire::test(CreatePlatformAdmin::class)
            ->fillForm(['name' => 'Novo Admin', 'email' => 'novo@plataforma.test', 'password' => 'senha-forte-123', 'passwordConfirmation' => 'senha-forte-123'])
            ->call('create')->assertHasNoFormErrors();

        $new = User::where('email', 'novo@plataforma.test')->firstOrFail();
        $this->assertTrue($new->is_super_admin);
        $this->assertSame('Plataforma', $new->account->name);
        $this->assertTrue(Hash::check('senha-forte-123', $new->password));
        $this->assertNotNull($new->email_verified_at);
        $this->assertFalse($new->invitation_pending);
    }

    public function test_the_new_admin_can_actually_sign_in_to_the_platform_panel(): void
    {
        Livewire::test(CreatePlatformAdmin::class)
            ->fillForm(['name' => 'Novo Admin', 'email' => 'novo@plataforma.test', 'password' => 'senha-forte-123', 'passwordConfirmation' => 'senha-forte-123'])
            ->call('create');

        $this->actingAs(User::where('email', 'novo@plataforma.test')->first())->get('/platform/accounts')->assertOk();
    }

    public function test_validation_rejects_duplicate_email_short_and_mismatched_passwords(): void
    {
        Livewire::test(CreatePlatformAdmin::class)
            ->fillForm(['name' => 'X', 'email' => 'eu@plataforma.test', 'password' => 'curta', 'passwordConfirmation' => 'outra'])
            ->call('create')
            ->assertHasFormErrors(['email', 'password']);

        Livewire::test(CreatePlatformAdmin::class)
            ->fillForm(['name' => 'X', 'email' => 'x@plataforma.test', 'password' => 'senha-forte-123', 'passwordConfirmation' => 'diferente-123'])
            ->call('create')->assertHasFormErrors(['password']);

        $this->assertNull(User::where('email', 'x@plataforma.test')->first());
    }

    public function test_the_email_of_a_customer_user_cannot_be_reused(): void
    {
        User::factory()->create(['email' => 'cliente@loja.test', 'account_id' => Account::factory()->create()->id]);

        Livewire::test(CreatePlatformAdmin::class)
            ->fillForm(['name' => 'X', 'email' => 'cliente@loja.test', 'password' => 'senha-forte-123', 'passwordConfirmation' => 'senha-forte-123'])
            ->call('create')->assertHasFormErrors(['email']);
    }

    public function test_editing_keeps_the_password_when_left_blank_and_changes_it_when_filled(): void
    {
        $other = $this->superAdmin('Outro Admin', 'outro@plataforma.test');
        $other->forceFill(['password' => 'senha-original-1'])->save();

        Livewire::test(EditPlatformAdmin::class, ['record' => $other->id])
            ->fillForm(['name' => 'Renomeado', 'password' => '', 'passwordConfirmation' => ''])->call('save')->assertHasNoFormErrors();
        $other->refresh();
        $this->assertSame('Renomeado', $other->name);
        $this->assertTrue(Hash::check('senha-original-1', $other->password));

        Livewire::test(EditPlatformAdmin::class, ['record' => $other->id])
            ->fillForm(['password' => 'senha-nova-456', 'passwordConfirmation' => 'senha-nova-456'])->call('save')->assertHasNoFormErrors();
        $this->assertTrue(Hash::check('senha-nova-456', $other->fresh()->password));
        $this->assertTrue($other->fresh()->is_super_admin, 'editar nunca mexe na flag');
    }

    public function test_a_customer_user_cannot_be_opened_or_edited_through_this_crud(): void
    {
        $owner = User::factory()->create(['account_id' => Account::factory()->create()->id]);

        $this->get('/platform/administradores/'.$owner->id.'/edit')->assertNotFound();
    }

    public function test_an_admin_can_remove_another_admin_but_never_themselves(): void
    {
        $other = $this->superAdmin('Outro Admin', 'outro@plataforma.test');

        Livewire::test(ListPlatformAdmins::class)
            ->assertTableActionHidden('delete', $this->me)
            ->callTableAction('delete', $other);

        $this->assertNull(User::find($other->id));
        $this->assertNotNull(User::find($this->me->id));
    }

    public function test_the_last_admin_cannot_be_removed(): void
    {
        // dois admins; o outro remove o "eu" até sobrar um: o último não sai
        $other = $this->superAdmin('Outro Admin', 'outro@plataforma.test');
        $this->actingAs($other);

        Livewire::test(ListPlatformAdmins::class)->callTableAction('delete', $this->me);
        $this->assertSame(1, User::where('is_super_admin', true)->count());

        // agora só resta "other" (a própria pessoa logada): sem ação de remover
        Livewire::test(ListPlatformAdmins::class)->assertTableActionHidden('delete', $other);
    }
}
