<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Payment;

class PaymentPage extends Component
{
    public $payment;
    public $id;

    public function mount($id)
    {
        $this->id = $id;
        $this->payment = Payment::withoutGlobalScopes()->findOrFail($id);
    }

    public function atualizar()
    {
        $this->payment = Payment::withoutGlobalScopes()->findOrFail($this->id);
    }

    public function render()
    {
        return view('livewire.payment-page');
    }
}
