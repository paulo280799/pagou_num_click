<div
    class="bg-white rounded-2xl px-5 py-[18px] flex items-center justify-between shadow-[0_1px_2px_rgba(20,20,19,0.04)]"
    x-data="{
        expiresAt: {{ $expirationTimestamp }} * 1000,
        display: '{{ $formattedTimeLeft }}',
        urgent: {{ $urgent ? 'true' : 'false' }},
        tick() {
            const diff = Math.max(0, Math.floor((this.expiresAt - Date.now()) / 1000));
            const minutes = String(Math.floor(diff / 60)).padStart(2, '0');
            const seconds = String(diff % 60).padStart(2, '0');
            this.display = `${minutes}:${seconds}`;
            this.urgent = diff <= 60;

            if (diff <= 0) {
                clearInterval(this.interval);
                $wire.call('checkExpiration');
            }
        }
    }"
    x-init="interval = setInterval(() => tick(), 1000)"
>
    <div class="flex items-center gap-2.5">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" class="flex-shrink-0">
            <circle cx="12" cy="12" r="9" stroke="#00B389" stroke-width="2" stroke-dasharray="4 3"/>
            <path d="M12 7v5l3 2" stroke="#00B389" stroke-width="2" stroke-linecap="round"/>
        </svg>
        <span class="text-sm text-[#141413] font-medium">Aguardando pagamento</span>
    </div>
    <div class="font-mono text-[15px] font-bold tabular-nums" :class="urgent ? 'text-[#C1440E]' : 'text-[#141413]'" x-text="display"></div>
</div>
