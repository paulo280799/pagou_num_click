@php
    $isFailed = $status === App\Enums\StatusPaymentEnum::FAILED;
@endphp

<div class="flex-1 overflow-y-auto px-5 pb-5 pt-6 flex flex-col gap-5">
    <div class="flex-1 flex flex-col items-center justify-center gap-5 py-5">
        <div class="w-[84px] h-[84px] rounded-full bg-[#FBEAE3] flex items-center justify-center">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="#C1440E" stroke-width="2"/><path d="M12 7v5l3 2" stroke="#C1440E" stroke-width="2" stroke-linecap="round"/></svg>
        </div>
        <div class="text-center">
            @if ($isFailed)
                <div class="text-xl font-bold text-[#141413] mb-1.5">Pagamento recusado</div>
                <div class="text-sm text-[#6B6A64] leading-tight max-w-[280px]">O pagamento não foi aprovado. Tente novamente ou utilize outro método.</div>
            @else
                <div class="text-xl font-bold text-[#141413] mb-1.5">Tempo esgotado</div>
                <div class="text-sm text-[#6B6A64] leading-tight max-w-[280px]">O código Pix expirou antes da confirmação do pagamento. Gere um novo código para continuar.</div>
            @endif
        </div>
    </div>
</div>
