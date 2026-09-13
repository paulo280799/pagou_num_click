<div class="modal">
    <img src="https://logowik.com/content/uploads/images/pix-banco-central8904.logowik.com.webp" alt="Pix Logo"
        class="logo">
    @if ($payment->status === App\Enums\StatusPaymentEnum::PENDING)
        <livewire:payment-timer :payment-id="$payment->id" />
    @endif

    <div wire:poll.5s="atualizar">
        @if ($payment->status === App\Enums\StatusPaymentEnum::PAID)
            <livewire:payment-status-paid />
        @elseif (!in_array($payment->status, [
                App\Enums\StatusPaymentEnum::PENDING,
                App\Enums\StatusPaymentEnum::PAID,
            ]))
            <livewire:payment-status-canceled />
        @elseif ($payment->status === App\Enums\StatusPaymentEnum::PENDING)
            <livewire:payment-status-pending :qrCode="$payment->qrCode" :copyPaste="$payment->copyPaste" :amount="$payment->amount" />
        @endif
    </div>
</div>
