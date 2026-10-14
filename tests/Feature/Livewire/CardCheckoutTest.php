<?php

namespace Tests\Feature\Livewire;

use App\Enums\PaymentMethodEnum;
use App\Enums\StatusPaymentEnum;
use App\Livewire\PaymentPage;
use App\Models\Account;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CardCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['integration.public_key' => 'pk_test_abc123', 'integration.user_integration' => 'sk_test_SECRET']);
    }

    private function make(string $state = 'withCustomer', array $attrs = []): Payment
    {
        return Payment::factory()->{$state}()->create(array_merge(['account_id' => Account::factory()->create()->id], $attrs));
    }

    public function test_card_tab_and_public_key_rendered_without_secret(): void
    {
        $p = $this->make();

        $this->get("/checkout/{$p->id}")
            ->assertOk()
            ->assertSee('role="tablist"', false)
            ->assertSee('pk_test_abc123')
            ->assertSee('pay-card')
            ->assertDontSee('sk_test_SECRET')
            ->assertDontSee('fulano@example.com');
    }

    public function test_card_form_has_no_wire_bindings(): void
    {
        $p = $this->make();

        $html = $this->get("/checkout/{$p->id}")->getContent();
        $form = substr($html, strpos($html, 'x-data="cardForm('));

        $this->assertStringNotContainsString('wire:model', $form);
        $this->assertStringContainsString('wire:ignore', $html);
    }

    public function test_no_card_tab_without_public_key(): void
    {
        config(['integration.public_key' => null]);
        $p = $this->make();

        $this->get("/checkout/{$p->id}")->assertOk()->assertDontSee('role="tablist"', false);
    }

    public function test_no_card_tab_for_legacy_payment_without_customer(): void
    {
        $p = $this->make('pending');

        $this->get("/checkout/{$p->id}")->assertOk()->assertDontSee('role="tablist"', false);
    }

    public function test_no_card_form_when_not_pending(): void
    {
        $p = $this->make('paid');

        $this->get("/checkout/{$p->id}")->assertOk()->assertDontSee('cardForm', false);
    }

    public function test_card_under_review_shows_notice_and_hides_form(): void
    {
        $p = $this->make('cardAttemptPending');

        $this->get("/checkout/{$p->id}")
            ->assertOk()
            ->assertSee('Pagamento em análise')
            ->assertDontSee('x-data="cardForm(', false);
    }

    public function test_timed_out_attempt_also_shows_notice_and_hides_form(): void
    {
        $p = $this->make('cardTimedOut');

        $this->get("/checkout/{$p->id}")
            ->assertOk()
            ->assertSee('Pagamento em análise')
            ->assertDontSee('x-data="cardForm(', false);
    }

    public function test_notice_disappears_when_attempt_is_released(): void
    {
        $p = $this->make('cardTimedOut');
        $component = Livewire::test(\App\Livewire\PaymentStatusPending::class, [
            'paymentId' => $p->id, 'qrCode' => 'x', 'copyPaste' => 'y', 'amount' => 10.0,
        ])->assertSee('Pagamento em análise');

        $p->releaseCardAttempt();

        $component->call('$refresh')->assertDontSee('Pagamento em análise')->assertSee('cardForm', false);
    }

    public function test_paid_by_card_shows_brand_last_four_and_installments(): void
    {
        $p = $this->make('cardPaid', ['installments' => 3]);

        $this->get("/checkout/{$p->id}")
            ->assertOk()
            ->assertSee('Cartão Visa final 1111 · 3x')
            ->assertSee('pagamento no cartão foi aprovado');
    }

    public function test_paid_by_pix_keeps_pix_text(): void
    {
        $p = $this->make('paid');

        $this->get("/checkout/{$p->id}")->assertOk()->assertSee('Recebemos seu Pix');
    }

    public function test_payment_updated_event_reloads_page_state(): void
    {
        $p = $this->make();
        $component = Livewire::test(PaymentPage::class, ['id' => $p->id]);

        $p->update(['status' => StatusPaymentEnum::PAID, 'payment_method' => PaymentMethodEnum::CREDIT_CARD]);
        $component->dispatch('payment-updated');

        $this->assertSame(StatusPaymentEnum::PAID, $component->get('payment')->status);
    }
}
