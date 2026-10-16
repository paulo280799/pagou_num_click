<?php

namespace App\Filament\Resources\AccountResource\RelationManagers;

use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rules\Password;

class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'Usuários';

    protected static ?string $modelLabel = 'usuário';

    protected static ?string $pluralModelLabel = 'usuários';

    protected static ?string $icon = 'heroicon-o-users';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->label('Nome')
                ->required()
                ->maxLength(255),
            Forms\Components\TextInput::make('email')
                ->label('E-mail')
                ->email()
                ->required()
                ->maxLength(255)
                ->unique(User::class, 'email', ignoreRecord: true),
            Forms\Components\TextInput::make('password')
                ->label('Senha')
                ->password()
                ->revealable()
                ->rule(Password::min(8))
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn (?string $state) => filled($state))
                ->helperText(fn (string $operation) => $operation === 'edit' ? 'Deixe em branco para manter a senha atual.' : null)
                ->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nome')->searchable(),
                Tables\Columns\TextColumn::make('email')->label('E-mail')->searchable(),
                Tables\Columns\TextColumn::make('created_at')->label('Criado em')->dateTime('d/m/Y H:i'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Novo usuário'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('Editar'),
                Tables\Actions\DeleteAction::make()
                    ->label('Remover')
                    ->before(function (Tables\Actions\DeleteAction $action, User $record) {
                        if ($reason = $this->deletionBlockedReason($record)) {
                            Notification::make()->title($reason)->danger()->send();
                            $action->cancel();
                        }
                    }),
            ]);
    }

    /** Não deixa remover a si mesmo nem o último usuário (a API precisa de ao menos um usuário na conta). */
    private function deletionBlockedReason(User $record): ?string
    {
        if ($record->getKey() == auth()->id()) {
            return 'Você não pode remover o seu próprio usuário.';
        }

        if ($this->getOwnerRecord()->users()->count() <= 1) {
            return 'A conta precisa ter ao menos um usuário.';
        }

        return null;
    }
}
