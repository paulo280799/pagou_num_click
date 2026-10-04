<?php

namespace App\Models;

use App\Enums\PaymentMethodEnum;
use App\Enums\StatusPaymentEnum;
use App\Models\Scopes\UserScope;
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
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
