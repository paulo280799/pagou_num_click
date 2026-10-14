<?php

namespace Tests\Feature\Livewire;

use App\Enums\StatusPaymentEnum;
use App\Livewire\PaymentPage;
use App\Models\Account;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_route_renders_pending_payment(): void
    {
        $account = Account::factory()->create();
        $payment = Payment::factory()->create(['account_id' => $account->id]);

        $this->get("/checkout/{$payment->id}")
            ->assertOk()
            ->assertSeeLivewire(PaymentPage::class);
    }

    public function test_checkout_route_404s_for_unknown_payment(): void
    {
        $this->get('/checkout/00000000-0000-0000-0000-000000000000')
            ->assertNotFound();
    }

    public function test_atualizar_reloads_payment_from_database(): void
    {
        $account = Account::factory()->create();
        $payment = Payment::factory()->create(['account_id' => $account->id]);

        $component = Livewire::test(PaymentPage::class, ['id' => $payment->id]);

        $payment->update(['status' => StatusPaymentEnum::PAID]);

        $component->call('atualizar');

        $this->assertSame(StatusPaymentEnum::PAID, $component->get('payment')->status);
    }

    /** @dataProvider terminalStatuses */
    public function test_polling_only_runs_while_pending(StatusPaymentEnum $status, bool $polls): void
    {
        $account = Account::factory()->create();
        $payment = Payment::factory()->create(['account_id' => $account->id, 'status' => $status]);

        $html = $this->get("/checkout/{$payment->id}")->assertOk()->getContent();

        $this->assertSame($polls, str_contains($html, 'wire:poll.5s="atualizar"'));
    }

    public static function terminalStatuses(): array
    {
        return [
            'pending' => [StatusPaymentEnum::PENDING, true],
            'paid' => [StatusPaymentEnum::PAID, false],
            'failed' => [StatusPaymentEnum::FAILED, false],
            'canceled' => [StatusPaymentEnum::CANCELED, false],
        ];
    }
}
