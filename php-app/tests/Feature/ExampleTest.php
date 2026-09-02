<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Replaces Laravel's default scaffold assertion (which expected a 200
     * welcome page at `/`) -- routes/web.php redirects `/` to `/dashboard`,
     * which itself requires authentication and (unfollowed, one hop at a
     * time, matching the test client's own behaviour) bounces an
     * unauthenticated visitor on to `/login` in turn.
     */
    public function test_the_root_route_redirects_toward_the_dashboard_which_then_requires_login(): void
    {
        $this->get('/')->assertRedirect('/dashboard');
        $this->get('/dashboard')->assertRedirect('/login');
    }
}
