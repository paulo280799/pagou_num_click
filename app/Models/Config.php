<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

use App\Models\Scopes\UserScope;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;

#[ScopedBy([UserScope::class])]
class Config extends Model
{
    use HasUuids;

    protected $fillable = [
        'duration',
        'notification_url',
        'redirect_url',
        'account_id'
    ];

    protected static function boot()
    {
        parent::boot();

        // Intercepta antes de criar um novo item
        static::creating(function ($item) {

            $item->account_id = auth()->user()->account->id;

            if (empty($model->api_token)) {
                // Gerar o token de acesso quando o modelo for criado pela primeira vez
                $item->api_token = Str::random(30); // Você pode ajustar o tamanho do token conforme necessário
            }
        });

    }
}
