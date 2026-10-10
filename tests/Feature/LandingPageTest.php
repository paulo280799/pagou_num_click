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
}
