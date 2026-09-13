<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Payment;
use Carbon\Carbon;
use App\Enums\StatusPaymentEnum;

class PaymentTimer extends Component
{
    public $payment;
    public $paymentId;
    public $timeLeft;
    public $expirationDate;

    public function mount($paymentId)
    {
        $this->paymentId = $paymentId;

        $this->loadPayment();
    }

    public function loadPayment()
    {
        $this->payment = Payment::withoutGlobalScopes()->findOrFail($this->paymentId);
        $this->expirationDate = $this->payment->expirationDate;
    }

    public function calculateTimeLeft()
    {
        $targetDate = Carbon::parse($this->expirationDate);
        $now = Carbon::now();

        $this->timeLeft = $now->diffInSeconds($targetDate, false);
    }

    public function cancelar()
    {
        $this->payment->update(['status' => StatusPaymentEnum::CANCELED]);
    }

    public function checkExpiration()
    {
        $expirationTime = Carbon::parse($this->expirationDate);

        if (now()->greaterThanOrEqualTo($expirationTime)) {
            $this->cancelar();
            return;
        }

        $this->calculateTimeLeft();
    }

    public function render()
    {
        return view('livewire.payment-timer', [
            'formattedTimeLeft' => gmdate("i:s", max(0, $this->timeLeft))
        ]);
    }
}
