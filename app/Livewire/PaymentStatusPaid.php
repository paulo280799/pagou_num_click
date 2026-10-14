<?php

namespace App\Livewire;

use Carbon\Carbon;
use Livewire\Component;

class PaymentStatusPaid extends Component
{
    public ?string $redirectUrl = null;

    public float $amount;

    public $paidAt;

    public bool $isCard = false;

    public ?string $cardBrand = null;

    public ?string $cardLastFour = null;

    public ?int $installments = null;

    public function mount(
        ?string $redirectUrl = null,
        float $amount = 0,
        $paidAt = null,
        bool $isCard = false,
        ?string $cardBrand = null,
        ?string $cardLastFour = null,
        ?int $installments = null,
    ) {
        $this->isCard = $isCard;
        $this->cardBrand = $cardBrand;
        $this->cardLastFour = $cardLastFour;
        $this->installments = $installments;
        $this->redirectUrl = $redirectUrl;
        $this->amount = $amount;
        $this->paidAt = $paidAt ? Carbon::parse($paidAt)->format('d/m/Y \à\s H:i') : null;
    }

    public function render()
    {
        return view('livewire.payment-status-paid');
    }
}
