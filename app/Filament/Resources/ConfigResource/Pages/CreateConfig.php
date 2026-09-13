<?php

namespace App\Filament\Resources\ConfigResource\Pages;

use App\Filament\Resources\ConfigResource;
use Filament\Actions;
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
}
