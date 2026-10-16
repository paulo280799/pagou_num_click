<?php

namespace App\Filament\Resources\AccountResource\Pages;

use App\Filament\Resources\AccountResource;
use App\Filament\Resources\AccountResource\Widgets\AccountActivityStats;
use App\Models\Account;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\Rules\Password;

class EditAccount extends EditRecord
{
    protected static string $resource = AccountResource::class;

    protected static ?string $title = 'Minha conta';

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

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('changePassword')
                ->label('Alterar minha senha')
                ->icon('heroicon-o-key')
                ->color('gray')
                ->modalHeading('Alterar minha senha')
                ->modalSubmitActionLabel('Alterar senha')
                ->form([
                    Forms\Components\TextInput::make('current_password')
                        ->label('Senha atual')
                        ->password()
                        ->required()
                        ->currentPassword(),
                    Forms\Components\TextInput::make('password')
                        ->label('Nova senha')
                        ->password()
                        ->revealable()
                        ->required()
                        ->rule(Password::min(8))
                        ->different('current_password')
                        ->confirmed(),
                    Forms\Components\TextInput::make('password_confirmation')
                        ->label('Confirmar nova senha')
                        ->password()
                        ->revealable()
                        ->required(),
                ])
                ->action(function (array $data) {
                    auth()->user()->update(['password' => $data['password']]);

                    Notification::make()->title('Senha alterada com sucesso.')->success()->send();
                }),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [AccountActivityStats::class];
    }

    protected function getSaveFormAction(): Actions\Action
    {
        return parent::getSaveFormAction()->label('Salvar alterações');
    }

    protected function getCancelFormAction(): Actions\Action
    {
        return parent::getCancelFormAction()->label('Cancelar');
    }
}
