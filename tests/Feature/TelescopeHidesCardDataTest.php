<?php

namespace Tests\Feature;

use Laravel\Telescope\Telescope;
use Tests\TestCase;

class TelescopeHidesCardDataTest extends TestCase
{
    public function test_telescope_hides_mercado_pago_token_and_authorization_header(): void
    {
        // o ClientRequestWatcher grava corpo e headers das chamadas de saída aos gateways
        $this->assertContains('token', Telescope::$hiddenRequestParameters);
        $this->assertContains('authorization', Telescope::$hiddenRequestHeaders);
    }

    public function test_telescope_hides_card_token_and_billing_address(): void
    {
        $hidden = Telescope::$hiddenRequestParameters;

        $this->assertContains('card_token', $hidden);
        $this->assertContains('billing_address', $hidden);
        $this->assertContains('_token', $hidden);
    }
}
