<?php
namespace App\Filament\Resources\WidgetsResource\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Enums\StatusPaymentEnum;
use Filament\Widgets\StatsOverviewWidget\Card;
use App\Models\Payment;
use Carbon\Carbon;

class PagamentosResumoStats extends BaseWidget
{
    protected function getCards(): array
    {
        return [
            Card::make('Hoje', $this->calcularValorLiquidoPorPeriodo('dia'))
                ->description('Receita líquida de hoje')
                ->descriptionIcon('heroicon-o-calendar')
                ->color('info'),

            Card::make('Esta semana', $this->calcularValorLiquidoPorPeriodo('semana'))
                ->description('Receita líquida da semana')
                ->descriptionIcon('heroicon-o-calendar')
                ->color('primary'),

            Card::make('Este mês', $this->calcularValorLiquidoPorPeriodo('mes'))
                ->description('Receita líquida do mês')
                ->descriptionIcon('heroicon-o-calendar')
                ->color('success'),

            Card::make('Este ano', $this->calcularValorLiquidoPorPeriodo('ano'))
                ->description('Receita líquida do ano')
                ->descriptionIcon('heroicon-o-calendar')
                ->color('warning'),
        ];
    }

    private function calcularValorLiquidoPorPeriodo(string $periodo): string
    {
        $query = Payment::where('status', StatusPaymentEnum::PAID);

        match ($periodo) {
            'dia' => $query->whereDate('created_at', Carbon::today()),
            'semana' => $query->whereBetween('created_at', [Carbon::now()->startOfWeek(), Carbon::now()->endOfWeek()]),
            'mes' => $query->whereMonth('created_at', Carbon::now()->month)->whereYear('created_at', Carbon::now()->year),
            'ano' => $query->whereYear('created_at', Carbon::now()->year),
        };

        $total = $query->sum('amount');
        $liquido = $total - ($total * 0.0075);

        return 'R$ ' . number_format($liquido, 2, ',', '.');
    }
}
