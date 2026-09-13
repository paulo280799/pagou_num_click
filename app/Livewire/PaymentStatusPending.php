<?php

namespace App\Livewire;

use Livewire\Component;

class PaymentStatusPending extends Component
{
    public string $qrCode;
    public string $copyPaste;
    public float $amount;

    public function mount(string $qrCode, string $copyPaste, float $amount)
    {
        $this->qrCode = $qrCode;
        $this->copyPaste = $copyPaste;
        $this->amount = $amount;
    }

    public function render()
    {
        return view('livewire.payment-status-pending');
    }
}
