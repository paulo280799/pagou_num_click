<?php

namespace App\Livewire;

use App\Enums\StatusPaymentEnum;
use App\Models\Payment;
use Carbon\Carbon;
use Livewire\Component;

class PaymentTimer extends Component
{
    public $paymentId;

    public $timeLeft;

    public function mount($paymentId)
    {
        $this->paymentId = $paymentId;

        $this->timeLeft = now()->diffInSeconds($this->freshPayment()->expirationDate, false);
    }

    private function freshPayment(): Payment
    {
        return Payment::withoutGlobalScopes()->findOrFail($this->paymentId);
    }

    public function checkExpiration()
    {
        $payment = $this->freshPayment();

        if (now()->greaterThanOrEqualTo(Carbon::parse($payment->expirationDate))) {
            $this->cancelarSeExpirado($payment);

            return;
        }

        $this->timeLeft = now()->diffInSeconds($payment->expirationDate, false);
    }

    private function cancelarSeExpirado(Payment $payment): void
    {
        if ($payment->status === StatusPaymentEnum::PENDING && now()->greaterThanOrEqualTo(Carbon::parse($payment->expirationDate))) {
            $payment->update(['status' => StatusPaymentEnum::CANCELED]);
        }
    }

    /** MM:SS; com 1 h ou mais, H:MM:SS (o mesmo formato do countdown em JS). */
    public static function formatTimeLeft(int|float $seconds): string
    {
        $seconds = max(0, (int) $seconds);

        return $seconds >= 3600 ? gmdate('G:i:s', $seconds) : gmdate('i:s', $seconds);
    }

    public function render()
    {
        return view('livewire.payment-timer', [
            'expirationTimestamp' => Carbon::parse($this->freshPayment()->expirationDate)->timestamp,
            'formattedTimeLeft' => self::formatTimeLeft($this->timeLeft),
            'urgent' => $this->timeLeft <= 60,
        ]);
    }
}
