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


    public function create_order(array $body){

         return Http::withBasicAuth($this->userIntegration, $this->passwordIntegration)
            ->post("{$this->urlIntegration}/v5/orders", $body);
    }


    public function get_order(string $order_id){
         return Http::withBasicAuth($this->userIntegration, $this->passwordIntegration)
            ->get("{$this->urlIntegration}/v5/orders/{$order_id}");

    }

    public function close_order(string $order_id){
         return Http::withBasicAuth($this->userIntegration, $this->passwordIntegration)
            ->patch("{$this->urlIntegration}/v5/orders/{$order_id}/closed");

    }

}
