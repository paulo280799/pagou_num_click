<?php

namespace App\Filament\Platform\Resources\PlatformAccountResource\Pages;

use App\Filament\Platform\Resources\PlatformAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPlatformAccounts extends ListRecords
{
    protected static string $resource = PlatformAccountResource::class;

    protected static ?string $title = 'Contas';

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Nova conta')];
    }
}
