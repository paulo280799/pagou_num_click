{{-- Alpine puro: nada aqui usa wire:model; dados do cartão nunca passam pelo Livewire/Laravel. --}}
<div wire:ignore x-data="cardForm(@js($cardConfig))" class="flex flex-col gap-4">
    <form @submit.prevent="submit()" novalidate class="bg-white rounded-2xl p-5 flex flex-col gap-3.5 shadow-[0_1px_2px_rgba(20,20,19,0.04)]">

        <div x-show="message" x-cloak role="alert"
            :class="status === 'processing' || message === 'Pagamento aprovado!' ? 'bg-[#E4F7EF] text-[#0D8A6F]' : 'bg-[#FBEAE3] text-[#C1440E]'"
            class="rounded-xl px-3.5 py-3 text-[13px] leading-snug">
            <span x-text="message"></span>
            <button type="button" x-show="declined" @click="tab = 'pix'" class="block mt-1 font-semibold underline">Pagar com Pix</button>
        </div>

        <fieldset :disabled="busy || locked" class="flex flex-col gap-3.5 border-0 p-0 m-0">
            <label class="flex flex-col gap-1 text-[13px] font-semibold text-[#141413]">Número do cartão
                <input type="text" inputmode="numeric" autocomplete="cc-number" placeholder="0000 0000 0000 0000" maxlength="23"
                    x-model="number" @input="number = $el.value = maskPan($el.value)"
                    class="w-full rounded-xl border border-[#E8E6DF] px-3.5 py-3 text-[15px] font-normal">
                <span x-show="errors.number" x-text="errors.number" class="text-xs font-normal text-[#C1440E]"></span>
            </label>

            <label class="flex flex-col gap-1 text-[13px] font-semibold text-[#141413]">Nome impresso no cartão
                <input type="text" autocomplete="cc-name" x-model="holder" @input="holder = holder.toUpperCase()"
                    class="w-full rounded-xl border border-[#E8E6DF] px-3.5 py-3 text-[15px] font-normal">
                <span x-show="errors.holder" x-text="errors.holder" class="text-xs font-normal text-[#C1440E]"></span>
            </label>

            <div class="grid grid-cols-2 gap-3">
                <label class="flex flex-col gap-1 text-[13px] font-semibold text-[#141413]">Validade
                    <input type="text" inputmode="numeric" autocomplete="cc-exp" placeholder="MM/AA" maxlength="5"
                        x-model="expiry" @input="expiry = $el.value = maskExpiry($el.value)"
                        class="w-full rounded-xl border border-[#E8E6DF] px-3.5 py-3 text-[15px] font-normal">
                    <span x-show="errors.expiry" x-text="errors.expiry" class="text-xs font-normal text-[#C1440E]"></span>
                </label>
                <label class="flex flex-col gap-1 text-[13px] font-semibold text-[#141413]">CVV
                    <input type="text" inputmode="numeric" autocomplete="cc-csc" maxlength="4" x-model="cvv"
                        class="w-full rounded-xl border border-[#E8E6DF] px-3.5 py-3 text-[15px] font-normal">
                    <span x-show="errors.cvv" x-text="errors.cvv" class="text-xs font-normal text-[#C1440E]"></span>
                </label>
            </div>

            <label class="flex flex-col gap-1 text-[13px] font-semibold text-[#141413]">Parcelas
                <select x-model="installments" class="w-full rounded-xl border border-[#E8E6DF] px-3.5 py-3 text-[15px] font-normal bg-white">
                    <template x-for="o in installmentOptions()" :key="o.n">
                        <option :value="o.n" x-text="o.label"></option>
                    </template>
                </select>
            </label>

            <div class="text-[13px] font-semibold text-[#141413] pt-1">Endereço de cobrança</div>

            <label class="flex flex-col gap-1 text-[13px] font-semibold text-[#141413]">CEP
                <input type="text" inputmode="numeric" autocomplete="postal-code" placeholder="00000-000" maxlength="9"
                    x-model="zip" @input="zip = $el.value = maskZip($el.value); lookupZip()"
                    class="w-full rounded-xl border border-[#E8E6DF] px-3.5 py-3 text-[15px] font-normal">
                <span x-show="zipLoading" x-cloak class="text-xs font-normal text-[#6B6A64]">Buscando endereço...</span>
                <span x-show="zipLookupFailed" x-cloak class="text-xs font-normal text-[#6B6A64]">Não encontramos esse CEP. Preencha o endereço abaixo.</span>
                <span x-show="errors.zip" x-text="errors.zip" class="text-xs font-normal text-[#C1440E]"></span>
            </label>

            <div class="grid grid-cols-[1fr_96px] gap-3">
                <label class="flex flex-col gap-1 text-[13px] font-semibold text-[#141413]">Rua
                    <input type="text" autocomplete="address-line1" maxlength="200" x-model="street"
                        class="w-full rounded-xl border border-[#E8E6DF] px-3.5 py-3 text-[15px] font-normal">
                    <span x-show="errors.street" x-text="errors.street" class="text-xs font-normal text-[#C1440E]"></span>
                </label>
                <label class="flex flex-col gap-1 text-[13px] font-semibold text-[#141413]">Número
                    <input type="text" autocomplete="off" maxlength="20" x-model="addrNumber"
                        class="w-full rounded-xl border border-[#E8E6DF] px-3.5 py-3 text-[15px] font-normal">
                    <span x-show="errors.addrNumber" x-text="errors.addrNumber" class="text-xs font-normal text-[#C1440E]"></span>
                </label>
            </div>

            <div class="grid grid-cols-[1fr_96px] gap-3">
                <label class="flex flex-col gap-1 text-[13px] font-semibold text-[#141413]">Cidade
                    <input type="text" autocomplete="address-level2" maxlength="64" x-model="city"
                        class="w-full rounded-xl border border-[#E8E6DF] px-3.5 py-3 text-[15px] font-normal">
                    <span x-show="errors.city" x-text="errors.city" class="text-xs font-normal text-[#C1440E]"></span>
                </label>
                <label class="flex flex-col gap-1 text-[13px] font-semibold text-[#141413]">UF
                    <select x-model="state" autocomplete="address-level1" class="w-full rounded-xl border border-[#E8E6DF] px-3 py-3 text-[15px] font-normal bg-white">
                        <option value="">--</option>
                        <template x-for="uf in ufs" :key="uf"><option :value="uf" x-text="uf"></option></template>
                    </select>
                    <span x-show="errors.state" x-text="errors.state" class="text-xs font-normal text-[#C1440E]"></span>
                </label>
            </div>
        </fieldset>

        <button type="submit" :disabled="busy || locked"
            class="w-full min-h-[44px] px-4 py-3.5 border-0 rounded-xl bg-[#00B389] hover:bg-[#0D8A6F] disabled:opacity-60 disabled:cursor-not-allowed text-white text-[15px] font-semibold flex items-center justify-center gap-2 transition-colors">
            <svg x-show="busy" x-cloak class="animate-spin" width="18" height="18" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="9" stroke="#fff" stroke-opacity=".3" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="#fff" stroke-width="3" stroke-linecap="round"/></svg>
            <span x-text="busy ? 'Processando...' : 'Pagar ' + amountLabel"></span>
        </button>
    </form>
</div>
