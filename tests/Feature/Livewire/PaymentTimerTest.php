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

    /** @dataProvider timeLeftFormats */
    public function test_time_left_shows_hours_only_from_one_hour_up(int $seconds, string $expected): void
    {
        $this->assertSame($expected, PaymentTimer::formatTimeLeft($seconds));
    }

    public static function timeLeftFormats(): array
    {
        return [
            'expired' => [-5, '00:00'],
            'seconds' => [45, '00:45'],
            'minutes' => [899, '14:59'],
            'just under an hour' => [3599, '59:59'],
            'exactly one hour' => [3600, '1:00:00'],
            'two hours minus 1m27s' => [7113, '1:58:33'],
        ];
    }

    public function test_initial_render_of_a_long_timer_includes_hours(): void
    {
        $payment = Payment::factory()->create(['account_id' => Account::factory()->create()->id, 'expirationDate' => now()->addMinutes(118)->addSeconds(30)]);

        Livewire::test(PaymentTimer::class, ['paymentId' => $payment->id])->assertSee("display: '1:58:", false);
    }
}
