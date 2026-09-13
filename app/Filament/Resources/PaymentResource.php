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
use App\Enums\StatusPaymentEnum;

use Filament\Infolists\Infolist;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\Section;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('5s')
            ->columns([
                Tables\Columns\TextColumn::make('id')->copyable()
                ->copyableState(fn (Payment $record): string => config('app.url')."/checkout/{$record->id}")
                ->description(fn (Payment $record): string => $record->refExternal),
                Tables\Columns\TextColumn::make('amount')->money('BRL'),
                Tables\Columns\TextColumn::make('status')->description(fn (Payment $record): string => $record->updated_at),

            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([

            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Section::make()
                ->schema([

                    TextEntry::make('id')
                        ->label('Número da transação'),
                        TextEntry::make('created_at')
                        ->label('Criado em'),
                ]),
                Section::make()
                ->schema([
                    TextEntry::make('amount')
                        ->label('Venda')
                        ->money('BRL')
                        ->columnSpanFull()
                        ->inlineLabel()
                        ->extraAttributes([
                            'class' => 'w-full flex justify-center items-center gap-2 text-center',
                        ]),
                    TextEntry::make('processing_fee')
                        ->label('Tarifa de processamento')
                        ->money('BRL')
                        ->default(fn ($record) => $record->amount * 0.0075)
                        ->columnSpanFull()
                        ->inlineLabel()
                        ->extraAttributes([
                            'class' => 'w-full flex justify-center items-center gap-2 text-center',
                        ]),
                    TextEntry::make('total')
                        ->label('Total')
                        ->money('BRL')
                        ->default(fn ($record) => $record->amount - ($record->amount * 0.0075))
                        ->columnSpanFull()
                        ->inlineLabel()
                        ->extraAttributes([
                            'class' => 'w-full flex justify-center items-center gap-2 text-center text-lg font-bold',
                        ]),
                ]),
                Section::make()
                ->schema([
                    TextEntry::make('refExternal')
                        ->label('Referencia externa')
                        ->columnSpanFull()
                        ->inlineLabel()
                        ->extraAttributes([
                            'class' => 'w-full flex justify-center items-center gap-2 text-center',
                        ]),
                ]),
                Section::make()
                ->schema([
                    TextEntry::make('status')
                        ->label('Status')
                        ->columnSpanFull()
                        ->inlineLabel()
                        ->extraAttributes([
                            'class' => 'w-full flex justify-center items-center gap-2 text-center'
                        ]),

                    TextEntry::make('paymentDate')
                        ->label('Data Pagameto')
                        ->columnSpanFull()
                        ->inlineLabel()
                        ->extraAttributes([
                            'class' => 'w-full flex justify-center items-center gap-2 text-center'
                        ]),
                ]),
            ])
            ->columns(2);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayments::route('/'),
        ];
    }
}
