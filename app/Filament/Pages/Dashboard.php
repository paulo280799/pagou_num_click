<?php

namespace App\Filament\Pages;

use App\Enums\DashboardPeriodEnum;
use App\Filament\Widgets\DashboardChart;
use App\Filament\Widgets\DashboardStats;
use App\Filament\Widgets\DashboardStatusStats;
use App\Support\PaymentsReport;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Split;
use Filament\Forms\Components\View;
use Filament\Forms\Form;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm {
        mountHasFilters as private mountStoredFilters;
    }

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $title = 'Dashboard';

    protected static ?int $navigationSort = 0;

    /** Sem filtro salvo na sessão/URL, abre em "Hoje" (o select do formulário reflete o mesmo valor). */
    public function mountHasFilters(): void
    {
        $this->mountStoredFilters();

        if (blank($this->filters['period'] ?? null)) {
            $this->filters = ['period' => DashboardPeriodEnum::TODAY->value];
            $this->getFiltersForm()->fill($this->filters);
        }
    }

    public function filtersForm(Form $form): Form
    {
        return $form->schema([
            Split::make([
                Select::make('period')
                    ->label('Período')
                    ->options(DashboardPeriodEnum::options())
                    ->default(DashboardPeriodEnum::TODAY->value)
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->prefixIcon('heroicon-o-calendar')
                    ->live()
                    ->grow(false)
                    ->extraFieldWrapperAttributes(['style' => 'min-width: 14rem']),
                View::make('filament.pages.dashboard-download-button')->grow(false),
            ])->columnSpanFull()->from('sm'),
        ]);
    }

    /** Baixa o CSV dos pagamentos do período selecionado. */
    public function downloadReport(): StreamedResponse
    {
        $report = new PaymentsReport(DashboardPeriodEnum::fromFilters($this->filters));

        return response()->streamDownload(function () use ($report) {
            $out = fopen('php://output', 'w');
            $report->write($out);
            fclose($out);
        }, $report->filename(), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function getWidgets(): array
    {
        return [
            DashboardStats::class,
            DashboardChart::class,
            DashboardStatusStats::class,
        ];
    }

    public function getColumns(): int|string|array
    {
        return 1;
    }
}
