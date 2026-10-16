<?php

namespace App\Filament\Widgets;

use App\Enums\DashboardPeriodEnum;
use App\Enums\StatusPaymentEnum;
use App\Models\Payment;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DashboardStats extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 1;

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $period = DashboardPeriodEnum::fromFilters($this->filters);

        $totals = $period->applyTo(Payment::query())
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(amount), 0) as created')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN amount END), 0) as paid', [StatusPaymentEnum::PAID->value])
            ->selectRaw('COALESCE(SUM(status = ?), 0) as paid_count', [StatusPaymentEnum::PAID->value])
            ->first();

        $net = Payment::netRevenue($period->applyTo(Payment::query()));
        $paidCount = (int) $totals->paid_count;
        $ticket = $paidCount > 0 ? (float) $totals->paid / $paidCount : 0;

        return [
            Stat::make('Cobranças criadas', self::money($totals->created))
                ->description($period->label()),
            Stat::make('Cobranças autorizadas', self::money($totals->paid))
                ->description($paidCount.' '.($paidCount === 1 ? 'paga' : 'pagas').' · líquido '.self::money($net))
                ->color('success'),
            Stat::make('Número de cobranças', (string) $totals->total)
                ->description($period->label()),
            Stat::make('Ticket médio', self::money($ticket))
                ->description('Média das cobranças pagas'),
        ];
    }

    public static function money(float|int|string|null $value): string
    {
        return 'R$ '.number_format((float) $value, 2, ',', '.');
    }
}
