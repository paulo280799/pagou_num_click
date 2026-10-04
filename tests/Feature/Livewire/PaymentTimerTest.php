<?php

namespace Tests\Feature\Livewire;

use App\Enums\StatusPaymentEnum;
use App\Livewire\PaymentTimer;
use App\Models\Account;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PaymentTimerTest extends TestCase
{
    use RefreshDatabase;

    public function test_mount_loads_time_left_from_database(): void
    {
        $account = Account::factory()->create();
        $payment = Payment::factory()->create([
            'account_id' => $account->id,
            'expirationDate' => now()->addMinutes(10),
        ]);

        $component = Livewire::test(PaymentTimer::class, ['paymentId' => $payment->id]);

        $this->assertNotNull($component->get('timeLeft'));
        $this->assertGreaterThan(0, $component->get('timeLeft'));
    }

    public function test_check_expiration_cancels_payment_when_expired(): void
    {
        $account = Account::factory()->create();
        $payment = Payment::factory()->expired()->create(['account_id' => $account->id]);

        Livewire::test(PaymentTimer::class, ['paymentId' => $payment->id])
            ->call('checkExpiration');

        $this->assertSame(StatusPaymentEnum::CANCELED, $payment->refresh()->status);
    }

    public function test_check_expiration_updates_time_left_when_not_expired(): void
    {
        $account = Account::factory()->create();
        $payment = Payment::factory()->create([
            'account_id' => $account->id,
            'expirationDate' => now()->addMinutes(10),
        ]);

        $component = Livewire::test(PaymentTimer::class, ['paymentId' => $payment->id])
            ->call('checkExpiration');

        $this->assertGreaterThan(0, $component->get('timeLeft'));
        $this->assertSame(StatusPaymentEnum::PENDING, $payment->refresh()->status);
    }

    public function test_check_expiration_does_not_cancel_when_not_actually_expired(): void
    {
        $account = Account::factory()->create();
        $payment = Payment::factory()->create([
            'account_id' => $account->id,
            'expirationDate' => now()->addMinutes(10),
        ]);

        $component = Livewire::test(PaymentTimer::class, ['paymentId' => $payment->id]);

        // Simula client tentando forjar estado client-side antes de chamar checkExpiration.
        $component->set('timeLeft', -999)
            ->call('checkExpiration');

        $this->assertSame(StatusPaymentEnum::PENDING, $payment->refresh()->status);
    }

    public function test_no_public_cancelar_method_is_exposed(): void
    {
        $this->assertFalse(method_exists(PaymentTimer::class, 'cancelar'));
    }

    public function test_no_public_expiration_date_property_is_exposed(): void
    {
        $this->assertFalse(property_exists(PaymentTimer::class, 'expirationDate'));
    }
}
