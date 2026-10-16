<?php

namespace App\Filament\Resources\PaymentResource\Pages;

use App\Filament\Resources\PaymentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use App\Enums\StatusPaymentEnum;
use App\Models\Payment;
use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        $count = fn (array $statuses) => Payment::whereIn('status', $statuses)->count();

        return [
            'all' => Tab::make('Todos')
                ->badge(Payment::count()),
            'pending' => Tab::make('Aguardando')
                ->badge($count([StatusPaymentEnum::PENDING]))
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', StatusPaymentEnum::PENDING)),
            'paid' => Tab::make('Pagos')
                ->badge($count([StatusPaymentEnum::PAID]))
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', StatusPaymentEnum::PAID)),
            'canceled' => Tab::make('Cancelados')
                ->badge($count([StatusPaymentEnum::CANCELED, StatusPaymentEnum::FAILED]))
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [StatusPaymentEnum::CANCELED, StatusPaymentEnum::FAILED])),
        ];
    }
}
