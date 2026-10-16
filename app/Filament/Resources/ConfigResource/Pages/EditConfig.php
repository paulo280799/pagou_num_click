<?php

namespace App\Filament\Resources\ConfigResource\Pages;

use App\Filament\Resources\ConfigResource;
use Filament\Actions;
use App\Models\Config;
use Filament\Resources\Pages\EditRecord;

class EditConfig extends EditRecord
{
    protected static string $resource = ConfigResource::class;

    protected static ?string $title = 'Configurações';

    protected function getSaveFormAction(): Actions\Action
    {
        return parent::getSaveFormAction()->label('Salvar alterações');
    }

    protected function getCancelFormAction(): Actions\Action
    {
        return parent::getCancelFormAction()->label('Cancelar');
    }

    public function mount(int | string $record): void
    {
        if (!auth()->user() || !auth()->user()->account->id) {
            throw new \RuntimeException('User not authenticated or missing enterprise association');
        }

        $config = Config::first();
        if (!$config) {
            throw new \RuntimeException('Enterprise record not found');
        }

        if (auth()->user()->account->id != $config->account_id) {
            throw new \RuntimeException('Unauthorized access to enterprise record');
        }

        parent::mount($record);
    }

}
