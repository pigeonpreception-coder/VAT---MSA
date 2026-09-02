<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the AuthorizationException render callback registered in
 * bootstrap/app.php -- the fix for red team finding RT-002
 * (docs/RED_TEAM_ASSESSMENT_2026-09-02.md): a plain
 * Illuminate\Auth\Access\AuthorizationException (thrown both by
 * TenantScope::requireTaxpayer() and every $this->authorize() gate denial)
 * fell through to Laravel's default exception handler, which leaks a full
 * stack trace and local filesystem path whenever APP_DEBUG=true. These
 * tests run under this environment's actual .env APP_DEBUG=true (no
 * phpunit.xml override exists for it -- confirmed), so they exercise
 * exactly the condition that used to leak.
 */
class AuthorizationExceptionRenderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** Holds neither invoices:read nor invoices:submit -- the fully-denied fixture. */
    private function developerPartner(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Developer Partner', 'email' => 'developer-'.Str::random(8).'@test.test',
            'password' => bcrypt('password'), 'role' => 'DEVELOPER_PARTNER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    public function test_a_json_authorization_denial_returns_a_clean_body_with_no_stack_trace(): void
    {
        $this->assertTrue(config('app.debug'), 'This test is only meaningful with APP_DEBUG=true -- otherwise it cannot prove the fix.');

        $response = $this->actingAs($this->developerPartner())->getJson('/api/v1/invoices');

        $response->assertStatus(403);
        $response->assertJson(['code' => 'FORBIDDEN']);
        $body = $response->getContent();
        $this->assertStringNotContainsString('"trace"', $body);
        $this->assertStringNotContainsString('.php', $body);
        $this->assertStringNotContainsString('Illuminate\\', $body);
    }

    public function test_an_html_authorization_denial_returns_the_clean_403_view_with_no_stack_trace(): void
    {
        $this->assertTrue(config('app.debug'), 'This test is only meaningful with APP_DEBUG=true -- otherwise it cannot prove the fix.');

        $response = $this->actingAs($this->developerPartner())->get('/invoices');

        $response->assertStatus(403);
        $response->assertViewIs('errors.403');
        $response->assertSee('Access denied');
        $body = $response->getContent();
        $this->assertStringNotContainsString('.php', $body);
        $this->assertStringNotContainsString('Illuminate\\', $body);
        $this->assertStringNotContainsString('Stack trace', $body);
    }
}
