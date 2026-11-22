<?php

namespace App\Filament\Pages;

use App\Enums\GatewayEnum;
use App\Enums\PaymentMethodEnum;
use App\Enums\StatusPaymentEnum;
use App\Models\GatewayCredential;
use App\Models\Payment;
use App\Services\Gateways\CredentialTester;
use App\Services\Gateways\GatewayManager;
use App\Support\TenantContext;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

/**
 * Credenciais dos provedores DESTA conta. Os segredos nunca voltam para a tela: em branco = manter o atual.
 */
class GatewayCredentialsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationLabel = 'Gateways';

    protected static ?string $title = 'Gateways de pagamento';

    protected static ?string $slug = 'gateways';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.gateway-credentials';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $rows = GatewayCredential::get()->keyBy(fn ($r) => $r->gateway->value);
        $pagarme = $rows->get('PAGARME');
        $mp = $rows->get('MERCADOPAGO');

        $this->form->fill([
            'pagarme_enabled' => $pagarme?->enabled ?? true,
            'pagarme_user' => null,
            'pagarme_password' => null,
            'pagarme_public_key' => $pagarme?->credentials['public_key'] ?? null,
            'mp_enabled' => $mp?->enabled ?? true,
            'mp_access_token' => null,
            'mp_public_key' => $mp?->credentials['public_key'] ?? null,
            'mp_webhook_secret' => null,
        ]);
    }

    private function accountId(): string
    {
        return app(TenantContext::class)->requireAccountId();
    }

    private function row(GatewayEnum $gateway): ?GatewayCredential
    {
        return GatewayCredential::where('gateway', $gateway->value)->first();
    }

    private function statusLine(GatewayEnum $gateway): HtmlString
    {
        $row = $this->row($gateway);
        $tenant = app(GatewayManager::class)->forTenant();
        $viaEnv = ! $row && $tenant->gateway($gateway)->isConfigured();

        if ($viaEnv) {
            return new HtmlString('<span>Usando as credenciais do servidor (.env). Cadastre as suas abaixo para migrar.</span>');
        }

        if (! $row) {
            return new HtmlString('<span>Não configurado.</span>');
        }

        $parts = [$row->enabled ? 'Ativo' : 'Desativado'];
        $secretField = $gateway === GatewayEnum::PAGARME ? 'user' : 'access_token';
        $parts[] = 'chave '.e($row->hint($secretField) ?? '—');
        $parts[] = match (true) {
            $row->last_validation_error !== null => 'última validação falhou: '.e($row->last_validation_error),
            $row->last_validated_at !== null => 'validada em '.$row->last_validated_at->timezone(config('app.timezone'))->format('d/m/Y H:i'),
            default => 'ainda não validada',
        };
        $env = $this->isTest($gateway, $row->credentials);
        if ($env !== null) {
            $parts[] = $env ? 'ambiente de TESTE' : 'ambiente de produção';
        }

        return new HtmlString('<span>'.implode(' · ', $parts).'</span>');
    }

    private function isTest(GatewayEnum $gateway, array $credentials): ?bool
    {
        return match ($gateway) {
            GatewayEnum::PAGARME => filled($credentials['user'] ?? null) ? str_contains($credentials['user'], '_test_') : null,
            GatewayEnum::MERCADOPAGO => filled($credentials['access_token'] ?? null) ? str_starts_with($credentials['access_token'], 'TEST-') : null,
        };
    }

    public function form(Form $form): Form
    {
        $redundancy = app(GatewayManager::class)->forTenant()->hasRedundancy();

        return $form
            ->statePath('data')
            ->columns(1)
            ->schema([
                Placeholder::make('redundancy')
                    ->hiddenLabel()
                    ->content(new HtmlString($redundancy
                        ? '<strong>Redundância ativa.</strong> Se o Pagar.me falhar, o Mercado Pago assume as cobranças.'
                        : '<strong>Sem redundância.</strong> Com apenas um gateway configurado, se ele cair você deixa de receber até voltar. Configure os dois para ter failover.')),

                Section::make('Pagar.me')
                    ->description('Gateway primário. Os valores abaixo ficam criptografados; em branco, mantém o atual.')
                    ->icon('heroicon-o-credit-card')
                    ->columns(2)
                    ->schema([
                        Placeholder::make('pagarme_status')->hiddenLabel()->content(fn () => $this->statusLine(GatewayEnum::PAGARME))->columnSpanFull(),
                        Toggle::make('pagarme_enabled')->label('Ativo')->columnSpanFull(),
                        TextInput::make('pagarme_user')->label('Chave secreta (sk_...)')->password()->autocomplete(false)->maxLength(255),
                        TextInput::make('pagarme_password')->label('Senha da API (se houver)')->password()->autocomplete(false)->maxLength(255),
                        TextInput::make('pagarme_public_key')->label('Chave pública (pk_...) — habilita o cartão')->maxLength(255)->columnSpanFull()
                            ->helperText('Deve ser do mesmo ambiente (teste/produção) da chave secreta.'),
                    ]),

                Section::make('Mercado Pago (redundância)')
                    ->description('Usado só quando o Pagar.me falha — ou como gateway único se você não configurar o Pagar.me.')
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->columns(2)
                    ->schema([
                        Placeholder::make('mp_status')->hiddenLabel()->content(fn () => $this->statusLine(GatewayEnum::MERCADOPAGO))->columnSpanFull(),
                        Toggle::make('mp_enabled')->label('Ativo')->columnSpanFull(),
                        TextInput::make('mp_access_token')->label('Access token')->password()->autocomplete(false)->maxLength(255),
                        TextInput::make('mp_webhook_secret')->label('Segredo do webhook')->password()->autocomplete(false)->maxLength(255),
                        TextInput::make('mp_public_key')->label('Chave pública — habilita o cartão')->maxLength(255)->columnSpanFull(),
                        Placeholder::make('mp_webhook_url')
                            ->label('URL do webhook (cadastre no painel do Mercado Pago, tópico de ordens)')
                            ->content(fn () => route('webhooks.mercadopago', ['account' => $this->accountId()]))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')->label('Salvar e validar')->submit('save'),
        ];
    }

    /**
     * Há cobrança que ainda depende deste gateway? Desativá-lo agora a deixaria sem consulta de status:
     * PIX pendente (mesmo vencido, até o scheduler resolver), cartão em tentativa, e o PIX remanescente de cartão
     * pago, que é vigiado até expirar+5 min para alertar estorno manual.
     */
    private function openPaymentsOn(GatewayEnum $gateway): int
    {
        return Payment::where(fn ($q) => $q
            ->where(fn ($q) => $q->where('status', StatusPaymentEnum::PENDING)->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('pix_gateway', $gateway->value)->whereNotNull('ide'))
                ->orWhere(fn ($q) => $q->where('card_gateway', $gateway->value)->whereNotNull('card_attempt_key'))))
            ->orWhere(fn ($q) => $q->where('status', StatusPaymentEnum::PAID)
                ->where('payment_method', PaymentMethodEnum::CREDIT_CARD)
                ->where('pix_gateway', $gateway->value)->whereNotNull('ide')
                ->where('expirationDate', '>=', now()->subMinutes(5))))
            ->count();
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $this->persist(GatewayEnum::PAGARME, $data['pagarme_enabled'], [
            'user' => $data['pagarme_user'],
            'password' => $data['pagarme_password'],
            'public_key' => $data['pagarme_public_key'],
        ], 'user');

        $this->persist(GatewayEnum::MERCADOPAGO, $data['mp_enabled'], [
            'access_token' => $data['mp_access_token'],
            'webhook_secret' => $data['mp_webhook_secret'],
            'public_key' => $data['mp_public_key'],
        ], 'access_token');

        $this->mount();
    }

    /** @param array<string, ?string> $input campos em branco mantêm o valor guardado (exceto a chave pública, que se pode apagar) */
    private function persist(GatewayEnum $gateway, bool $enabled, array $input, string $requiredField): void
    {
        $row = $this->row($gateway);
        $current = $row?->credentials ?? [];

        $merged = $current;
        foreach ($input as $key => $value) {
            if ($key === 'public_key') {
                $merged[$key] = filled($value) ? $value : null;
            } elseif (filled($value)) {
                $merged[$key] = $value;
            }
        }

        if (! $row && blank($merged[$requiredField] ?? null)) {
            return; // nada preenchido para este gateway
        }

        if (blank($merged[$requiredField] ?? null)) {
            Notification::make()->title($gateway->name.': a chave principal é obrigatória.')->danger()->send();

            return;
        }

        if ($row && $row->enabled && ! $enabled && ($open = $this->openPaymentsOn($gateway)) > 0) {
            Notification::make()->title("Não dá para desativar agora: {$open} cobrança(s) em andamento dependem deste gateway.")
                ->body('Aguarde elas expirarem ou serem pagas.')->danger()->send();

            return;
        }

        $label = $gateway === GatewayEnum::PAGARME ? 'Pagar.me' : 'Mercado Pago';
        $changedSecrets = $merged !== $current;

        // Valida ANTES de gravar: uma credencial colada errada não pode substituir a que funciona.
        $ok = true;
        $error = null;
        if ($enabled && ($changedSecrets || ! $row || ! $row->last_validated_at)) {
            [$ok, $error] = app(CredentialTester::class)->test($gateway, $merged);
        }

        if (! $ok && $row) {
            Notification::make()->title("{$label}: a credencial nova foi recusada; mantivemos a anterior.")->body($error)->danger()->send();

            return;
        }

        if (! $ok) {
            // gateway novo e credencial ruim: guarda o rascunho DESATIVADO (nada cobra com ela) para corrigir depois
            GatewayCredential::updateOrCreate(['gateway' => $gateway->value], [
                'credentials' => $merged, 'enabled' => false, 'last_validated_at' => null, 'last_validation_error' => $error,
            ]);
            Notification::make()->title("{$label}: salva desativada, a validação falhou.")->body($error)->warning()->send();

            return;
        }

        GatewayCredential::updateOrCreate(['gateway' => $gateway->value], [
            'credentials' => $merged,
            'enabled' => $enabled,
        ] + ($enabled && ($changedSecrets || ! $row || ! $row->last_validated_at)
            ? ['last_validated_at' => now(), 'last_validation_error' => null]
            : ($changedSecrets ? ['last_validated_at' => null, 'last_validation_error' => null] : [])));

        if ($enabled && ($changedSecrets || ! $row || ! $row->last_validated_at)) {
            Notification::make()->title("{$label}: credencial validada e salva.")->success()->send();
        }
    }
}
