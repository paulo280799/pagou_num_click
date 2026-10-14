<?php

namespace App\Models;

use App\Enums\PaymentMethodEnum;
use App\Enums\StatusPaymentEnum;
use App\Models\Scopes\UserScope;
use App\Support\ChargeResult;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
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
        'acquirer_tid',
        'acquirer_nsu',
        'acquirer_auth_code',
        'customer',
        'qrCode',
        'copyPaste',
        'amount',

        'payment_method',
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
        'customer' => 'encrypted:array',
        'card_attempt_started_at' => 'datetime',
    ];

    protected $hidden = ['customer'];

    public function markCardPaid(ChargeResult $result): void
    {
        $this->update([
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
