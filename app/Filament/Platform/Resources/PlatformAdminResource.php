<?php

namespace App\Filament\Platform\Resources;

use App\Filament\Platform\Resources\PlatformAdminResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Super-admins da plataforma (quem opera contas, convites e suspensões). Só super-admins chegam aqui. */
class PlatformAdminResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'administradores';

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Administradores';

    protected static ?string $modelLabel = 'administrador';

    protected static ?string $pluralModelLabel = 'Administradores';

    protected static ?int $navigationSort = 2;

    /** Só os super-admins: donos de conta não aparecem (nem podem ser promovidos por aqui). */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('is_super_admin', true);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Dados do administrador')
                ->description('Acesso total ao painel da plataforma: contas, convites, suspensão e Telescope.')
                ->schema([
                    Forms\Components\TextInput::make('name')->label('Nome')->required()->maxLength(255),
                    Forms\Components\TextInput::make('email')->label('E-mail')->email()->required()->maxLength(255)
                        ->unique('users', 'email', ignoreRecord: true),
                    Forms\Components\TextInput::make('password')
                        ->label(fn (string $operation) => $operation === 'create' ? 'Senha' : 'Nova senha')
                        ->helperText(fn (string $operation) => $operation === 'create' ? 'Mínimo de 8 caracteres.' : 'Em branco, mantém a senha atual.')
                        ->password()->revealable()->autocomplete('new-password')
                        ->required(fn (string $operation) => $operation === 'create')
                        ->minLength(8)->maxLength(255)
                        ->same('passwordConfirmation')
                        // só grava quando preenchida (o cast `hashed` do User faz o hash)
                        ->dehydrated(fn ($state) => filled($state)),
                    Forms\Components\TextInput::make('passwordConfirmation')
                        ->label('Repita a senha')->password()->revealable()->autocomplete('new-password')
                        ->required(fn (string $operation, Forms\Get $get) => $operation === 'create' || filled($get('password')))
                        ->dehydrated(false),
                ])->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Nome')->searchable()->weight('bold')
                    ->description(fn (User $u) => $u->id === auth()->id() ? 'Você' : null),
                Tables\Columns\TextColumn::make('email')->label('E-mail')->searchable(),
                Tables\Columns\TextColumn::make('created_at')->label('Criado em')->dateTime('d/m/Y'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    // não remove a si mesmo nem o último administrador (ninguém mais operaria a plataforma)
                    ->hidden(fn (User $u) => $u->id === auth()->id())
                    ->modalHeading('Remover administrador?')
                    ->modalDescription('O usuário deixa de existir e perde todo acesso. Isto não pode ser desfeito.')
                    ->before(function (Tables\Actions\DeleteAction $action) {
                        if (User::where('is_super_admin', true)->count() <= 1) {
                            \Filament\Notifications\Notification::make()->title('É preciso manter ao menos um administrador.')->danger()->send();
                            $action->cancel();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlatformAdmins::route('/'),
            'create' => Pages\CreatePlatformAdmin::route('/create'),
            'edit' => Pages\EditPlatformAdmin::route('/{record}/edit'),
        ];
    }
}
