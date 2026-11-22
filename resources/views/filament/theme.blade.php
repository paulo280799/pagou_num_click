{{-- Tema do painel alinhado à landing/checkout: fonte de títulos, raios e foco. Cores/fonte do corpo vêm do AdminPanelProvider. --}}
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700&family=JetBrains+Mono:wght@400;500&display=swap">
<style>
    .fi-header-heading, .fi-section-header-heading, .fi-simple-header-heading,
    .fi-wi-stats-overview-stat-value { font-family: 'Bricolage Grotesque', 'Helvetica Neue', Arial, sans-serif; letter-spacing: -.02em; }
    .fi-header-heading, .fi-simple-header-heading { font-weight: 700; }
    .fi-wi-stats-overview-stat-value { font-variant-numeric: tabular-nums; }
    .fi-btn, .fi-input-wrp, .fi-dropdown-panel { border-radius: .625rem; }
    .fi-section, .fi-ta-ctn, .fi-wi-stats-overview-stat, .fi-simple-main { border-radius: 1rem; }
    /* Abas da lista de pagamentos: à esquerda (o Filament as centraliza com mx-auto) e coladas na tabela. */
    .fi-resource-payments .fi-tabs { margin-left: 0; margin-right: auto; margin-bottom: -0.75rem; }
</style>
