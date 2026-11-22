<?php

namespace App\Livewire;

use App\Enums\GatewayEnum;
use App\Enums\StatusPaymentEnum;
use App\Models\Payment;
use App\Services\Gateways\GatewayManager;
use Livewire\Component;

class PaymentStatusPending extends Component
{
    public string $paymentId;

    public ?string $qrCode = null;

    public ?string $copyPaste = null;

    public float $amount;

    public function mount(string $paymentId, ?string $qrCode, ?string $copyPaste, float $amount)
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

        // O cartão fica habilitado se qualquer gateway tiver chave pública. Só a chave pública vai ao browser.
        $gatewayConfig = app(GatewayManager::class)->cardCheckoutConfig();

        // customer == null: pagamento legado, pay-card responde 409. Só booleanos vão ao browser.
        $cardEnabled = $payment !== null
            && $gatewayConfig !== null
            && $payment->customer !== null
            && $payment->allowsMethod('credit_card')
            && $payment->status === StatusPaymentEnum::PENDING;

        // sem QR code (checkout só-cartão) ou PIX não habilitado: não há aba Pix
        $pixEnabled = $payment === null || ($payment->allowsMethod('pix') && filled($this->qrCode));

        return view('livewire.payment-status-pending', [
            'pixEnabled' => $pixEnabled,
            'cardEnabled' => $cardEnabled,
            // tentativa sem resultado definitivo (em análise ou timeout): chave gravada, com ou sem order id
            'cardInReview' => $cardEnabled && $payment->card_attempt_key !== null,
            // valor inicial: o card-form.js busca a config vigente em configUrl no submit (o wire:ignore congela esta)
            'cardConfig' => ($gatewayConfig?->toArray() ?? ['gateway' => 'pagarme', 'publicKey' => null, 'tokenUrl' => null]) + [
                // parcelamento no MP só 1x até o spike S4
                'maxInstallments' => $gatewayConfig?->gateway === GatewayEnum::MERCADOPAGO ? 1 : ($payment?->installmentLimit() ?? 1),
                'amountCents' => (int) round($this->amount * 100),
                'payUrl' => route('checkout.pay-card', $this->paymentId),
                'configUrl' => route('checkout.card-config', $this->paymentId),
                // checkout só-cartão: as mensagens do formulário não podem mandar o pagador para um Pix que não existe
                'pixEnabled' => $pixEnabled,
            ],
        ]);
    }
}
