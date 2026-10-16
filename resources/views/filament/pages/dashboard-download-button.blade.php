{{-- O painel só carrega o CSS do Filament (sem tema próprio): classes Tailwind avulsas (sm:mt-8) não existem, por isso o estilo é inline --}}
<style>
    @media (min-width: 640px) {
        .dashboard-download { margin-top: 2rem; }
    }
</style>

<div class="dashboard-download">
    <x-filament::button
        wire:click="downloadReport"
        wire:loading.attr="disabled"
        wire:target="downloadReport"
        color="gray"
        icon="heroicon-o-arrow-down-tray"
    >
        Baixar relatório
    </x-filament::button>
</div>
