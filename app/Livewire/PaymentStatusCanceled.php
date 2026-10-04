<?php

namespace App\Livewire;

use App\Enums\StatusPaymentEnum;
use Livewire\Component;

class PaymentStatusCanceled extends Component
{
    public ?StatusPaymentEnum $status = null;

    public function mount(?StatusPaymentEnum $status = null)
    {
        $this->status = $status;
    }

    public function render()
    {
        return view('livewire.payment-status-canceled');
    }
}
