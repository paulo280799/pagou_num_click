<?php

namespace App\Filament\Platform\Resources\PlatformAccountResource\Pages;

use App\Filament\Platform\Resources\PlatformAccountResource;
use App\Models\Account;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreatePlatformAccount extends CreateRecord
{
    protected static string $resource = PlatformAccountResource::class;

    protected static ?string $title = 'Nova conta';

    protected static bool $canCreateAnother = false;

    /** Conta + responsável sem senha utilizável (ele define a sua pelo convite). */
    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            $account = Account::create(['name' => $data['name']]);

            $owner = User::create([
                'name' => $data['owner_name'],
                'email' => $data['owner_email'],
                'password' => Str::random(64),
                'account_id' => $account->id,
            ]);
            $owner->forceFill(['invitation_pending' => true])->save(); // fora do $fillable de propósito

            return $account;
        });
    }

    protected function afterCreate(): void
    {
        PlatformAccountResource::sendInvite($this->record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
