<div x-data="{ copied: false }" class="flex-1 overflow-y-auto px-5 pb-5 pt-5 flex flex-col gap-5">

    <livewire:payment-timer :payment-id="$paymentId" />

    <div class="bg-white rounded-[20px] p-6 flex flex-col items-center gap-4 shadow-[0_1px_2px_rgba(20,20,19,0.04)]">
        <div class="w-[220px] h-[220px] bg-white border border-[#E8E6DF] rounded-xl flex items-center justify-center">
            <img src="{{ $qrCode }}" alt="QR Code Pix" class="w-[196px] h-[196px] object-contain">
        </div>
        <div class="text-center">
            <div class="text-sm font-semibold text-[#141413] mb-1">Escaneie com o app do seu banco</div>
            <div class="text-[13px] text-[#6B6A64] leading-tight">Abra o Pix, aponte a câmera para o código e confirme o valor</div>
        </div>
    </div>

    <div class="flex items-baseline justify-center gap-1.5 py-1">
        <span class="text-[13px] text-[#6B6A64]">Valor a pagar</span>
        <span class="text-xl font-bold text-[#141413]">R$ {{ number_format(floor($amount * 100) / 100, 2, ',', '.') }}</span>
    </div>

    <div class="flex flex-col gap-2">
        <div class="text-[13px] font-semibold text-[#141413]">Ou copie o código Pix</div>
        <div class="bg-white border border-[#E8E6DF] rounded-xl px-3.5 py-3 font-mono text-[11px] text-[#6B6A64] leading-relaxed max-h-[66px] overflow-hidden text-ellipsis break-all" id="pix-key">{{ $copyPaste }}</div>
        <button
            type="button"
            class="w-full min-h-[44px] px-4 py-3.5 border-0 rounded-xl bg-[#00B389] hover:bg-[#0D8A6F] text-white text-[15px] font-semibold cursor-pointer flex items-center justify-center gap-2 transition-colors"
            @click="
                navigator.clipboard.writeText(document.getElementById('pix-key').innerText).then(() => {
                    copied = true;
                    setTimeout(() => copied = false, 2000);
                })
            "
        >
            <template x-if="!copied">
                <span class="flex items-center gap-2">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><rect x="8" y="8" width="12" height="12" rx="2" stroke="#fff" stroke-width="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2" stroke="#fff" stroke-width="2"/></svg>
                    Copiar código Pix
                </span>
            </template>
            <template x-if="copied">
                <span class="flex items-center gap-2">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none"><path d="M5 13l4 4L19 7" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Código copiado!
                </span>
            </template>
        </button>
    </div>

    <div class="pt-3.5 mt-auto -mx-5 px-5 bg-white border-t border-[#E8E6DF] flex items-center justify-center gap-1.5 py-3.5">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6l8-4z" stroke="#6B6A64" stroke-width="1.8" stroke-linejoin="round"/></svg>
        <span class="text-[11px] text-[#6B6A64]">Pagamento processado com segurança</span>
    </div>
</div>
