<?php

namespace Tests\Feature\Identity;

use App\Models\MfaTotpCredential;
use App\Models\User;
use App\Support\Access\Totp;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_a_role_without_identity_read_does_not_see_the_security_link_or_reach_the_page(): void
    {
        $superAdmin = $this->makeUser('mfa-view-super-admin@test.test', 'SUPER_ADMIN');

        $dashboard = $this->actingAs($superAdmin)->get('/dashboard');
        $dashboard->assertOk()->assertDontSee(route('security.mfa'), false);

        $this->actingAs($superAdmin)->get('/security/mfa')->assertForbidden();
    }

    public function test_a_role_with_identity_read_sees_the_security_link(): void
    {
        $user = $this->makeUser('mfa-view-owner@test.test', 'TAXPAYER_OWNER');

        $dashboard = $this->actingAs($user)->get('/dashboard');
        $dashboard->assertOk()->assertSee(route('security.mfa'), false);
    }
}
