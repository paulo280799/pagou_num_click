<div class="flex-1 overflow-y-auto px-5 pb-5 pt-6 flex flex-col gap-5">

    <div class="flex-1 flex flex-col items-center justify-center gap-5 py-5">
        <div class="w-[84px] h-[84px] rounded-full bg-[#E4F7EF] flex items-center justify-center">
            <svg width="44" height="44" viewBox="0 0 24 24" fill="none"><path d="M5 13l5 5L19 7" stroke="#00B389" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>
        <div class="text-center">
            <div class="text-xl font-bold text-[#141413] mb-1.5">Pagamento confirmado!</div>
            <div class="text-sm text-[#6B6A64] leading-tight max-w-[280px]">Recebemos seu Pix. Um recibo foi enviado para o seu e-mail.</div>
        </div>
    </div>

    <div class="bg-white rounded-2xl p-5 flex flex-col gap-3.5 shadow-[0_1px_2px_rgba(20,20,19,0.04)]">
        <div class="flex justify-between text-[13px]">
            <span class="text-[#6B6A64]">Valor pago</span>
            <span class="font-bold text-[#141413]">R$ {{ number_format(floor($amount * 100) / 100, 2, ',', '.') }}</span>
        </div>
        @if ($paidAt)
            <div class="h-px bg-[#E8E6DF]"></div>
            <div class="flex justify-between text-[13px]">
                <span class="text-[#6B6A64]">Data e hora</span>
                <span class="text-[#141413]">{{ $paidAt }}</span>
            </div>
        @endif
        <div class="h-px bg-[#E8E6DF]"></div>
        <div class="flex justify-between text-[13px]">
            <span class="text-[#6B6A64]">Forma de pagamento</span>
            <span class="text-[#141413] flex items-center gap-1.5">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none"><path d="M4 4h6v6H4V4zm2 2v2h2V6H6zm8-2h6v6h-6V4zm2 2v2h2V6h-2zM4 14h6v6H4v-6zm2 2v2h2v-2H6zm10-2h2v2h-2v-2zm4 0h2v2h-2v-2zm-4 4h2v2h-2v-2zm4 0h2v2h-2v-2z" fill="#00B389"/></svg>
                Pix
            </span>
        </div>
    </div>

    @if (!empty($redirectUrl))
        <a
            href="{{ $redirectUrl }}"
            class="w-full min-h-[44px] px-4 py-[15px] border-0 rounded-xl bg-[#00B389] hover:bg-[#0D8A6F] text-white text-[15px] font-semibold text-center transition-colors"
        >
            Voltar à loja
        </a>
    @endif
</div>
