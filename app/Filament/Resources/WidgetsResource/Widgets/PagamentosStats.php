<?php

namespace App\Filament\Resources\WidgetsResource\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Enums\StatusPaymentEnum;
use Filament\Widgets\StatsOverviewWidget\Card;
use App\Models\Payment;

class PagamentosStats extends BaseWidget
{
    protected function getStats(): array
    {
        return [
            Card::make('Total de Pagamentos', Payment::count())
                ->description('Todos os pagamentos registrados')
                ->color('primary'),

            Card::make('Em Andamento', Payment::where('status', StatusPaymentEnum::PENDING)->count())
                ->description('Pagamentos que ainda não foram concluídos')
                ->color('warning'),

            Card::make('Finalizados', Payment::where('status', StatusPaymentEnum::PAID)->count())
                ->description('Pagamentos concluídos com sucesso')
                ->color('success'),

            Card::make('Cancelados', Payment::whereIn('status', [
                StatusPaymentEnum::CANCELED,
                StatusPaymentEnum::FAILED
            ])->count())
                ->description('Pagamentos cancelados ou falhados')
                ->color('danger'),
        ];
    }
}
