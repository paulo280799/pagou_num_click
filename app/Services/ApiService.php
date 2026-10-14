<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class ApiService
{
    private string $urlIntegration;

    private string $userIntegration;

    private string $passwordIntegration;

    public function __construct()
    {
        $this->urlIntegration = config('integration.url_integration');
        $this->userIntegration = config('integration.user_integration');
        $this->passwordIntegration = config('integration.password_integration');
    }

    public function create_order(array $body)
    {

        return Http::withBasicAuth($this->userIntegration, $this->passwordIntegration)
            ->post("{$this->urlIntegration}/v5/orders", $body);
    }

    public function create_card_order(array $body, string $idempotencyKey)
    {
        return Http::withBasicAuth($this->userIntegration, $this->passwordIntegration)
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->timeout(config('integration.card_http_timeout'))
            ->post("{$this->urlIntegration}/v5/orders", $body);
    }

    public function get_orders_by_code(string $code)
    {
        return Http::withBasicAuth($this->userIntegration, $this->passwordIntegration)
            ->timeout(config('integration.card_http_timeout'))
            ->get("{$this->urlIntegration}/v5/orders", ['code' => $code]);
    }

    public function get_order(string $order_id)
    {
        return Http::withBasicAuth($this->userIntegration, $this->passwordIntegration)
            ->timeout(config('integration.card_http_timeout'))
            ->get("{$this->urlIntegration}/v5/orders/{$order_id}");

    }

    public function close_order(string $order_id)
    {
        return Http::withBasicAuth($this->userIntegration, $this->passwordIntegration)
            ->patch("{$this->urlIntegration}/v5/orders/{$order_id}/closed");

    }
}
