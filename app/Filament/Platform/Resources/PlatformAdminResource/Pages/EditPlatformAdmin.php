<?php

namespace App\Filament\Platform\Resources\PlatformAdminResource\Pages;

use App\Filament\Platform\Resources\PlatformAdminResource;
use Filament\Resources\Pages\EditRecord;

class EditPlatformAdmin extends EditRecord
{
    protected static string $resource = PlatformAdminResource::class;

    protected static ?string $title = 'Editar administrador';

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
