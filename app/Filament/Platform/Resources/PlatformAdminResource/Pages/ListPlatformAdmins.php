<?php

namespace App\Filament\Platform\Resources\PlatformAdminResource\Pages;

use App\Filament\Platform\Resources\PlatformAdminResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPlatformAdmins extends ListRecords
{
    protected static string $resource = PlatformAdminResource::class;

    protected static ?string $title = 'Administradores';

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()->label('Novo administrador')];
    }
}
