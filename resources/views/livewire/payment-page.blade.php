<div class="flex flex-col flex-1 overflow-hidden">

    <div class="px-5 pt-5 pb-4 bg-white border-b border-[#E8E6DF] flex items-center gap-2.5">
        <div class="w-8 h-8 rounded-lg bg-[#00B389] flex items-center justify-center text-white font-bold text-sm flex-shrink-0">
            {{ $merchantInitial }}
        </div>
        <div class="min-w-0">
            <div class="text-[13px] font-semibold text-[#141413] whitespace-nowrap overflow-hidden text-ellipsis">{{ $merchantName }}</div>
            <div class="text-xs text-[#6B6A64]">Pedido #{{ $orderRef }}</div>
        </div>
    </div>

    @if ($payment->status === App\Enums\StatusPaymentEnum::PENDING)
        <div wire:poll.5s="atualizar" class="flex flex-col flex-1 overflow-hidden">
    @else
        <div class="flex flex-col flex-1 overflow-hidden">
    @endif
        @if ($payment->status === App\Enums\StatusPaymentEnum::PAID)
            <livewire:payment-status-paid :redirect-url="$payment->redirect_url" :amount="$payment->amount" :paid-at="$payment->paymentDate" />
        @elseif (!in_array($payment->status, [
                App\Enums\StatusPaymentEnum::PENDING,
                App\Enums\StatusPaymentEnum::PAID,
            ]))
            <livewire:payment-status-canceled :status="$payment->status" />
        @elseif ($payment->status === App\Enums\StatusPaymentEnum::PENDING)
            <livewire:payment-status-pending :payment-id="$payment->id" :qr-code="$payment->qrCode" :copy-paste="$payment->copyPaste" :amount="$payment->amount" />
        @endif
    </div>
</div>
