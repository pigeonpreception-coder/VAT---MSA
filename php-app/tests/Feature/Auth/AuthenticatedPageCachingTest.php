<?php

namespace Tests\Feature\Auth;

use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers App\Http\Middleware\PreventAuthenticatedPageCaching -- the fix for
 * red team finding RT-001 (docs/RED_TEAM_ASSESSMENT_2026-09-02.md): a
 * `no-store` Cache-Control on every authenticated response so a browser's
 * back-forward cache cannot replay an authenticated page after logout.
 */
class AuthenticatedPageCachingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function taxpayerOwner(): User
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => 'VAT-CACHE-0001', 'tin' => 'TIN-CACHE-0001',
            'legal_name' => 'Cache Test Co', 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => 'cache-taxpayer@test.test',
        ]);

        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Cache Test Owner', 'email' => 'cache-owner@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);
    }

    public function test_an_authenticated_dashboard_response_carries_a_no_store_cache_control_header(): void
    {
        $response = $this->actingAs($this->taxpayerOwner())->get('/dashboard');

        $response->assertOk();
        $response->assertHeader('Cache-Control');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_an_authenticated_invoices_list_response_carries_a_no_store_cache_control_header(): void
    {
        $response = $this->actingAs($this->taxpayerOwner())->get('/invoices');

        $response->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_the_login_page_is_not_forced_no_store_by_this_change(): void
    {
        // The fix is deliberately scoped to the `auth` route group -- the
        // unauthenticated /login page keeps Laravel's own default session
        // headers untouched, since it has nothing sensitive to protect from
        // bfcache replay and this assertion pins that scoping decision.
        $response = $this->get('/login');

        $response->assertOk();
        $this->assertStringNotContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_an_unauthenticated_request_to_a_protected_route_still_redirects_to_login(): void
    {
        // The new middleware must not interfere with the existing auth
        // redirect for a guest hitting a protected route.
        $this->get('/dashboard')->assertRedirect('/login');
    }
}
