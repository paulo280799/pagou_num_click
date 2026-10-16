<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AccountResource\Pages;
use App\Filament\Resources\AccountResource\RelationManagers;
use App\Models\Account;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\RawJs;
use Illuminate\Database\Eloquent\Builder;

use RuntimeException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AccountResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static ?string $navigationIcon = 'heroicon-o-wallet';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Minha conta';

    protected static ?string $pluralModelLabel = 'Minha conta';

    protected static ?string $navigationLabel = 'Minha conta';

    /** Tenant scope: cada usuário só enxerga a própria conta (antes qualquer UUID era acessível). */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('id', auth()->user()?->account_id);
    }

    public static function getRecord(): Account
    {
        static $cachedConfig = null;

        if ($cachedConfig) {
            return $cachedConfig;
        }

        if (!auth()->user() || !auth()->user()->account->id) {
            throw new RuntimeException('User not authenticated or missing account association');
        }

        try {
            $account = Account::findOrFail(auth()->user()->account->id);
        } catch (ModelNotFoundException $e) {
            throw new RuntimeException('Associated account not found');
        }

        $cachedConfig = $account;
        return $account;
    }

    /** Remove tudo que não é dígito (CPF/CNPJ/telefone são guardados só com números). */
    private static function digits(?string $state): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $state);

        return $digits === '' ? null : $digits;
    }

    /** Telefone guardado só com dígitos → "(11) 98765-4321" / "(11) 8765-4321" para exibir no campo. */
    private static function formatPhone(?string $state): ?string
    {
        $d = static::digits($state);

        return match (strlen((string) $d)) {
            11 => sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7)),
            10 => sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6)),
            default => $state,
        };
    }

    public static function form(Form $form): Form
    {
        return $form
            ->columns(1)
            ->schema([
                Forms\Components\Section::make('Dados da conta')
                    ->description('Informações cadastrais do titular da conta.')
                    ->icon('heroicon-o-building-office')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nome da conta')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('legal_name')
                            ->label('Razão social / nome completo')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('document')
                            ->label('CPF ou CNPJ')
                            ->helperText('Somente números.')
                            ->maxLength(18)
                            ->formatStateUsing(fn (?string $state) => $state)
                            ->dehydrateStateUsing(fn (?string $state) => static::digits($state))
                            ->rules([
                                fn () => function (string $attribute, $value, \Closure $fail) {
                                    $digits = static::digits($value);
                                    if ($digits !== null && ! in_array(strlen($digits), [11, 14], true)) {
                                        $fail('Informe um CPF (11 dígitos) ou CNPJ (14 dígitos).');
                                    }
                                },
                            ]),
                        Forms\Components\TextInput::make('phone')
                            ->label('Telefone')
                            ->helperText('DDD + número.')
                            ->tel()
                            ->placeholder('(00) 00000-0000')
                            ->mask(RawJs::make(<<<'JS'
                                $input.replace(/\D/g, '').length > 10 ? '(99) 99999-9999' : '(99) 9999-9999'
                                JS))
                            ->formatStateUsing(fn (?string $state) => static::formatPhone($state))
                            ->maxLength(15)
                            ->dehydrateStateUsing(fn (?string $state) => static::digits($state))
                            ->rules([
                                fn () => function (string $attribute, $value, \Closure $fail) {
                                    $digits = static::digits($value);
                                    if ($digits !== null && ! in_array(strlen($digits), [10, 11], true)) {
                                        $fail('Informe DDD + número (10 ou 11 dígitos).');
                                    }
                                },
                            ]),
                        Forms\Components\TextInput::make('contact_email')
                            ->label('E-mail de contato')
                            ->email()
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\UsersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\CreateAccount::route('/create'),
            'edit' => Pages\EditAccount::route('/{record}/edit'),
        ];
    }
}
