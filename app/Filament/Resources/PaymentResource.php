<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentResource\Pages;
use App\Filament\Resources\PaymentResource\RelationManagers;
use App\Models\Payment;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use App\Enums\PaymentMethodEnum;
use App\Enums\StatusPaymentEnum;

use Filament\Infolists\Infolist;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\Section;
use Illuminate\Support\Js;
use Illuminate\Support\Str;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'pagamento';

    protected static ?string $pluralModelLabel = 'pagamentos';

    protected static ?string $navigationLabel = 'Pagamentos';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('5s')
            ->columns([
                Tables\Columns\TextColumn::make('refExternal')
                    ->label('Referência')
                    ->weight('bold')
                    ->searchable()
                    ->description(fn (Payment $record): string => Str::limit($record->id, 8, '…'))
                    ->copyable()
                    ->copyMessage('Referência copiada'),
                Tables\Columns\TextColumn::make('payment_method_short')
                    ->label('Meio')
                    ->state(fn (Payment $record): string => static::paymentMethodShort($record))
                    ->icon(fn (Payment $record): string => static::paymentMethodIcon($record)),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Valor')
                    ->money('BRL', locale: 'pt_BR')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nenhum pagamento encontrado')
            ->searchPlaceholder('Buscar por referência ou ID')
            ->filters([
                Tables\Filters\SelectFilter::make('payment_method')
                    ->label('Meio de pagamento')
                    ->options([
                        PaymentMethodEnum::PIX->value => 'Pix',
                        PaymentMethodEnum::CREDIT_CARD->value => 'Cartão de crédito',
                    ]),
                Tables\Filters\Filter::make('created_at')
                    ->label('Período')
                    ->form([
                        Forms\Components\DatePicker::make('created_from')->label('Criado a partir de'),
                        Forms\Components\DatePicker::make('created_until')->label('Criado até'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['created_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['created_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['created_from'] ?? null) {
                            $indicators[] = 'A partir de '.\Carbon\Carbon::parse($data['created_from'])->format('d/m/Y');
                        }
                        if ($data['created_until'] ?? null) {
                            $indicators[] = 'Até '.\Carbon\Carbon::parse($data['created_until'])->format('d/m/Y');
                        }

                        return $indicators;
                    }),
                Tables\Filters\Filter::make('amount')
                    ->label('Valor')
                    ->form([
                        Forms\Components\TextInput::make('amount_from')->label('Valor mínimo')->numeric()->minValue(0)->prefix('R$'),
                        Forms\Components\TextInput::make('amount_until')->label('Valor máximo')->numeric()->minValue(0)->prefix('R$'),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(filled($data['amount_from'] ?? null), fn (Builder $q) => $q->where('amount', '>=', $data['amount_from']))
                        ->when(filled($data['amount_until'] ?? null), fn (Builder $q) => $q->where('amount', '<=', $data['amount_until'])))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if (filled($data['amount_from'] ?? null)) {
                            $indicators[] = 'Valor a partir de R$ '.number_format((float) $data['amount_from'], 2, ',', '.');
                        }
                        if (filled($data['amount_until'] ?? null)) {
                            $indicators[] = 'Valor até R$ '.number_format((float) $data['amount_until'], 2, ',', '.');
                        }

                        return $indicators;
                    }),
            ])
            ->filtersFormColumns(2)
            ->actions([
                Tables\Actions\Action::make('copyLink')
                    ->label('Copiar link')
                    ->icon('heroicon-o-link')
                    ->color('gray')
                    ->visible(fn (Payment $record): bool => $record->status === StatusPaymentEnum::PENDING)
                    ->alpineClickHandler(fn (Payment $record): string => 'window.navigator.clipboard.writeText('.Js::from(config('app.url')."/checkout/{$record->id}").'); $tooltip(\'Link copiado\', { timeout: 1500 })'),
                Tables\Actions\ViewAction::make()
                    ->label('Ver')
                    ->modalHeading(fn (Payment $record): string => 'Pagamento '.$record->refExternal)
                    ->modalCancelAction(fn (\Filament\Actions\StaticAction $action) => $action->label('Fechar'))
                    ->modalWidth('3xl'),
            ])
            ->bulkActions([

            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->size(TextEntry\TextEntrySize::Large),
                        TextEntry::make('amount')
                            ->label('Valor')
                            ->money('BRL', locale: 'pt_BR')
                            ->size(TextEntry\TextEntrySize::Large)
                            ->weight('bold'),
                        TextEntry::make('refExternal')
                            ->label('Referência externa')
                            ->copyable(),
                        TextEntry::make('id')
                            ->label('Número da transação')
                            ->copyable(),
                    ]),
                Section::make('Pagamento')
                    ->icon('heroicon-o-credit-card')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('payment_method_label')
                            ->label('Meio de pagamento')
                            ->state(fn (Payment $record): string => static::paymentMethodLabel($record)),
                        TextEntry::make('card_label')
                            ->label('Cartão')
                            ->state(fn (Payment $record): string => trim(($record->card_brand ?: 'Cartão').($record->card_last_four ? " final {$record->card_last_four}" : '')))
                            ->visible(fn (Payment $record): bool => static::isCardPaid($record)),
                        TextEntry::make('installments_label')
                            ->label('Parcelamento')
                            ->state(function (Payment $record): string {
                                $n = max(1, (int) $record->installments);

                                return $n === 1
                                    ? 'À vista'
                                    : $n.'x de R$ '.number_format($record->amount / $n, 2, ',', '.').' sem juros';
                            })
                            ->visible(fn (Payment $record): bool => static::isCardPaid($record)),
                        TextEntry::make('created_at')
                            ->label('Criado em')
                            ->dateTime('d/m/Y H:i'),
                        TextEntry::make('expirationDate')
                            ->label('Expira em')
                            ->dateTime('d/m/Y H:i')
                            ->visible(fn (Payment $record): bool => $record->status === StatusPaymentEnum::PENDING && filled($record->expirationDate)),
                        TextEntry::make('paymentDate')
                            ->label('Pago em')
                            ->dateTime('d/m/Y H:i')
                            ->visible(fn (Payment $record): bool => filled($record->paymentDate)),
                    ]),
                Section::make('Valores')
                    ->icon('heroicon-o-banknotes')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('amount_sale')
                            ->label('Venda')
                            ->state(fn (Payment $record): string => 'R$ '.number_format($record->amount, 2, ',', '.')),
                        TextEntry::make('fee_rate')
                            ->label('Taxa')
                            ->state(function (Payment $record): string {
                                $fee = $record->feeSummary();

                                if ($fee['percent'] === null) {
                                    return 'Não configurada';
                                }

                                return number_format($fee['percent'], 2, ',', '.').'%'.($fee['estimated'] ? ' (estimada)' : '');
                            }),
                        TextEntry::make('processing_fee')
                            ->label('Tarifa de processamento')
                            ->state(fn (Payment $record): string => ($amount = $record->feeSummary()['amount']) === null
                                ? '—'
                                : 'R$ '.number_format($amount, 2, ',', '.')),
                        TextEntry::make('total')
                            ->label('Total líquido')
                            ->state(fn (Payment $record): string => 'R$ '.number_format($record->amount - ($record->feeSummary()['amount'] ?? 0), 2, ',', '.'))
                            ->weight('bold')
                            ->size(TextEntry\TextEntrySize::Large),
                    ]),
                Section::make('Cliente')
                    ->icon('heroicon-o-user')
                    ->columns(3)
                    ->visible(fn (Payment $record): bool => is_array($record->customer) && filled($record->customer))
                    ->schema([
                        TextEntry::make('customer_name')
                            ->label('Nome')
                            ->state(fn (Payment $record): ?string => data_get($record->customer, 'name')),
                        TextEntry::make('customer_email')
                            ->label('E-mail')
                            ->state(fn (Payment $record): ?string => data_get($record->customer, 'email')),
                        TextEntry::make('customer_document')
                            ->label('Documento')
                            ->state(fn (Payment $record): ?string => static::maskDocument(data_get($record->customer, 'document'))),
                    ]),
                Section::make('Dados técnicos')
                    ->icon('heroicon-o-code-bracket')
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        TextEntry::make('ide')->label('ID do pedido Pix (provedor)')->copyable()->visible(fn (Payment $r): bool => filled($r->ide)),
                        TextEntry::make('card_order_id')->label('ID do pedido do cartão (provedor)')->copyable()->visible(fn (Payment $r): bool => filled($r->card_order_id)),
                        TextEntry::make('acquirer_tid')->label('TID')->copyable()->visible(fn (Payment $r): bool => filled($r->acquirer_tid)),
                        TextEntry::make('acquirer_nsu')->label('NSU')->copyable()->visible(fn (Payment $r): bool => filled($r->acquirer_nsu)),
                        TextEntry::make('acquirer_auth_code')->label('Código de autorização')->copyable()->visible(fn (Payment $r): bool => filled($r->acquirer_auth_code)),
                    ]),
            ]);
    }

    /** CPF/CNPJ parcialmente oculto para exibição no painel: ***.456.789-** / **.345.678/0001-**. */
    private static function maskDocument(?string $document): ?string
    {
        $d = preg_replace('/\D/', '', (string) $document);

        return match (strlen($d)) {
            11 => '***.'.substr($d, 3, 3).'.'.substr($d, 6, 3).'-**',
            14 => '**.'.substr($d, 2, 3).'.'.substr($d, 5, 3).'/'.substr($d, 8, 4).'-**',
            default => filled($document) ? '••••••' : null,
        };
    }

    /** Rótulo curto do meio de pagamento para a lista. */
    private static function paymentMethodShort(Payment $record): string
    {
        if (static::isCardPaid($record)) {
            $n = max(1, (int) $record->installments);

            return 'Cartão · '.($n === 1 ? 'à vista' : "{$n}x");
        }

        if ($record->status !== StatusPaymentEnum::PAID && $record->allowsMethod('pix') && $record->allowsMethod('credit_card')) {
            return 'Pix ou cartão';
        }

        return $record->payment_method === PaymentMethodEnum::CREDIT_CARD ? 'Cartão' : 'Pix';
    }

    private static function paymentMethodIcon(Payment $record): string
    {
        return $record->payment_method === PaymentMethodEnum::CREDIT_CARD
            ? 'heroicon-o-credit-card'
            : 'heroicon-o-qr-code';
    }

    private static function isCardPaid(Payment $record): bool
    {
        return $record->status === StatusPaymentEnum::PAID && $record->payment_method === PaymentMethodEnum::CREDIT_CARD;
    }

    /** Pago: meio usado. Em aberto com Pix e cartão liberados, o pagador ainda não escolheu. */
    private static function paymentMethodLabel(Payment $record): string
    {
        if ($record->status !== StatusPaymentEnum::PAID && $record->allowsMethod('pix') && $record->allowsMethod('credit_card')) {
            return 'Pix ou cartão (a definir)';
        }

        return ucfirst($record->payment_method?->getLabel() ?? '—');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayments::route('/'),
        ];
    }
}
