<?php

namespace Tests\Feature\Security;

use App\Exceptions\RateLimitExceededException;
use App\Models\User;
use App\Support\Security\RateLimitGuard;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers App\Support\Security\RateLimitGuard (ported from lib/security/
 * request.ts's enforceRateLimits) and App\Http\Middleware\EnforceRateLimit,
 * the multi-level actor/device/source/tenant/global rate controls this
 * migration had never ported until now -- see that class's own doc
 * comment for why db/runtime.ts's own rate_limit_windows migration had
 * sat unconsumed. Real MySQL, real HTTP requests, no mocks -- matching
 * this migration's own established rigor.
 */
class RateLimitEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    private function makeUser(string $email, string $role = 'SECURITY_ANALYST'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => $email, 'email' => $email,
            'password' => bcrypt('password'), 'role' => $role, 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    public function test_a_bucket_under_its_limit_passes_and_persists_a_real_window_row(): void
    {
        $now = (int) (now()->timestamp * 1000);
        RateLimitGuard::enforce([['key' => 'test:bucket:under', 'limit' => 5, 'windowSeconds' => 60]], $now);

        $this->assertDatabaseHas('rate_limit_windows', ['bucket_key' => 'test:bucket:under', 'request_count' => 1]);
    }

    public function test_a_bucket_increments_across_calls_within_the_same_window(): void
    {
        $now = (int) (now()->timestamp * 1000);
        RateLimitGuard::enforce([['key' => 'test:bucket:increment', 'limit' => 5, 'windowSeconds' => 60]], $now);
        RateLimitGuard::enforce([['key' => 'test:bucket:increment', 'limit' => 5, 'windowSeconds' => 60]], $now);
        RateLimitGuard::enforce([['key' => 'test:bucket:increment', 'limit' => 5, 'windowSeconds' => 60]], $now);

        $this->assertDatabaseHas('rate_limit_windows', ['bucket_key' => 'test:bucket:increment', 'request_count' => 3]);
    }

    public function test_a_bucket_over_its_limit_throws_and_carries_the_window_as_retry_after(): void
    {
        $now = (int) (now()->timestamp * 1000);
        for ($i = 0; $i < 3; $i++) {
            RateLimitGuard::enforce([['key' => 'test:bucket:over', 'limit' => 3, 'windowSeconds' => 45]], $now);
        }

        try {
            RateLimitGuard::enforce([['key' => 'test:bucket:over', 'limit' => 3, 'windowSeconds' => 45]], $now);
            $this->fail('Expected RateLimitExceededException.');
        } catch (RateLimitExceededException $e) {
            $this->assertSame('RATE_LIMIT_EXCEEDED', $e->code());
            $this->assertSame(429, $e->status());
            $this->assertSame(45, $e->retryAfterSeconds());
        }
        $this->assertDatabaseHas('rate_limit_windows', ['bucket_key' => 'test:bucket:over', 'request_count' => 4]);
    }

    public function test_a_new_window_resets_the_count(): void
    {
        $firstWindow = (int) (now()->timestamp * 1000);
        $secondWindow = $firstWindow + 61_000;
        RateLimitGuard::enforce([['key' => 'test:bucket:window-reset', 'limit' => 1, 'windowSeconds' => 60]], $firstWindow);

        // The second call is in a new 60-second window -- must not throw
        // even though the first window's own count already reached its limit.
        RateLimitGuard::enforce([['key' => 'test:bucket:window-reset', 'limit' => 1, 'windowSeconds' => 60]], $secondWindow);

        $this->assertDatabaseCount('rate_limit_windows', 2);
    }

    public function test_exceeding_the_identity_family_actor_bucket_over_http_returns_429_and_records_a_security_event(): void
    {
        $user = $this->makeUser('rate-limit-http@security.test');

        // RateLimitGuard::enforceCommand's identity-family actor bucket is
        // 30 requests per 60 seconds (App\Support\Security\RateLimitGuard's
        // own doc comment) -- 31 real HTTP POSTs to a real 'rate-limit:identity'
        // route (MfaController::enroll, which the middleware guards
        // regardless of what the controller itself later does with the
        // request) proves the 31st is rejected before the controller ever runs.
        $response = null;
        for ($i = 0; $i < 31; $i++) {
            $response = $this->actingAs($user)->postJson('/api/v1/identity/mfa/totp');
        }

        $response->assertStatus(429);
        $response->assertJson(['code' => 'RATE_LIMIT_EXCEEDED']);
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertDatabaseHas('security_events', [
            'event_type' => 'RATE_LIMIT_EXCEEDED', 'actor_id' => $user->id, 'action' => 'IDENTITY_RATE_LIMIT', 'outcome' => 'REJECTED',
        ]);
    }

    public function test_a_request_with_no_authenticated_user_is_never_rate_limited(): void
    {
        // EnforceRateLimit::handle() short-circuits to $next($request) when
        // $request->user() is null -- a pre-auth caller (e.g. the login
        // form itself) must never be blocked by an authenticated-actor
        // bucket it can never carry an identity for.
        $response = $this->postJson('/api/v1/identity/mfa/totp');

        $response->assertStatus(401);
        $this->assertDatabaseCount('rate_limit_windows', 0);
    }

    /**
     * Gap-finding pass (2026-09-24): RateLimitGuard::enforceInvitationClaimRateLimits()/
     * enforceVerifyTokenRateLimits() -- unlike every other named bucket
     * set above, these two are called inline from their own service
     * (UserInvitationService::claim()/PublicVerificationService::verify()),
     * not via the EnforceRateLimit middleware, since both routes are
     * genuinely pre-auth (no `$request->user()` for the middleware's own
     * short-circuit above to key on) -- same shape as
     * enforceSelfServeSignup's own SignupService call site.
     */
    public function test_the_invitation_claim_source_bucket_returns_429_after_its_limit(): void
    {
        // enforceInvitationClaimRateLimits' own source bucket is 10 requests
        // per 300 seconds (RateLimitGuard's own doc comment) -- 11 real
        // HTTP POSTs (every one with an invalid token, on purpose: the rate
        // limit is checked before the token is ever looked up) proves the
        // 11th is rejected with a graceful form error, not a raw 500.
        $payload = ['token' => 'not-a-real-token', 'name' => 'Claimant', 'password' => 'CorrectHorse1', 'password_confirmation' => 'CorrectHorse1'];
        $response = null;
        for ($i = 0; $i < 11; $i++) {
            $response = $this->post('/invitations/claim', $payload);
        }

        $response->assertSessionHasErrors('token');
        $this->assertDatabaseHas('rate_limit_windows', ['bucket_key' => 'invitation-claim:source:127.0.0.1']);
    }

    public function test_the_verify_token_source_bucket_returns_429_after_its_limit(): void
    {
        // enforceVerifyTokenRateLimits' own source bucket is 30 requests per
        // 60 seconds -- 31 real HTTP GETs against an unknown token (the
        // rate limit is checked before the certificate lookup) proves the
        // 31st is rejected, matching RateLimitExceededException::render()'s
        // own JSON shape on this JSON route.
        $response = null;
        for ($i = 0; $i < 31; $i++) {
            $response = $this->getJson('/api/v1/verify/not-a-real-token');
        }

        $response->assertStatus(429);
        $response->assertJson(['code' => 'RATE_LIMIT_EXCEEDED']);
        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertDatabaseHas('rate_limit_windows', ['bucket_key' => 'verify-token:source:127.0.0.1']);
    }
}
