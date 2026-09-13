<?php

namespace App\Filament\Resources\AccountResource\Pages;

use App\Filament\Resources\AccountResource;
use Filament\Actions;
use App\Models\Account;
use Filament\Resources\Pages\CreateRecord;

class CreateAccount extends CreateRecord
{
    protected static string $resource = AccountResource::class;

    protected static bool $canCreateAnother = false;

    public function mount(): void
    {
        $existRecord = Account::where('id',auth()->user()->account->id)->first();

        if ($existRecord) {
            $this->redirect($this->getResource()::getUrl('edit', ['record' => $existRecord->id]));
        }

        parent::mount();
    }
}
