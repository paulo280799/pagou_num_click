<?php

namespace App\Livewire;

use App\Models\Payment;
use Livewire\Component;

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
        $merchantName = $this->payment->account->name ?? 'Loja';

        return view('livewire.payment-page', [
            'merchantName' => $merchantName,
            'merchantInitial' => mb_strtoupper(mb_substr($merchantName, 0, 1)),
            'orderRef' => $this->payment->refExternal ?? mb_substr($this->payment->id, 0, 8),
        ]);
    }
}
