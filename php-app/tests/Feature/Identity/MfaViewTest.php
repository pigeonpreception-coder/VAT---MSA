<?php

namespace Tests\Feature\Identity;

use App\Models\MfaTotpCredential;
use App\Models\User;
use App\Support\Access\Totp;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for MFA/step-up
 * (App\Http\Controllers\Identity\MfaViewController /
 * resources/views/security/mfa/index.blade.php) -- a genuine addition
 * (no corresponding page.tsx exists in the source), sitting alongside
 * MfaController's JSON API and exercising the same MfaService underneath.
 * See tests/Feature/Identity/MfaTest.php for the JSON API's own coverage
 * of the underlying enrol/verify/step-up/anti-replay contract.
 */
class MfaViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function makeUser(string $email, string $role = 'NAMRA_VAT_AUDITOR'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'MFA View Test User', 'email' => $email,
            'password' => bcrypt('password'), 'role' => $role, 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    public function test_the_security_page_requires_authentication(): void
    {
        $this->get('/security/mfa')->assertRedirect('/login');
    }

    public function test_a_fresh_user_sees_not_enabled(): void
    {
        $user = $this->makeUser('mfa-view-fresh@test.test');

        $response = $this->actingAs($user)->get('/security/mfa');

        $response->assertOk()->assertSee('Not enabled');
    }

    public function test_the_full_enrol_verify_and_step_up_flow_works_through_the_real_forms(): void
    {
        $user = $this->makeUser('mfa-view-flow@test.test');

        $this->actingAs($user)->post('/security/mfa')->assertRedirect(route('security.mfa'));
        $indexAfterEnroll = $this->actingAs($user)->get('/security/mfa');
        $indexAfterEnroll->assertOk()->assertSee('Finish enrolling');

        // The secret is only shown via flashed session data, which this
        // test's own follow-up request (a fresh actingAs() call) does not
        // carry forward -- read it directly from where enroll() persisted
        // it instead, the same row the flashed value came from.
        $credential = MfaTotpCredential::find($user->id);
        $this->assertNotNull($credential, 'A credential row should exist immediately after enrolling.');
        $secret = $credential->secret_base32;

        // Wrong code -- stays not-enabled with a flashed form error.
        $this->actingAs($user)->post('/security/mfa/verification', ['code' => '000000'])
            ->assertRedirect(route('security.mfa'))
            ->assertSessionHasErrors('code');

        // Correct code activates it.
        $code = Totp::generateCode($secret);
        $this->actingAs($user)->post('/security/mfa/verification', ['code' => $code])
            ->assertRedirect(route('security.mfa'))
            ->assertSessionHas('status');
        $this->actingAs($user)->get('/security/mfa')->assertOk()->assertSee('Enabled');

        // Step-up confirmation, once enrolled, works through its own form
        // too -- advance past the verification code's own 30-second step
        // so this is a genuinely fresh, unused code.
        Carbon::setTestNow(Carbon::now()->addSeconds(90));
        $stepUpCode = Totp::generateCode($secret);
        $this->actingAs($user)->post('/security/step-up', ['code' => $stepUpCode])
            ->assertRedirect(route('security.mfa'))
            ->assertSessionHas('status');
        $this->actingAs($user)->get('/security/mfa')->assertOk()->assertSee('Fresh (within the last few minutes)');
        Carbon::setTestNow();
    }

    /**
     * User's own explicit request (2026-09-15): the sidebar shows every
     * link to every authenticated user regardless of permission, so
     * SUPER_ADMIN (which lacks identity:read) still sees the Security
     * (MFA) link -- but MfaViewController's own authorize() gate is
     * untouched, so it still correctly 403s on direct access.
     */
    public function test_the_security_link_is_shown_even_without_identity_read_but_the_page_still_refuses_it(): void
    {
        $superAdmin = $this->makeUser('mfa-view-super-admin@test.test', 'SUPER_ADMIN');

        $dashboard = $this->actingAs($superAdmin)->get('/dashboard');
        $dashboard->assertOk()->assertSee(route('security.mfa'), false);

        $this->actingAs($superAdmin)->get('/security/mfa')->assertForbidden();
    }

    public function test_a_role_with_identity_read_sees_the_security_link(): void
    {
        $user = $this->makeUser('mfa-view-owner@test.test', 'TAXPAYER_OWNER');

        $dashboard = $this->actingAs($user)->get('/dashboard');
        $dashboard->assertOk()->assertSee(route('security.mfa'), false);
    }

    /**
     * Red-team punch list #6 (docs/RED_TEAM_OPEN_ITEMS_CONSOLIDATED_
     * 2026-09-15.md). The test session driver is 'array' (phpunit.xml),
     * so actingAs() never writes a real `sessions` row itself -- these rows
     * are inserted directly, the same way PasswordResetTest's own
     * `sessions`-wipe regression does, to stand in for what the real
     * 'database' driver (config/session.php) would have left behind.
     */
    public function test_the_security_page_lists_every_active_session_for_the_user_only(): void
    {
        $user = $this->makeUser('mfa-view-sessions@test.test');
        $otherUser = $this->makeUser('mfa-view-sessions-other@test.test');

        DB::table('sessions')->insert([
            ['id' => 'sess-list-a', 'user_id' => $user->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'Chrome on Windows', 'payload' => base64_encode('x'), 'last_activity' => time()],
            ['id' => 'sess-list-b', 'user_id' => $user->id, 'ip_address' => '10.0.0.2', 'user_agent' => 'Safari on iPhone', 'payload' => base64_encode('x'), 'last_activity' => time()],
            ['id' => 'sess-list-bystander', 'user_id' => $otherUser->id, 'ip_address' => '10.0.0.3', 'user_agent' => 'Bystander device', 'payload' => base64_encode('x'), 'last_activity' => time()],
        ]);

        $response = $this->actingAs($user)->get('/security/mfa');

        $response->assertOk()
            ->assertSee('Chrome on Windows')
            ->assertSee('Safari on iPhone')
            ->assertDontSee('Bystander device');
    }

    public function test_a_user_can_revoke_one_of_their_own_other_sessions_but_not_someone_elses(): void
    {
        $user = $this->makeUser('mfa-view-revoke@test.test');
        $otherUser = $this->makeUser('mfa-view-revoke-other@test.test');

        DB::table('sessions')->insert([
            ['id' => 'sess-revoke-mine', 'user_id' => $user->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'Old laptop', 'payload' => base64_encode('x'), 'last_activity' => time()],
            ['id' => 'sess-revoke-other', 'user_id' => $otherUser->id, 'ip_address' => '10.0.0.2', 'user_agent' => 'Bystander device', 'payload' => base64_encode('x'), 'last_activity' => time()],
        ]);

        $this->actingAs($user)->post('/security/sessions/sess-revoke-mine/revoke')
            ->assertRedirect(route('security.mfa'))
            ->assertSessionHas('status');
        $this->assertSame(0, DB::table('sessions')->where('id', 'sess-revoke-mine')->count());

        $this->actingAs($user)->post('/security/sessions/sess-revoke-other/revoke')
            ->assertRedirect(route('security.mfa'));
        $this->assertSame(1, DB::table('sessions')->where('id', 'sess-revoke-other')->count(), "One user must not be able to revoke another user's session.");
    }

    /**
     * The test session driver ('array') generates a fresh random ID on
     * every request that carries no session cookie -- so proving this
     * request's session is recognised as "current" needs the real
     * generated ID carried forward on the cookie, the same way a real
     * browser would, not just read from the container between requests.
     */
    private function currentSessionIdFor(User $user): string
    {
        $this->actingAs($user)->get('/security/mfa');

        return session()->getId();
    }

    public function test_revoking_your_own_current_session_is_refused_with_an_error_not_silently_applied(): void
    {
        $user = $this->makeUser('mfa-view-revoke-current@test.test');
        $currentSessionId = $this->currentSessionIdFor($user);

        DB::table('sessions')->insert([
            'id' => $currentSessionId, 'user_id' => $user->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'This device', 'payload' => base64_encode('x'), 'last_activity' => time(),
        ]);

        $this->withCookie(config('session.cookie'), $currentSessionId)
            ->actingAs($user)->post("/security/sessions/{$currentSessionId}/revoke")
            ->assertRedirect(route('security.mfa'))
            ->assertSessionHasErrors('session');
        $this->assertSame(1, DB::table('sessions')->where('id', $currentSessionId)->count());
    }

    public function test_log_out_other_sessions_removes_every_other_session_but_leaves_the_current_one(): void
    {
        $user = $this->makeUser('mfa-view-revoke-others@test.test');
        $currentSessionId = $this->currentSessionIdFor($user);

        DB::table('sessions')->insert([
            ['id' => $currentSessionId, 'user_id' => $user->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'This device', 'payload' => base64_encode('x'), 'last_activity' => time()],
            ['id' => 'sess-others-a', 'user_id' => $user->id, 'ip_address' => '10.0.0.2', 'user_agent' => 'Old laptop', 'payload' => base64_encode('x'), 'last_activity' => time()],
            ['id' => 'sess-others-b', 'user_id' => $user->id, 'ip_address' => '10.0.0.3', 'user_agent' => 'Tablet', 'payload' => base64_encode('x'), 'last_activity' => time()],
        ]);

        $this->withCookie(config('session.cookie'), $currentSessionId)
            ->actingAs($user)->post('/security/sessions/revoke-others')
            ->assertRedirect(route('security.mfa'))
            ->assertSessionHas('status');

        $this->assertSame(1, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('id', $currentSessionId)->count(), 'The current session must survive "log out other sessions".');
    }
}
