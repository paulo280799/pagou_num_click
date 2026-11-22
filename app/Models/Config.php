<?php

namespace App\Models;

use App\Enums\GatewayEnum;
use App\Enums\PaymentMethodEnum;
use App\Models\Scopes\UserScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

#[ScopedBy([UserScope::class])]
class Config extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'duration',
        'notification_url',
        'redirect_url',
        'card_installments_enabled',
        'card_max_installments',
        'pix_fee_percent',
        'card_fee_percent_1x',
        'card_fee_percent_2_6x',
        'card_fee_percent_7_12x',
        'mp_pix_fee_percent',
        'mp_card_fee_percent_1x',
        'mp_card_fee_percent_2_6x',
        'mp_card_fee_percent_7_12x',
        'account_id',
    ];

    protected $hidden = ['api_token_hash', 'previous_api_token_hash'];

    protected $casts = [
        'api_token_last_used_at' => 'datetime',
        'previous_api_token_expires_at' => 'datetime',
        'duration' => 'integer',
        'card_installments_enabled' => 'boolean',
        'card_max_installments' => 'integer',
        'pix_fee_percent' => 'float',
        'card_fee_percent_1x' => 'float',
        'card_fee_percent_2_6x' => 'float',
        'card_fee_percent_7_12x' => 'float',
        'mp_pix_fee_percent' => 'float',
        'mp_card_fee_percent_1x' => 'float',
        'mp_card_fee_percent_2_6x' => 'float',
        'mp_card_fee_percent_7_12x' => 'float',
    ];

    /** Teto de parcelas aceito pelo checkout (e opção máxima no painel). */
    public const MAX_INSTALLMENTS = 12;

    /**
     * Tarifa (%) do meio de pagamento no gateway que processou; null = ainda não configurada
     * (cartão no Pagar.me; qualquer meio no Mercado Pago). Cartão por faixa de parcelas: 1x, 2–6x, 7–12x.
     */
    public function feePercentFor(PaymentMethodEnum $method, ?int $installments = null, GatewayEnum $gateway = GatewayEnum::PAGARME): ?float
    {
        $mp = $gateway === GatewayEnum::MERCADOPAGO;

        if ($method === PaymentMethodEnum::PIX) {
            return $mp ? $this->mp_pix_fee_percent : (float) $this->pix_fee_percent;
        }

        $n = max(1, (int) $installments);

        return match (true) {
            $n === 1 => $mp ? $this->mp_card_fee_percent_1x : $this->card_fee_percent_1x,
            $n <= 6 => $mp ? $this->mp_card_fee_percent_2_6x : $this->card_fee_percent_2_6x,
            default => $mp ? $this->mp_card_fee_percent_7_12x : $this->card_fee_percent_7_12x,
        };
    }

    /** Parcelas máximas oferecidas no cartão: 1 (à vista) quando o parcelamento está desligado. */
    public function effectiveMaxInstallments(): int
    {
        if (! $this->card_installments_enabled) {
            return 1;
        }

        return max(1, min(self::MAX_INSTALLMENTS, (int) $this->card_max_installments));
    }

    /** Token em texto puro, só em memória logo após gerar/rotacionar (o banco guarda apenas o hash). */
    protected ?string $plainApiToken = null;

    /**
     * Atribuir `api_token` grava só o hash SHA-256 e o prefixo; a leitura devolve o valor puro apenas na
     * instância que acabou de gerá-lo (para mostrar uma única vez ao usuário), nunca do banco.
     */
    protected function apiToken(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->plainApiToken,
            set: function (string $value) {
                $this->plainApiToken = $value;

                return [
                    'api_token_hash' => self::hashToken($value),
                    'api_token_prefix' => substr($value, 0, 8),
                ];
            },
        );
    }

    public static function generateToken(): string
    {
        return 'pnc_'.Str::random(40);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Config dona do token: o atual, ou o anterior enquanto a janela de rotação não expirou. */
    public static function findByToken(string $token): ?self
    {
        $hash = self::hashToken($token);

        return static::withoutGlobalScopes()
            ->where('api_token_hash', $hash)
            ->orWhere(fn ($q) => $q->where('previous_api_token_hash', $hash)->where('previous_api_token_expires_at', '>', now()))
            ->first();
    }

    /** Gera um token novo e devolve o valor puro (única vez em que ele existe). */
    public function rotateToken(bool $revokeNow = false): string
    {
        $keepPrevious = ! $revokeNow && $this->api_token_hash;

        $this->forceFill([
            'previous_api_token_hash' => $keepPrevious ? $this->api_token_hash : null,
            'previous_api_token_expires_at' => $keepPrevious ? now()->addMinutes((int) config('integration.api_token_rotation_grace_minutes')) : null,
            'api_token_last_used_at' => null,
            'api_token' => $token = self::generateToken(),
        ])->save();

        return $token;
    }

    /** Prefixo + máscara para exibir no painel sem revelar o token. */
    public function maskedApiToken(): ?string
    {
        return $this->api_token_prefix ? $this->api_token_prefix.'••••••••••••' : null;
    }

    protected static function boot()
    {
        parent::boot();

        // Intercepta antes de criar um novo item
        static::creating(function ($item) {

            $item->account_id = $item->account_id ?: app(TenantContext::class)->requireAccountId();

            if (empty($item->api_token_hash)) {
                // token gerado na criação; o valor puro fica em $item->api_token só nesta instância
                $item->api_token = self::generateToken();
            }
        });

    }
}
