<?php

namespace Tests\Feature\Middleware;

use App\Models\Account;
use App\Models\Config;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiTokenMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_without_authorization_header_is_unauthorized(): void
    {
        $response = $this->postJson('/api/create-payment', []);

        $response->assertStatus(401)->assertJson(['error' => 'Unauthorized']);
    }

    public function test_request_with_unknown_token_is_unauthorized(): void
    {
        $response = $this->postJson('/api/create-payment', [], [
            'Authorization' => 'Bearer invalid-token',
        ]);

        $response->assertStatus(401)->assertJson(['error' => 'Unauthorized']);
    }

    public function test_request_with_valid_token_authenticates_as_accounts_first_user(): void
    {
        $account = Account::factory()->create();
        $user = User::factory()->create(['account_id' => $account->id]);
        $config = Config::factory()->create(['account_id' => $account->id, 'api_token' => 'valid-token']);

        Http::fake([
            '*/v5/orders' => Http::response([
                'id' => 'order_1',
                'amount' => 1000,
                'status' => 'pending',
                'charges' => [[
                    'payment_method' => 'pix',
                    'last_transaction' => ['qr_code_url' => 'https://qr.test', 'qr_code' => 'copy-paste'],
                ]],
            ], 200),
        ]);

        $response = $this->postJson('/api/create-payment', [
            'refExternal' => 'ref-1',
            'items' => [['amount' => '10.00', 'description' => 'Item', 'quantity' => 1]],
            'customer' => [
                'name' => 'Fulano',
                'email' => 'fulano@example.test',
                'type' => 'individual',
                'document' => '12345678900',
                'phones' => ['home_phone' => ['country_code' => '55', 'area_code' => '11', 'number' => '999999999']],
            ],
        ], [
            'Authorization' => 'Bearer valid-token',
        ]);

        $response->assertStatus(201);
        $this->assertAuthenticatedAs($user);
    }
}
