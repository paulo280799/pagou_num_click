<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Account extends Model
{
    use HasUuids;

    protected $fillable = ['id','name'];

    public function user(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
