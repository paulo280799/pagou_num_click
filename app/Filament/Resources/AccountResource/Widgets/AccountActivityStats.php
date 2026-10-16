<?php

namespace App\Filament\Resources\AccountResource\Widgets;

use App\Enums\StatusPaymentEnum;
use App\Models\Payment;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class AccountActivityStats extends BaseWidget
{
    protected function getStats(): array
    {
        $month = fn () => Payment::whereMonth('created_at', now()->month)->whereYear('created_at', now()->year);

        $created = $month()->count();
        $paid = $month()->where('status', StatusPaymentEnum::PAID)->count();
        $volume = (float) $month()->where('status', StatusPaymentEnum::PAID)->sum('amount');
        $conversion = $created > 0 ? round($paid / $created * 100, 1) : 0;

        return [
            Stat::make('Cobranças no mês', $created)
                ->description('Criadas em ' . now()->translatedFormat('F/Y'))
                ->color('primary'),
            Stat::make('Pagas no mês', $paid)
                ->description('R$ ' . number_format($volume, 2, ',', '.') . ' em volume')
                ->color('success'),
            Stat::make('Conversão', number_format($conversion, 1, ',', '.') . '%')
                ->description('Pagas ÷ criadas no mês')
                ->color('info'),
        ];
    }
}
