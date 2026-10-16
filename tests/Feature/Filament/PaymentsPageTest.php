<?php

namespace Tests\Feature\Filament;

use App\Enums\PaymentMethodEnum;
use App\Enums\StatusPaymentEnum;
use App\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Models\Account;
use App\Models\Payment;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentsPageTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::factory()->create();
        $this->actingAs(User::factory()->create(['account_id' => $this->account->id]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function make(string $state, array $attrs = []): Payment
    {
        return Payment::factory()->{$state}()->create(['account_id' => $this->account->id] + $attrs);
    }

    public function test_list_page_is_translated_and_shows_columns(): void
    {
        $this->make('pending', ['refExternal' => 'pedido-1']);

        $this->get('/admin/payments')
            ->assertOk()
            ->assertSee('Pagamentos')
            ->assertSee('Referência')
            ->assertSee('Criado em');
    }

    public function test_tabs_filter_by_status(): void
    {
        $pending = $this->make('pending');
        $paid = $this->make('paid');
        $canceled = $this->make('pending', ['status' => StatusPaymentEnum::CANCELED]);
        $failed = $this->make('pending', ['status' => StatusPaymentEnum::FAILED]);

        Livewire::test(ListPayments::class)
            ->assertCanSeeTableRecords([$pending, $paid, $canceled, $failed])
            ->set('activeTab', 'pending')
            ->assertCanSeeTableRecords([$pending])
            ->assertCanNotSeeTableRecords([$paid, $canceled, $failed])
            ->set('activeTab', 'paid')
            ->assertCanSeeTableRecords([$paid])
            ->assertCanNotSeeTableRecords([$pending, $canceled])
            ->set('activeTab', 'canceled')
            ->assertCanSeeTableRecords([$canceled, $failed])
            ->assertCanNotSeeTableRecords([$pending, $paid]);
    }

    public function test_tab_badges_count_only_own_account(): void
    {
        $this->make('pending');
        $this->make('paid');
        Payment::factory()->create(['account_id' => Account::factory()->create()->id]);

        $tabs = Livewire::test(ListPayments::class)->instance()->getTabs();

        $this->assertSame(2, $tabs['all']->getBadge());
        $this->assertSame(1, $tabs['pending']->getBadge());
        $this->assertSame(1, $tabs['paid']->getBadge());
        $this->assertSame(0, $tabs['canceled']->getBadge());
    }

    public function test_search_by_external_reference(): void
    {
        $a = $this->make('pending', ['refExternal' => 'pedido-alfa']);
        $b = $this->make('pending', ['refExternal' => 'pedido-beta']);

        Livewire::test(ListPayments::class)
            ->searchTable('alfa')
            ->assertCanSeeTableRecords([$a])
            ->assertCanNotSeeTableRecords([$b]);
    }

    public function test_filters_by_method_amount_and_date(): void
    {
        $pix = $this->make('paid', ['amount' => 50, 'payment_method' => PaymentMethodEnum::PIX]);
        $card = $this->make('cardPaid', ['amount' => 500]);
        $old = $this->make('paid', ['amount' => 50, 'created_at' => now()->subDays(10)]);

        Livewire::test(ListPayments::class)
            ->filterTable('payment_method', PaymentMethodEnum::CREDIT_CARD->value)
            ->assertCanSeeTableRecords([$card])
            ->assertCanNotSeeTableRecords([$pix, $old])
            ->resetTableFilters()
            ->filterTable('amount', ['amount_from' => 100, 'amount_until' => null])
            ->assertCanSeeTableRecords([$card])
            ->assertCanNotSeeTableRecords([$pix, $old])
            ->resetTableFilters()
            ->filterTable('created_at', ['created_from' => now()->subDays(2)->toDateString(), 'created_until' => null])
            ->assertCanSeeTableRecords([$pix, $card])
            ->assertCanNotSeeTableRecords([$old]);
    }

    public function test_copy_link_action_only_for_pending_and_copies_checkout_url(): void
    {
        $pending = $this->make('pending');
        $paid = $this->make('paid');

        Livewire::test(ListPayments::class)
            ->assertTableActionVisible('copyLink', $pending)
            ->assertTableActionHidden('copyLink', $paid)
            ->assertSeeHtml('clipboard.writeText')
            ->assertSeeHtml('\/checkout\/'.$pending->id);
    }

    public function test_method_column_summarises_card_installments(): void
    {
        $this->make('cardPaid', ['installments' => 3]);
        $this->make('cardPaid', ['installments' => 1]);
        $this->make('pending', ['payment_methods' => ['pix', 'credit_card']]);

        Livewire::test(ListPayments::class)
            ->assertSee('Cartão · 3x')
            ->assertSee('Cartão · à vista')
            ->assertSee('Pix ou cartão');
    }

    public function test_details_show_customer_with_masked_document_and_timestamps(): void
    {
        $payment = $this->make('paid', [
            'customer' => ['name' => 'Maria Silva', 'email' => 'maria@test.com', 'type' => 'individual', 'document' => '12345678909'],
            'ide' => 'or_abc123',
        ]);

        Livewire::test(ListPayments::class)
            ->mountTableAction('view', $payment)
            ->assertSee('Cliente')
            ->assertSee('Maria Silva')
            ->assertSee('maria@test.com')
            ->assertSee('***.456.789-**')
            ->assertDontSee('12345678909')
            ->assertSee('Pago em')
            ->assertSee('Fechar')
            ->assertSee('Dados técnicos')
            ->assertSee('or_abc123');
    }

    public function test_details_hide_customer_section_when_absent_and_show_expiry_when_pending(): void
    {
        $payment = $this->make('pending');

        Livewire::test(ListPayments::class)
            ->mountTableAction('view', $payment)
            ->assertDontSee('Maria')
            ->assertSee('Expira em')
            ->assertDontSee('Pago em');
    }
}
