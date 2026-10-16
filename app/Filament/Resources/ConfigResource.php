<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ConfigResource\Pages;
use App\Filament\Resources\ConfigResource\RelationManagers;
use App\Models\Config;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
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

    protected static ?string $modelLabel = 'Configurações';

    protected static ?string $pluralModelLabel = 'Configurações';

    protected static ?string $navigationLabel = 'Configurações';

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
        } catch (ModelNotFoundException $e) {
            throw new RuntimeException('Associated account not found');
        }

        $cachedConfig = $config;
        return $config;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->columns(1)
            ->schema([
                Forms\Components\Section::make('Integração')
                    ->description('Como o seu sistema se conecta ao Pagou num Click.')
                    ->icon('heroicon-o-link')
                    ->key('data.integration')
                    ->headerActions([
                        Forms\Components\Actions\Action::make('regenerateToken')
                            ->label('Regenerar token')
                            ->icon('heroicon-o-arrow-path')
                            ->color('danger')
                            ->size('sm')
                            ->outlined()
                            ->requiresConfirmation()
                            ->modalHeading('Regenerar token da API?')
                            ->modalDescription('O token atual deixa de funcionar imediatamente. Atualize o seu sistema com o novo token para não interromper a criação de cobranças.')
                            ->modalSubmitActionLabel('Regenerar token')
                            ->action(function (Forms\Set $set) {
                                $config = Config::first();

                                if (! $config) {
                                    Notification::make()->title('Nenhuma configuração encontrada para esta conta.')->danger()->send();

                                    return;
                                }

                                $config->forceFill(['api_token' => Str::random(30)])->save();
                                $set('api_token', $config->api_token);

                                Notification::make()->title('Token regenerado. Atualize o seu sistema com o novo token.')->success()->send();
                            }),
                    ])
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('api_token')
                            ->label('Token de acesso')
                            ->helperText('Envie no header Authorization: Bearer. Para trocar, use "Regenerar token".')
                            ->default(fn ($record) => $record->api_token ?? 'Token não gerado ainda.')
                            ->disabled()
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('notification_url')
                            ->label('URL de notificação (webhook)')
                            ->helperText('Recebe o aviso quando o pagamento muda de status.')
                            ->url()
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('redirect_url')
                            ->label('URL de redirecionamento')
                            ->helperText('Para onde o pagador volta após concluir.')
                            ->url()
                            ->required()
                            ->maxLength(255),
                    ]),
                Forms\Components\Section::make('Checkout')
                    ->description('Por quanto tempo o link de pagamento fica válido para o pagador.')
                    ->icon('heroicon-o-clock')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('duration')
                            ->label('Validade do checkout')
                            ->helperText('Vale para as novas cobranças.')
                            ->numeric()
                            ->minValue(1)
                            ->suffix('segundos')
                            ->required(),
                    ]),
                Forms\Components\Section::make('Cartão de crédito')
                    ->description('Parcelamento sem juros oferecido no checkout. Vale para as novas cobranças.')
                    ->icon('heroicon-o-credit-card')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Toggle::make('card_installments_enabled')
                            ->label('Permitir parcelamento')
                            ->helperText('Desligado: o cartão é cobrado sempre à vista (1x).')
                            ->default(true)
                            ->live()
                            ->columnSpanFull(),
                        Forms\Components\Select::make('card_max_installments')
                            ->label('Parcelas máximas')
                            ->options(fn () => collect(range(1, \App\Models\Config::MAX_INSTALLMENTS))
                                ->mapWithKeys(fn (int $n) => [$n => $n === 1 ? '1x (à vista)' : "até {$n}x"])
                                ->all())
                            ->default(\App\Models\Config::MAX_INSTALLMENTS)
                            ->required(fn (Forms\Get $get) => (bool) $get('card_installments_enabled'))
                            ->visible(fn (Forms\Get $get) => (bool) $get('card_installments_enabled'))
                            ->in(range(1, \App\Models\Config::MAX_INSTALLMENTS))
                            ->native(false),
                    ]),
                Forms\Components\Section::make('Tarifas de cobrança')
                    ->description('Percentuais usados no cálculo da tarifa e da receita líquida. Valem para pagamentos confirmados daqui em diante.')
                    ->icon('heroicon-o-receipt-percent')
                    ->columns(4)
                    ->schema([
                        Forms\Components\TextInput::make('pix_fee_percent')
                            ->label('Pix')
                            ->numeric()->minValue(0)->maxValue(100)->step(0.01)->suffix('%')
                            ->default(0.75)
                            ->required(),
                        Forms\Components\TextInput::make('card_fee_percent_1x')
                            ->label('Cartão 1x')
                            ->numeric()->minValue(0)->maxValue(100)->step(0.01)->suffix('%'),
                        Forms\Components\TextInput::make('card_fee_percent_2_6x')
                            ->label('Cartão 2x a 6x')
                            ->numeric()->minValue(0)->maxValue(100)->step(0.01)->suffix('%'),
                        Forms\Components\TextInput::make('card_fee_percent_7_12x')
                            ->label('Cartão 7x a 12x')
                            ->numeric()->minValue(0)->maxValue(100)->step(0.01)->suffix('%'),
                        Forms\Components\Placeholder::make('fee_hint')
                            ->hiddenLabel()
                            ->content('Deixe em branco as taxas de cartão ainda não definidas: a tarifa aparece como "Não configurada".')
                            ->columnSpanFull(),
                    ]),
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
