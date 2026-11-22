<?php

namespace App\Models;

use App\Enums\GatewayEnum;
use App\Models\Scopes\UserScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Credenciais de um provedor para uma conta (tenant). Uma linha por (conta, gateway). */
#[ScopedBy([UserScope::class])]
class GatewayCredential extends Model
{
    use HasUuids;

    protected $fillable = ['account_id', 'gateway', 'credentials', 'enabled', 'last_validated_at', 'last_validation_error'];

    protected $hidden = ['credentials'];

    protected $casts = [
        'gateway' => GatewayEnum::class,
        'credentials' => 'encrypted:array',
        'enabled' => 'boolean',
        'last_validated_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $item) {
            $item->account_id = $item->account_id ?: app(TenantContext::class)->requireAccountId();
        });
    }

    /** Último trecho do segredo para identificar a credencial na tela sem expô-la. */
    public function hint(string $field): ?string
    {
        $value = (string) ($this->credentials[$field] ?? '');

        return $value === '' ? null : '••••'.substr($value, -4);
    }
}
