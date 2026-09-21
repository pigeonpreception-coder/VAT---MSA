<?php

namespace Tests\Feature\Signup;

use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\LicensePlanSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the self-serve commercial SaaS signup channel
 * (App\Services\Signup\SignupService, ported from lib/data/
 * signup-repository.ts's submitSelfServeSignup) -- the one genuinely
 * unauthenticated command in this codebase, over both the stateless JSON
 * API (routes/api.php) and the public Blade form built alongside it. Real
 * HTTP, real MySQL, no mocks.
 */
class SelfServeSignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(LicensePlanSeeder::class);
    }

    private function payload(array $overrides = []): array
    {
        $suffix = mb_strtoupper(Str::random(8));

        return array_replace([
            'schema_version' => '1.0.0', 'applicant_name' => 'Jane Applicant', 'applicant_role' => 'OWNER',
            'contact_email' => 'applicant-'.Str::lower($suffix).'@signuptest.test', 'country_code' => 'NA',
            'plan_code' => 'PILOT_PROFESSIONAL', 'vat_number' => "VAT-SU-{$suffix}", 'tin' => "TIN-SU-{$suffix}",
            'legal_name' => 'Signup Test Trading Co', 'taxpayer_type' => 'PRIVATE_COMPANY', 'return_frequency' => 'MONTHLY',
            'address' => '1 Signup Street, Windhoek', 'company_system_administrator_attested' => true,
            'terms_accepted' => true, 'privacy_notice_accepted' => true,
        ], $overrides);
    }

    private function submit(array $overrides = [], ?string $idempotencyKey = null): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/signup/v1/applications', $this->payload($overrides), [
            'Idempotency-Key' => $idempotencyKey ?? 'su-'.Str::random(20),
        ]);
    }

    public function test_a_valid_submission_is_accepted_pending_verification(): void
    {
        $response = $this->submit();

        $response->assertStatus(202)
            ->assertJsonPath('status', 'PENDING_VERIFICATION')
            ->assertJsonPath('identity_status', 'VERIFICATION_REQUIRED')
            ->assertJsonPath('taxpayer_verification_status', 'AWAITING_PROVIDER_CONTRACT')
            ->assertJsonPath('licence_status', 'NOT_ACTIVATED');
        $this->assertNotEmpty($response->json('application_reference'));
        $this->assertDatabaseHas('self_serve_signup_applications', ['status' => 'PENDING_VERIFICATION']);
    }

    public function test_submitting_without_company_administrator_authority_is_forbidden(): void
    {
        $response = $this->submit(['company_system_administrator_attested' => false]);

        $response->assertStatus(403)->assertJsonPath('code', 'COMPANY_ADMIN_AUTHORITY_REQUIRED');
        $this->assertDatabaseCount('self_serve_signup_applications', 0);
    }

    public function test_submitting_without_accepting_terms_is_a_validation_error(): void
    {
        $response = $this->submit(['terms_accepted' => false]);

        $response->assertStatus(422)->assertJsonPath('code', 'TERMS_ACCEPTANCE_REQUIRED');
    }

    public function test_an_unavailable_plan_code_is_rejected(): void
    {
        $response = $this->submit(['plan_code' => 'NOT_A_REAL_PLAN']);

        $response->assertStatus(422)->assertJsonPath('code', 'PLAN_UNAVAILABLE');
    }

    public function test_a_malformed_plan_code_is_a_validation_error_before_any_plan_lookup(): void
    {
        $response = $this->submit(['plan_code' => 'x']);

        $response->assertStatus(422)->assertJsonPath('code', 'PLAN_CODE_INVALID');
    }

    public function test_identical_vat_number_and_tin_is_rejected(): void
    {
        $response = $this->submit(['vat_number' => 'VAT-SAME-0001', 'tin' => 'VAT-SAME-0001']);

        $response->assertStatus(422)->assertJsonPath('code', 'IDENTIFIERS_NOT_DISTINCT');
    }

    public function test_a_replayed_idempotency_key_returns_the_same_application_not_a_second_one(): void
    {
        $payload = $this->payload();
        $key = 'su-replay-'.Str::random(20);

        $first = $this->submit($payload, $key);
        $second = $this->submit($payload, $key);

        $first->assertStatus(202);
        $second->assertStatus(202)->assertJsonPath('application_reference', $first->json('application_reference'));
        $this->assertDatabaseCount('self_serve_signup_applications', 1);
    }

    public function test_a_short_idempotency_key_is_rejected(): void
    {
        $response = $this->submit([], 'too-short');

        $response->assertStatus(422)->assertJsonPath('code', 'IDEMPOTENCY_KEY_INVALID');
    }

    public function test_a_second_application_for_a_vat_number_with_an_active_canonical_taxpayer_is_a_conflict(): void
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => 'VAT-SU-EXISTING', 'tin' => 'TIN-SU-EXISTING',
            'legal_name' => 'Existing Co', 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Existing Street', 'email' => 'existing@signuptest.test',
        ]);
        Organisation::create(['id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE']);

        $response = $this->submit(['vat_number' => $taxpayer->vat_number, 'tin' => 'TIN-SU-DIFFERENT']);

        $response->assertStatus(409);
    }

    public function test_a_second_pending_application_for_the_same_vat_number_is_a_conflict(): void
    {
        $shared = $this->payload();
        $this->submit($shared)->assertStatus(202);

        $response = $this->submit(array_merge($shared, ['contact_email' => 'other-'.Str::lower(Str::random(8)).'@signuptest.test']));

        $response->assertStatus(409);
    }

    public function test_repeated_submissions_from_the_same_email_are_rate_limited(): void
    {
        $email = 'rate-limited-'.Str::lower(Str::random(8)).'@signuptest.test';
        for ($i = 0; $i < 5; $i++) {
            $this->submit(['contact_email' => $email, 'vat_number' => "VAT-RL-{$i}-".Str::random(4), 'tin' => "TIN-RL-{$i}-".Str::random(4)])
                ->assertStatus(202);
        }

        $response = $this->submit(['contact_email' => $email, 'vat_number' => 'VAT-RL-6-'.Str::random(4), 'tin' => 'TIN-RL-6-'.Str::random(4)]);

        $response->assertStatus(429);
    }

    public function test_an_unexpected_field_is_rejected(): void
    {
        $response = $this->submit(['not_a_real_field' => 'value']);

        $response->assertStatus(422)->assertJsonPath('code', 'FIELD_UNEXPECTED');
    }

    public function test_the_blade_form_is_reachable_without_authentication(): void
    {
        $response = $this->get('/signup');

        $response->assertOk()->assertViewIs('signup.create');
        $response->assertSee('Apply for a commercial VAT-MSA subscription');
    }

    public function test_an_authenticated_user_is_redirected_away_from_the_signup_form(): void
    {
        $user = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Existing User', 'email' => 'existing-user-'.Str::random(8).'@signuptest.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($user)->get('/signup');

        $response->assertRedirect(route('dashboard'));
    }

    public function test_submitting_through_the_blade_form_creates_a_pending_application(): void
    {
        $suffix = mb_strtoupper(Str::random(8));

        $response = $this->post('/signup', [
            'applicant_name' => 'Blade Applicant', 'applicant_role' => 'OWNER', 'contact_email' => 'blade-'.Str::lower($suffix).'@signuptest.test',
            'plan_code' => 'PILOT_PROFESSIONAL', 'vat_number' => "VAT-BL-{$suffix}", 'tin' => "TIN-BL-{$suffix}",
            'legal_name' => 'Blade Signup Co', 'taxpayer_type' => 'PRIVATE_COMPANY', 'return_frequency' => 'MONTHLY',
            'address' => '1 Blade Street, Windhoek', 'company_system_administrator_attested' => '1',
            'terms_accepted' => '1', 'privacy_notice_accepted' => '1',
        ]);

        $response->assertRedirect(route('signup.create'));
        $this->assertDatabaseHas('self_serve_signup_applications', ['legal_name' => 'Blade Signup Co', 'status' => 'PENDING_VERIFICATION']);
    }

    public function test_submitting_through_the_blade_form_without_company_administrator_authority_shows_an_error(): void
    {
        $suffix = mb_strtoupper(Str::random(8));

        $response = $this->post('/signup', [
            'applicant_name' => 'Blade Applicant', 'applicant_role' => 'OWNER', 'contact_email' => 'blade-'.Str::lower($suffix).'@signuptest.test',
            'plan_code' => 'PILOT_PROFESSIONAL', 'vat_number' => "VAT-BL2-{$suffix}", 'tin' => "TIN-BL2-{$suffix}",
            'legal_name' => 'Blade Signup Co', 'taxpayer_type' => 'PRIVATE_COMPANY', 'return_frequency' => 'MONTHLY',
            'address' => '1 Blade Street, Windhoek', 'terms_accepted' => '1', 'privacy_notice_accepted' => '1',
        ]);

        $response->assertSessionHasErrors('signup');
        $this->assertDatabaseCount('self_serve_signup_applications', 0);
    }
}
