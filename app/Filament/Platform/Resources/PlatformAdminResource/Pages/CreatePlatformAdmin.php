<?php

namespace App\Filament\Platform\Resources\PlatformAdminResource\Pages;

use App\Filament\Platform\Resources\PlatformAdminResource;
use App\Models\Account;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePlatformAdmin extends CreateRecord
{
    protected static string $resource = PlatformAdminResource::class;

    protected static ?string $title = 'Novo administrador';

    protected static bool $canCreateAnother = false;

    /** Todo usuário precisa de uma conta; o super-admin fica na conta "Plataforma" (invisível para ele). */
    protected function handleRecordCreation(array $data): Model
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'account_id' => Account::firstOrCreate(['name' => 'Plataforma'])->id,
        ]);

        // fora do $fillable de propósito: a flag só é dada aqui e pelo comando platform:make-admin
        $user->forceFill(['is_super_admin' => true, 'email_verified_at' => now()])->save();

        return $user;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
