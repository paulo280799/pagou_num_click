<?php

namespace App\Filament\Widgets;

use App\Enums\DashboardPeriodEnum;
use App\Enums\StatusPaymentEnum;
use App\Models\Payment;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class DashboardChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'Gráfico de cobranças';

    protected static ?int $sort = 2;

    protected static ?string $maxHeight = '320px';

    protected int|string|array $columnSpan = 'full';

    public ?string $filter = 'tpv';

    protected function getFilters(): ?array
    {
        return [
            'tpv' => 'Volume total transacionado (TPV)',
            'count' => 'Número de cobranças',
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $period = DashboardPeriodEnum::fromFilters($this->filters);
        $volume = $this->filter !== 'count';

        $rows = $period->applyTo(Payment::query())
            ->selectRaw('DATE_FORMAT(created_at, ?) as bucket', [$period->sqlBucketFormat()])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN amount END), 0) as paid', [StatusPaymentEnum::PAID->value])
            ->selectRaw('COUNT(*) as total')
            ->groupBy('bucket')
            ->get()
            ->keyBy('bucket');

        $buckets = $period->buckets();
        $data = collect($buckets)->keys()->map(fn (string $key) => $volume
            ? round((float) ($rows[$key]->paid ?? 0), 2)
            : (int) ($rows[$key]->total ?? 0))->all();

        return [
            'datasets' => [[
                'label' => $volume ? 'TPV (R$)' : 'Cobranças',
                'data' => $data,
                'fill' => true,
                'tension' => 0.4,
                'borderColor' => '#22c55e',
                'backgroundColor' => 'rgba(34, 197, 94, 0.15)',
                'pointBackgroundColor' => '#22c55e',
            ]],
            'labels' => array_values($buckets),
        ];
    }

    /** RawJs só funciona como o objeto de opções inteiro; aninhado em array ele é serializado como `{}`. */
    protected function getOptions(): array|RawJs|null
    {
        if ($this->filter === 'count') {
            return [
                'plugins' => ['legend' => ['display' => false]],
                'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
            ];
        }

        return RawJs::make(<<<'JS'
            {
                plugins: { legend: { display: false } },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: (value) => 'R$ ' + Number(value).toLocaleString('pt-BR', { minimumFractionDigits: 2 }),
                        },
                    },
                },
            }
            JS);
    }
}
