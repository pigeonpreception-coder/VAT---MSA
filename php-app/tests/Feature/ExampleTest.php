<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * `/` used to redirect straight to /dashboard (and on to /login for a
     * guest). It is now the public landing page every visitor sees first,
     * so a guest gets a 200 there -- while the dashboard itself still
     * requires authentication. Landing-page behaviour in full lives in
     * Tests\Feature\Site\PublicSiteTest.
     */
    public function test_the_root_route_is_the_public_landing_page_and_the_dashboard_still_requires_login(): void
    {
        $this->get('/')->assertOk();
        $this->get('/dashboard')->assertRedirect('/login');
    }
}
