<?php

namespace Tests\Feature\Livewire;

use App\Enums\StatusPaymentEnum;
use App\Livewire\PaymentStatusCanceled;
use App\Livewire\PaymentStatusPaid;
use App\Livewire\PaymentStatusPending;
use App\Models\Account;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentStatusComponentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_status_canceled_renders(): void
    {
        Livewire::test(PaymentStatusCanceled::class, ['status' => StatusPaymentEnum::CANCELED])
            ->assertOk()
            ->assertSee('Tempo esgotado');
    }

    public function test_payment_status_failed_shows_distinct_message(): void
    {
        Livewire::test(PaymentStatusCanceled::class, ['status' => StatusPaymentEnum::FAILED])
            ->assertOk()
            ->assertSee('Pagamento recusado');
    }

    public function test_payment_status_paid_renders(): void
    {
        Livewire::test(PaymentStatusPaid::class, ['amount' => 25.5])->assertOk();
    }

    public function test_payment_status_paid_shows_redirect_button_when_redirect_url_present(): void
    {
        Livewire::test(PaymentStatusPaid::class, ['redirectUrl' => 'https://loja.test', 'amount' => 25.5])
            ->assertOk()
            ->assertSee('Voltar à loja')
            ->assertSee('https://loja.test');
    }

    public function test_payment_status_paid_hides_redirect_button_when_absent(): void
    {
        Livewire::test(PaymentStatusPaid::class, ['redirectUrl' => null, 'amount' => 25.5])
            ->assertOk()
            ->assertDontSee('Voltar à loja');
    }

    public function test_payment_status_pending_renders_with_mounted_data(): void
    {
        $account = Account::factory()->create();
        $payment = Payment::factory()->create(['account_id' => $account->id]);

        Livewire::test(PaymentStatusPending::class, [
            'paymentId' => $payment->id,
            'qrCode' => 'https://qr.test/img.png',
            'copyPaste' => '00020126...',
            'amount' => 10.5,
        ])
            ->assertOk()
            ->assertSet('qrCode', 'https://qr.test/img.png')
            ->assertSet('copyPaste', '00020126...')
            ->assertSet('amount', 10.5);
    }
}
