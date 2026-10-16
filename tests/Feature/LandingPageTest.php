<?php

namespace Tests\Feature;

use Tests\TestCase;

class LandingPageTest extends TestCase
{
    public function test_landing_page_renders(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Pagou num Click')
            ->assertSee('paga num click');
    }

    public function test_cta_falls_back_to_anchor_without_contact_url(): void
    {
        config(['services.landing.contact_url' => null]);

        $this->get('/')->assertSee('href="#topo"', false);
    }

    public function test_cta_uses_configured_contact_url(): void
    {
        config(['services.landing.contact_url' => 'https://wa.me/5511999999999']);

        $this->get('/')->assertSee('href="https://wa.me/5511999999999"', false);
    }

    public function test_landing_mentions_card_and_payment_methods(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('PIX e cartão')
            ->assertSee('Cartão de crédito')
            ->assertSee('payment_methods', false)
            ->assertSee('credit_card', false);
    }

    public function test_landing_links_to_api_docs(): void
    {
        $this->get('/')->assertSee('href="'.route('api.docs').'"', false);
    }

    public function test_landing_presents_dashboard_and_report(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('id="painel"', false)
            ->assertSee('href="#painel"', false)
            ->assertSee('Dashboard e relatório')
            ->assertSee('Baixar relatório')
            ->assertSee('relatório em CSV');
    }
}
