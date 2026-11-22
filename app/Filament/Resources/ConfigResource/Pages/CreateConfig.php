<?php

namespace App\Filament\Resources\ConfigResource\Pages;

use App\Filament\Resources\ConfigResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use App\Models\Config;

class CreateConfig extends CreateRecord
{
    protected static string $resource = ConfigResource::class;

    protected static bool $canCreateAnother = false;

    public function mount(): void
    {
        $existRecord = Config::first();

        if ($existRecord) {
            $this->redirect($this->getResource()::getUrl('edit', ['record' => $existRecord->id]));
        }

        parent::mount();
    }

    protected function afterCreate(): void
    {
        // o valor puro só existe nesta instância, logo após a criação
        Notification::make()
            ->title('Token da API gerado')
            ->body('Copie agora: por segurança ele não será exibido de novo. '.$this->record->api_token)
            ->success()
            ->persistent()
            ->send();
    }
}
