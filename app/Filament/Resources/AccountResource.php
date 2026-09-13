<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AccountResource\Pages;
use App\Models\Account;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

use RuntimeException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class AccountResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static ?string $navigationIcon = 'heroicon-o-wallet';

    protected static ?int $navigationSort = 2;

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

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255)
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\CreateAccount::route('/create'),
            'edit' => Pages\EditAccount::route('/{record}/edit'),
        ];
    }
}
