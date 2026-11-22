<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['id', 'name', 'legal_name', 'document', 'contact_email', 'phone'];

    protected $casts = ['suspended_at' => 'datetime'];

    /** Conta suspensa: a API recusa novas cobranças (403); checkouts já criados continuam pagáveis até expirar. */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function user(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
