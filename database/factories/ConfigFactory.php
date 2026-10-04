<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Config;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Config>
 */
class ConfigFactory extends Factory
{
    public function definition(): array
    {
        return [
            'duration' => '900',
            'notification_url' => fake()->url(),
            'redirect_url' => fake()->url(),
            'api_token' => Str::random(30),
            'account_id' => Account::factory(),
        ];
    }

    /**
     * Config::boot() unconditionally overwrites account_id from the
     * authenticated user on every create (see App\Models\Config), so
     * fixture creation bypasses model events rather than requiring an
     * authenticated user for every test that needs a Config row.
     */
    public function create($attributes = [], ?\Illuminate\Database\Eloquent\Model $parent = null)
    {
        return Config::withoutEvents(fn () => parent::create($attributes, $parent));
    }
}
