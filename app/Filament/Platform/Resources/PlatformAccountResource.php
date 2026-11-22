<?php

namespace App\Filament\Platform\Resources;

use App\Enums\StatusPaymentEnum;
use App\Filament\Platform\Resources\PlatformAccountResource\Pages;
use App\Models\Account;
use App\Models\User;
use App\Notifications\AccountInvitation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Todas as contas da plataforma (Account não tem escopo de tenant: só super-admins chegam aqui). */
class PlatformAccountResource extends Resource
{
    protected static ?string $model = Account::class;

    protected static ?string $slug = 'accounts';

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $modelLabel = 'conta';

    protected static ?string $pluralModelLabel = 'Contas';

    protected static ?string $navigationLabel = 'Contas';

    /** Números por conta via subconsultas: `Payment` tem escopo de tenant e aqui não há tenant. */
    public static function getEloquentQuery(): Builder
    {
        return Account::query()->addSelect([
            'accounts.*',
            'owner_email' => User::select('email')->whereColumn('account_id', 'accounts.id')->orderBy('id')->limit(1),
            'owner_pending' => User::selectRaw('count(*)')->whereColumn('account_id', 'accounts.id')->whereNull('password_set_at'),
            'last_api_use' => DB::table('configs')->select('api_token_last_used_at')->whereColumn('account_id', 'accounts.id')->limit(1),
            'payments_total' => DB::table('payments')->selectRaw('count(*)')->whereColumn('account_id', 'accounts.id'),
            'paid_volume' => DB::table('payments')->selectRaw('coalesce(sum(amount), 0)')->whereColumn('account_id', 'accounts.id')
                ->where('status', StatusPaymentEnum::PAID->value),
        ]);
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Nova conta')
                ->description('Criamos a conta e enviamos um convite por e-mail para o responsável definir a senha.')
                ->schema([
                    Forms\Components\TextInput::make('name')->label('Nome da empresa')->required()->maxLength(255),
                    Forms\Components\TextInput::make('owner_name')->label('Nome do responsável')->required()->maxLength(255),
                    Forms\Components\TextInput::make('owner_email')->label('E-mail do responsável')->email()->required()
                        ->unique('users', 'email')->maxLength(255),
                ])->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Conta')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('owner_email')->label('Responsável'),
                Tables\Columns\TextColumn::make('status')->label('Situação')->badge()
                    ->state(fn (Account $a) => $a->suspended_at ? 'Suspensa' : ($a->owner_pending > 0 ? 'Convite pendente' : 'Ativa'))
                    ->color(fn (string $state) => match ($state) {
                        'Suspensa' => 'danger',
                        'Convite pendente' => 'warning',
                        default => 'success',
                    }),
                Tables\Columns\TextColumn::make('last_api_use')->label('Último uso da API')->dateTime('d/m/Y H:i')->placeholder('nunca')->since()->toggleable(),
                Tables\Columns\TextColumn::make('payments_total')->label('Cobranças')->numeric(),
                Tables\Columns\TextColumn::make('paid_volume')->label('Volume pago')->money('BRL'),
                Tables\Columns\TextColumn::make('created_at')->label('Criada em')->dateTime('d/m/Y')->toggleable(),
            ])
            ->actions([
                Tables\Actions\Action::make('resendInvite')
                    ->label('Reenviar convite')->icon('heroicon-o-envelope')
                    ->visible(fn (Account $a) => $a->owner_pending > 0)
                    ->action(fn (Account $a) => static::sendInvite($a)),
                Tables\Actions\Action::make('suspend')
                    ->label('Suspender')->icon('heroicon-o-pause-circle')->color('danger')
                    ->visible(fn (Account $a) => $a->suspended_at === null && $a->id !== auth()->user()?->account_id)
                    ->requiresConfirmation()
                    ->modalHeading('Suspender conta?')
                    ->modalDescription('A API da conta passa a recusar novas cobranças (403). Checkouts já criados continuam pagáveis até expirar.')
                    ->action(fn (Account $a) => $a->forceFill(['suspended_at' => now()])->save()),
                Tables\Actions\Action::make('reactivate')
                    ->label('Reativar')->icon('heroicon-o-play-circle')->color('success')
                    ->visible(fn (Account $a) => $a->suspended_at !== null)
                    ->requiresConfirmation()
                    ->action(fn (Account $a) => $a->forceFill(['suspended_at' => null])->save()),
            ]);
    }

    /** Envia o convite ao primeiro usuário da conta; se o e-mail falhar, mostra o link para o super-admin repassar. */
    public static function sendInvite(Account $account): void
    {
        $owner = User::where('account_id', $account->id)->whereNull('password_set_at')->orderBy('id')->first();

        if (! $owner) {
            Notification::make()->title('Esta conta não tem convite pendente.')->warning()->send();

            return;
        }

        try {
            $owner->notify(new AccountInvitation);
            Notification::make()->title("Convite enviado para {$owner->email}.")->success()->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()
                ->title('Não foi possível enviar o e-mail')
                ->body('Repasse este link ao responsável (vale '.AccountInvitation::VALID_DAYS.' dias, uso único): '.AccountInvitation::urlFor($owner))
                ->warning()->persistent()->send();
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlatformAccounts::route('/'),
            'create' => Pages\CreatePlatformAccount::route('/create'),
        ];
    }
}
