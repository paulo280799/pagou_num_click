<?php

namespace App\Livewire;

use Carbon\Carbon;
use Livewire\Component;

class PaymentStatusPaid extends Component
{
    public ?string $redirectUrl = null;

    public float $amount;

    public $paidAt;

    public function mount(?string $redirectUrl = null, float $amount = 0, $paidAt = null)
    {
        $this->redirectUrl = $redirectUrl;
        $this->amount = $amount;
        $this->paidAt = $paidAt ? Carbon::parse($paidAt)->format('d/m/Y \à\s H:i') : null;
    }

    public function render()
    {
        return view('livewire.payment-status-paid');
    }
}
