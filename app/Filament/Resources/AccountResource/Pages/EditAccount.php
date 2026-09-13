<?php

namespace App\Filament\Resources\AccountResource\Pages;

use App\Filament\Resources\AccountResource;
use Filament\Actions;
use App\Models\Account;
use Filament\Resources\Pages\EditRecord;

class EditAccount extends EditRecord
{
    protected static string $resource = AccountResource::class;

    public function mount(int | string $record): void
    {
        if (!auth()->user() || !auth()->user()->account->id) {
            throw new \RuntimeException('User not authenticated or missing account association');
        }

        $account = Account::where('id', auth()->user()->account->id)->first();
        if (!$account) {
            throw new \RuntimeException('Enterprise record not found');
        }

        if (auth()->user()->account->id != $account->id) {
            throw new \RuntimeException('Unauthorized access to enterprise record');
        }

        parent::mount($record);
    }
}
