<?php

namespace App\Models;

use App\Enums\PaymentMethodEnum;
use App\Models\Scopes\UserScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
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
        'account_id',
    ];

    protected $casts = [
        'duration' => 'integer',
        'card_installments_enabled' => 'boolean',
        'card_max_installments' => 'integer',
        'pix_fee_percent' => 'float',
        'card_fee_percent_1x' => 'float',
        'card_fee_percent_2_6x' => 'float',
        'card_fee_percent_7_12x' => 'float',
    ];

    /** Teto de parcelas aceito pelo checkout (e opção máxima no painel). */
    public const MAX_INSTALLMENTS = 12;

    /**
     * Tarifa (%) do meio de pagamento; null = ainda não configurada (só cartão).
     * Cartão por faixa de parcelas: 1x, 2–6x, 7–12x.
     */
    public function feePercentFor(PaymentMethodEnum $method, ?int $installments = null): ?float
    {
        if ($method === PaymentMethodEnum::PIX) {
            return (float) $this->pix_fee_percent;
        }

        $n = max(1, (int) $installments);

        return match (true) {
            $n === 1 => $this->card_fee_percent_1x,
            $n <= 6 => $this->card_fee_percent_2_6x,
            default => $this->card_fee_percent_7_12x,
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

    protected static function boot()
    {
        parent::boot();

        // Intercepta antes de criar um novo item
        static::creating(function ($item) {

            $item->account_id = auth()->user()->account->id;

            if (empty($item->api_token)) {
                // Gerar o token de acesso quando o modelo for criado pela primeira vez
                $item->api_token = Str::random(30); // Você pode ajustar o tamanho do token conforme necessário
            }
        });

    }
}
