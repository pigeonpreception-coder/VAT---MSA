<?php

namespace Tests\Feature\Identity;

use App\Support\Access\Totp;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers App\Services\Identity\MfaService and App\Http\Controllers\
 * Identity\MfaController -- ported from lib/data/mfa-repository.ts and
 * app/api/v1/identity/{mfa/totp,mfa/totp/verification,step-up,assurance}/
 * route.ts, matching tests/routes/security-mfa-step-up.test.ts's own
 * contract exactly (enrol, verify, confirm step-up, anti-replay).
 *
 * TOTP step-up parity (2026-09-15, infrastructure only, per the user's
 * own explicit scope decision): this proves the real, server-verified
 * MFA credential and step-up mechanism work end to end. It does NOT
 * prove any existing password.confirm-gated command now requires TOTP --
 * that cutover is a deliberately separate, not-yet-done follow-up (see
 * docs/LAUNCH_READINESS_BACKLOG.md item #8).
 */
class MfaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function makeUser(string $email): \App\Models\User
    {
        return \App\Models\User::create([
            'id' => (string) Str::uuid(), 'name' => 'MFA Test User', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'NAMRA_VAT_AUDITOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    public function test_assurance_reports_no_enrolment_and_no_step_up_before_anything_has_happened(): void
    {
        $user = $this->makeUser('mfa-fresh@test.test');

        $response = $this->actingAs($user)->getJson('/api/v1/identity/assurance');

        $response->assertOk()->assertJson(['mfaEnrolled' => false, 'hasRecentStepUp' => false]);
    }

    public function test_denies_confirming_step_up_before_mfa_is_enrolled_at_all(): void
    {
        $user = $this->makeUser('mfa-no-enrol@test.test');

        $response = $this->actingAs($user)->postJson('/api/v1/identity/step-up', ['code' => '000000']);

        $response->assertStatus(422)->assertJsonPath('errors.0.code', 'MFA_NOT_ACTIVE');
    }

    public function test_full_enrolment_and_step_up_lifecycle_with_anti_replay(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-15T09:00:00Z'));
        $user = $this->makeUser('mfa-lifecycle@test.test');

        // 1. Enrol -- returns a fresh base32 secret and otpauth URI, never returned again.
        $enrollResponse = $this->actingAs($user)->postJson('/api/v1/identity/mfa/totp');
        $enrollResponse->assertStatus(201);
        $secret = $enrollResponse->json('enrollment.secret');
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        $this->assertStringContainsString(rawurlencode($secret), $enrollResponse->json('enrollment.otpauthUri'));

        // 2. Wrong code leaves the credential unverified.
        $wrongVerify = $this->actingAs($user)->postJson('/api/v1/identity/mfa/totp/verification', ['code' => '000000']);
        $wrongVerify->assertStatus(422)->assertJsonPath('errors.0.code', 'CODE_INCORRECT');
        $this->actingAs($user)->getJson('/api/v1/identity/assurance')->assertJson(['mfaEnrolled' => false]);

        // 3. Correct code activates the credential.
        $code = Totp::generateCode($secret);
        $verifyResponse = $this->actingAs($user)->postJson('/api/v1/identity/mfa/totp/verification', ['code' => $code]);
        $verifyResponse->assertOk()->assertJson(['credential' => ['status' => 'ACTIVE']]);
        $this->actingAs($user)->getJson('/api/v1/identity/assurance')->assertJson(['mfaEnrolled' => true]);

        // 4. Re-enrolling while ACTIVE is refused.
        $this->actingAs($user)->postJson('/api/v1/identity/mfa/totp')->assertStatus(409);

        // 5. Wrong step-up code is refused.
        $this->actingAs($user)->postJson('/api/v1/identity/step-up', ['code' => '111111'])
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'CODE_INCORRECT');

        // 6. A genuinely fresh code (advance past the enrolment code's own 30-second step,
        // so this isn't the same code the anti-replay check would otherwise reject for the
        // wrong reason) confirms step-up.
        Carbon::setTestNow(Carbon::now()->addSeconds(90));
        $usedCode = Totp::generateCode($secret);
        $stepUpResponse = $this->actingAs($user)->postJson('/api/v1/identity/step-up', ['code' => $usedCode]);
        $stepUpResponse->assertStatus(201)->assertJsonPath('step_up.method', 'TOTP');
        $this->assertTrue(Carbon::parse($stepUpResponse->json('step_up.expiresAt'))->isFuture());
        $this->actingAs($user)->getJson('/api/v1/identity/assurance')->assertJson(['hasRecentStepUp' => true]);

        // 7. The exact same code can never confirm step-up twice (anti-replay).
        $this->actingAs($user)->postJson('/api/v1/identity/step-up', ['code' => $usedCode])
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'CODE_INCORRECT');

        Carbon::setTestNow();
    }

    public function test_a_malformed_code_is_rejected_before_any_totp_verification(): void
    {
        $user = $this->makeUser('mfa-malformed@test.test');
        $this->actingAs($user)->postJson('/api/v1/identity/mfa/totp');

        $response = $this->actingAs($user)->postJson('/api/v1/identity/mfa/totp/verification', ['code' => 'abcdef']);

        $response->assertStatus(422)->assertJsonPath('errors.0.code', 'CODE_INVALID');
    }

    public function test_mfa_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/identity/assurance')->assertStatus(401);
    }
}
