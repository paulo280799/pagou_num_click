<?php

namespace App\Filament\Widgets;

use App\Enums\DashboardPeriodEnum;
use App\Enums\StatusPaymentEnum;
use App\Models\Payment;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DashboardStatusStats extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 3;

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $period = DashboardPeriodEnum::fromFilters($this->filters);

        $byStatus = $period->applyTo(Payment::query())
            ->selectRaw('status, COUNT(*) as total, COALESCE(SUM(amount), 0) as amount')
            ->groupBy('status')
            ->get()
            ->keyBy(fn ($row) => $row->status->value);

        $all = (int) $byStatus->sum('total');

        $cards = [
            [StatusPaymentEnum::PAID, 'Pago', 'success'],
            [StatusPaymentEnum::PENDING, 'Pendente', 'warning'],
            [StatusPaymentEnum::CANCELED, 'Cancelado', 'danger'],
            [StatusPaymentEnum::FAILED, 'Falha', 'danger'],
        ];

        return array_map(function (array $card) use ($byStatus, $all) {
            [$status, $label, $color] = $card;
            $count = (int) ($byStatus[$status->value]->total ?? 0);
            $percent = $all > 0 ? $count / $all * 100 : 0;

            return Stat::make($label, DashboardStats::money($byStatus[$status->value]->amount ?? 0))
                ->description($count.' '.($count === 1 ? 'cobrança' : 'cobranças').' ('.number_format($percent, 2, ',', '.').'%)')
                ->color($color);
        }, $cards);
    }
}
