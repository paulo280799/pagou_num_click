<?php

namespace App\Filament\Resources\PaymentResource\Pages;

use App\Filament\Resources\PaymentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use App\Filament\Resources\WidgetsResource\Widgets\PagamentosStats;
use App\Filament\Resources\WidgetsResource\Widgets\PagamentosResumoStats;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            PagamentosStats::class,
            PagamentosResumoStats::class,
        ];
    }
}
