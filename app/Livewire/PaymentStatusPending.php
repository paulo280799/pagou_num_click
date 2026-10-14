<?php

namespace App\Livewire;

use App\Enums\StatusPaymentEnum;
use App\Models\Payment;
use Livewire\Component;

class PaymentStatusPending extends Component
{
    public string $paymentId;

    public string $qrCode;

    public string $copyPaste;

    public float $amount;

    public function mount(string $paymentId, string $qrCode, string $copyPaste, float $amount)
    {
        $this->paymentId = $paymentId;
        $this->qrCode = $qrCode;
        $this->copyPaste = $copyPaste;
        $this->amount = $amount;
    }

    /** Chamado pelo formulário de cartão (Alpine) para a página principal reler o status. */
    public function refreshStatus(): void
    {
        $this->dispatch('payment-updated');
    }

    public function render()
    {
        $payment = Payment::withoutGlobalScopes()->find($this->paymentId);

        // customer == null: pagamento legado, pay-card responde 409. Só booleanos vão ao browser.
        $cardEnabled = $payment !== null
            && filled(config('integration.public_key'))
            && $payment->customer !== null
            && $payment->status === StatusPaymentEnum::PENDING;

        return view('livewire.payment-status-pending', [
            'cardEnabled' => $cardEnabled,
            // tentativa sem resultado definitivo (em análise ou timeout): chave gravada, com ou sem order id
            'cardInReview' => $cardEnabled && $payment->card_attempt_key !== null,
            'cardConfig' => [
                'publicKey' => config('integration.public_key'),
                'maxInstallments' => max(1, (int) config('integration.card_max_installments')),
                'amountCents' => (int) round($this->amount * 100),
                'payUrl' => route('checkout.pay-card', $this->paymentId),
                'tokenUrl' => 'https://api.pagar.me/core/v5/tokens',
            ],
        ]);
    }
}
