<?php

namespace App\Models;

use App\Enums\PaymentMethodEnum;
use App\Enums\StatusPaymentEnum;
use App\Models\Scopes\UserScope;
use App\Support\ChargeResult;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[ScopedBy([UserScope::class])]
class Payment extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'id',
        'ide',
        'card_order_id',
        'card_attempt_key',
        'card_attempt_started_at',
        'card_brand',
        'card_last_four',
        'installments',
        'max_installments',
        'fee_percent',
        'fee_amount',
        'acquirer_tid',
        'acquirer_nsu',
        'acquirer_auth_code',
        'customer',
        'qrCode',
        'copyPaste',
        'amount',

        'payment_method',
        'payment_methods',
        'refExternal',
        'status',
        'duration',
        'expirationDate',
        'paymentDate',
        'redirect_url',
        'notification_url',

        'notification_attempts',
        'last_notification_attempt',
        'is_notified',
        'account_id',
    ];

    protected static function boot()
    {
        parent::boot();

        // Intercepta antes de criar um novo item
        static::creating(function ($item) {
            if (! $item->account_id) {
                $item->account_id = auth()->user()->account->id;
            }
        });

    }

    protected $casts = [
        'status' => StatusPaymentEnum::class,
        'payment_method' => PaymentMethodEnum::class,
        'payment_methods' => 'array',
        'fee_percent' => 'float',
        'fee_amount' => 'float',
        'customer' => 'encrypted:array',
        'card_attempt_started_at' => 'datetime',
    ];

    protected $hidden = ['customer'];

    /** Meios habilitados no checkout; payments legados (null) aceitam PIX e cartão. */
    public function allowsMethod(string $method): bool
    {
        return in_array($method, $this->payment_methods ?? ['pix', 'credit_card'], true);
    }

    /** Tarifa histórica do Pix, antes de ser configurável por conta (pagamentos PAGOS sem snapshot). */
    public const LEGACY_PIX_FEE_PERCENT = 0.75;

    /**
     * Receita líquida (valor − tarifa) dos pagamentos PAGOS da query: usa o `fee_amount` gravado em cada um;
     * sem snapshot, Pix legado usa 0,75 % e cartão não tem tarifa.
     */
    public static function netRevenue(Builder $query): float
    {
        $legacyPix = self::LEGACY_PIX_FEE_PERCENT / 100;

        return (float) $query->where('status', StatusPaymentEnum::PAID)
            ->selectRaw("SUM(amount - COALESCE(fee_amount, CASE WHEN payment_method = 'PIX' THEN ROUND(amount * {$legacyPix}, 2) ELSE 0 END)) as net")
            ->value('net');
    }

    private function accountConfig(): ?Config
    {
        return Config::withoutGlobalScopes()->where('account_id', $this->account_id)->first();
    }

    /**
     * Tarifa a gravar quando o pagamento é confirmado (snapshot da Config da conta naquele momento).
     * Sem tarifa configurada (cartão) grava null.
     *
     * @return array{fee_percent: ?float, fee_amount: ?float}
     */
    public function feeAttributesFor(PaymentMethodEnum $method, ?int $installments = null): array
    {
        $percent = $this->accountConfig()?->feePercentFor($method, $installments);

        return [
            'fee_percent' => $percent,
            'fee_amount' => $percent === null ? null : round($this->amount * $percent / 100, 2),
        ];
    }

    /**
     * Tarifa para exibição no painel.
     * Pago: o snapshot gravado (legado Pix sem snapshot = 0,75%). Não pago: estimativa pela Config atual.
     *
     * @return array{percent: ?float, amount: ?float, estimated: bool}
     */
    public function feeSummary(): array
    {
        if ($this->fee_percent !== null) {
            return ['percent' => $this->fee_percent, 'amount' => $this->fee_amount, 'estimated' => false];
        }

        if ($this->status === StatusPaymentEnum::PAID) {
            if ($this->payment_method === PaymentMethodEnum::PIX) {
                $percent = self::LEGACY_PIX_FEE_PERCENT;

                return ['percent' => $percent, 'amount' => round($this->amount * $percent / 100, 2), 'estimated' => false];
            }

            return ['percent' => null, 'amount' => null, 'estimated' => false];
        }

        $estimate = $this->feeAttributesFor($this->payment_method ?? PaymentMethodEnum::PIX, $this->installments);

        return ['percent' => $estimate['fee_percent'], 'amount' => $estimate['fee_amount'], 'estimated' => true];
    }

    /** Parcelas máximas aceitas no cartão (snapshot da Config; legado sem snapshot usa o teto). */
    public function installmentLimit(): int
    {
        return max(1, min(Config::MAX_INSTALLMENTS, $this->max_installments ?? Config::MAX_INSTALLMENTS));
    }

    public function markCardPaid(ChargeResult $result): void
    {
        $this->update($this->feeAttributesFor(PaymentMethodEnum::CREDIT_CARD, $result->installments) + [
            'status' => StatusPaymentEnum::PAID,
            'paymentDate' => now(),
            'payment_method' => PaymentMethodEnum::CREDIT_CARD,
            'card_order_id' => $result->orderId ?? $this->card_order_id,
            'card_brand' => $result->brand,
            'card_last_four' => $result->lastFour,
            'installments' => $result->installments,
            'acquirer_tid' => $result->tid,
            'acquirer_nsu' => $result->nsu,
            'acquirer_auth_code' => $result->authCode,
            'card_attempt_key' => null,
            'card_attempt_started_at' => null,
        ]);
    }

    public function releaseCardAttempt(): void
    {
        $this->update(['card_attempt_key' => null, 'card_attempt_started_at' => null]);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
