<?php

namespace Tests\Feature\Models;

use App\Models\Account;
use App\Models\Config;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_id_is_auto_filled_from_authenticated_user_on_create(): void
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);
        $this->actingAs($user);

        $config = Config::create([
            'duration' => '900',
            'notification_url' => 'https://example.test/notify',
            'redirect_url' => 'https://example.test/redirect',
        ]);

        $this->assertSame($account->id, $config->account_id);
    }

    public function test_api_token_is_generated_when_not_provided(): void
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);
        $this->actingAs($user);

        $config = Config::create([
            'duration' => '900',
            'notification_url' => 'https://example.test/notify',
            'redirect_url' => 'https://example.test/redirect',
        ]);

        $this->assertNotEmpty($config->api_token);
    }

    public function test_api_token_is_kept_when_already_set_on_the_model(): void
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);
        $this->actingAs($user);

        $config = new Config([
            'duration' => '900',
            'notification_url' => 'https://example.test/notify',
            'redirect_url' => 'https://example.test/redirect',
        ]);
        $config->api_token = 'explicit-token';
        $config->save();

        $this->assertSame('explicit-token', $config->api_token);
    }

    public function test_create_without_authenticated_user_fails(): void
    {
        $this->expectException(\ErrorException::class);
        $this->expectExceptionMessage('Attempt to read property "account" on null');

        Config::create([
            'duration' => '900',
            'notification_url' => 'https://example.test/notify',
            'redirect_url' => 'https://example.test/redirect',
        ]);
    }

    public function test_user_scope_filters_configs_by_authenticated_users_account(): void
    {
        $account = Account::factory()->create();
        $otherAccount = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);

        Config::factory()->create(['account_id' => $account->id]);
        Config::factory()->create(['account_id' => $otherAccount->id]);

        $this->actingAs($user);

        $this->assertSame(1, Config::count());
        $this->assertSame(2, Config::withoutGlobalScopes()->count());
    }
}
