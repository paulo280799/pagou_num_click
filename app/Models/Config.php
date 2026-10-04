<?php

namespace App\Models;

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
        'account_id',
    ];

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
