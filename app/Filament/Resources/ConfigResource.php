<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ConfigResource\Pages;
use App\Filament\Resources\ConfigResource\RelationManagers;
use App\Models\Config;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

use RuntimeException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class ConfigResource extends Resource
{
    protected static ?string $model = Config::class;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 3;

    public static function getRecord(): Config
    {
        static $cachedConfig = null;

        if ($cachedConfig) {
            return $cachedConfig;
        }

        if (!auth()->user() || !auth()->user()->account->id) {
            throw new RuntimeException('User not authenticated or missing account association');
        }

        try {
            $config = Config::first();
            dd($config);
        } catch (ModelNotFoundException $e) {
            throw new RuntimeException('Associated account not found');
        }

        $cachedConfig = $config;
        return $config;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('duration')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('notification_url')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('redirect_url')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('api_token')
                    ->label('Token de Acesso')
                    ->default(fn ($record) => $record->api_token ?? 'Token não gerado ainda.')
                    ->disabled() // Campo desabilitado, tornando-o somente leitura
                    ->hint('Esse token não pode ser alterado.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            //'index' => Pages\ListConfigs::route('/'),
            'index' => Pages\CreateConfig::route('/create'),
            'edit' => Pages\EditConfig::route('/{record}/edit'),
        ];
    }
}
