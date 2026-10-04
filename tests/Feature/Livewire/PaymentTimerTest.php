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

    public function test_mount_loads_payment_and_expiration_date(): void
    {
        $account = Account::factory()->create();
        $payment = Payment::factory()->create(['account_id' => $account->id]);

        $component = Livewire::test(PaymentTimer::class, ['paymentId' => $payment->id]);

        $this->assertTrue($component->get('payment')->is($payment));
        $this->assertNotNull($component->get('expirationDate'));
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

    public function test_cancelar_marks_payment_as_canceled(): void
    {
        $account = Account::factory()->create();
        $payment = Payment::factory()->create(['account_id' => $account->id]);

        Livewire::test(PaymentTimer::class, ['paymentId' => $payment->id])
            ->call('cancelar');

        $this->assertSame(StatusPaymentEnum::CANCELED, $payment->refresh()->status);
    }
}
