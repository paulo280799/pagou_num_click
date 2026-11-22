<?php

namespace Tests\Feature\Middleware;

use App\Models\Account;
use App\Models\Config;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiTokenHashTest extends TestCase
{
    use RefreshDatabase;

    private function config(string $token = 'tok-secreto-123'): Config
    {
        return Config::factory()->create(['account_id' => Account::factory()->create()->id, 'api_token' => $token]);
    }

    private function withBearer(string $token): \Illuminate\Testing\TestResponse
    {
        Http::fake();

        return $this->postJson('/api/create-payment', [], ['Authorization' => 'Bearer '.$token]);
    }

    public function test_database_never_holds_the_plain_token(): void
    {
        $config = $this->config('tok-secreto-123');

        $row = DB::table('configs')->where('id', $config->id)->first();

        $this->assertSame(hash('sha256', 'tok-secreto-123'), $row->api_token_hash);
        $this->assertSame('tok-secr', $row->api_token_prefix);
        $this->assertStringNotContainsString('tok-secreto-123', json_encode($row));
        $this->assertNull(Config::withoutGlobalScopes()->find($config->id)->api_token);
        $this->assertStringNotContainsString('api_token_hash', json_encode(Config::withoutGlobalScopes()->find($config->id)));
    }

    public function test_created_config_gets_a_prefixed_token_shown_only_on_that_instance(): void
    {
        $account = Account::factory()->create();
        $this->actingAs(User::factory()->create(['account_id' => $account->id]));

        $config = Config::create(['duration' => '900', 'notification_url' => 'https://x.test/n', 'redirect_url' => 'https://x.test/r']);

        $this->assertStringStartsWith('pnc_', $config->api_token);
        $this->assertSame(44, strlen($config->api_token));
        $this->assertSame(Config::findByToken($config->api_token)->id, $config->id);
        $this->assertNull(Config::withoutGlobalScopes()->find($config->id)->api_token);
    }

    public function test_hash_of_the_token_is_not_accepted_as_a_token(): void
    {
        $this->config('tok-secreto-123');

        $this->withBearer(hash('sha256', 'tok-secreto-123'))->assertStatus(401);
    }

    public function test_rotation_keeps_previous_token_only_during_the_grace_window(): void
    {
        $config = $this->config('tok-antigo');
        $new = $config->rotateToken();

        $this->assertNotNull(Config::findByToken($new));
        $this->assertNotNull(Config::findByToken('tok-antigo'), 'janela de rotação');

        $this->travel(config('integration.api_token_rotation_grace_minutes') + 1)->minutes();

        $this->assertNotNull(Config::findByToken($new));
        $this->assertNull(Config::findByToken('tok-antigo'), 'janela expirada');
    }

    public function test_revoke_now_kills_the_previous_token_immediately(): void
    {
        $config = $this->config('tok-antigo');
        $new = $config->rotateToken(revokeNow: true);

        $this->assertNull(Config::findByToken('tok-antigo'));
        $this->assertNotNull(Config::findByToken($new));
        $this->withBearer('tok-antigo')->assertStatus(401);
    }

    public function test_last_used_is_recorded_on_a_valid_call(): void
    {
        $config = $this->config('tok-secreto-123');
        $this->assertNull($config->fresh()->api_token_last_used_at);

        $this->withBearer('tok-secreto-123');

        $this->assertNotNull(Config::withoutGlobalScopes()->find($config->id)->api_token_last_used_at);
    }

    /** DDL no MySQL faz commit implícito: o teste limpa o que gravou em vez de contar com o rollback. */
    public function test_migration_backfills_hash_and_prefix_for_existing_tokens(): void
    {
        $migration = require database_path('migrations/2026_11_22_110000_hash_api_tokens_on_configs_table.php');
        $accountId = (string) \Illuminate\Support\Str::uuid();

        try {
            $migration->down();
            DB::table('accounts')->insert(['id' => $accountId, 'name' => 'Conta', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('configs')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(), 'duration' => '900', 'notification_url' => 'x', 'redirect_url' => 'x',
                'api_token' => 'legado-1234567890', 'account_id' => $accountId, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $migration->up();

            $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('configs', 'api_token'));
            $this->assertSame($accountId, Config::findByToken('legado-1234567890')?->account_id, 'token em uso segue valendo');
            $this->assertSame('legado-1', DB::table('configs')->where('account_id', $accountId)->value('api_token_prefix'));
        } finally {
            if (! \Illuminate\Support\Facades\Schema::hasColumn('configs', 'api_token_hash')) {
                $migration->up();
            }
            DB::table('configs')->where('account_id', $accountId)->delete();
            DB::table('accounts')->where('id', $accountId)->delete();
        }
    }
}
