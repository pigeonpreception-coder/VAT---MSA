<?php

namespace Tests\Feature\Identity;

use App\Models\IdentityProofingCase;
use App\Models\LicensePlan;
use App\Models\RegistrationApplication;
use App\Models\SelfServeSignupApplication;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for taxpayer registration intake
 * (App\Http\Controllers\Identity\RegistrationsViewController /
 * resources/views/registrations/index.blade.php) -- ported from the
 * source's own app/registrations/page.tsx. Gap-finding pass (2026-09-23):
 * this page had no Laravel route at all; closing it also required porting
 * the `identity_proofing_cases`/`identity_mismatch_cases` tables (never
 * ported anywhere in this migration) and SignupService::listSelfServeSignupApplications,
 * neither of which any other Laravel view or JSON route depended on yet.
 */
class RegistrationsViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function nationalAdmin(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Admin', 'email' => 'admin@regview.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_SYSTEM_SUPPORT', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function taxpayerOwner(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Owner', 'email' => 'owner@regview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    public function test_the_registrations_page_requires_authentication(): void
    {
        $this->get('/registrations')->assertRedirect('/login');
    }

    public function test_a_role_without_registrations_read_is_denied(): void
    {
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Seller Viewer', 'email' => 'viewer@regview.test',
            'password' => bcrypt('password'), 'role' => 'SELLER_VIEWER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($viewer)->get('/registrations')->assertForbidden();
    }

    public function test_it_renders_registration_applications_with_proofing_and_mismatch_columns(): void
    {
        $admin = $this->nationalAdmin();
        $application = RegistrationApplication::create([
            'id' => (string) Str::uuid(), 'idempotency_key' => 'regview-key-0001', 'request_hash' => str_repeat('a', 64),
            'vat_number' => 'VAT-REGVIEW-0001', 'tin' => 'TIN-REGVIEW-0001', 'legal_name' => 'Regview Trading Co',
            'taxpayer_type' => 'PRIVATE_COMPANY', 'return_frequency' => 'MONTHLY', 'address' => '1 Regview Street',
            'email' => 'finance@regview-trading.test', 'status' => 'UNDER_REVIEW', 'verification_source' => 'ITAS',
            'submitted_by' => $admin->id, 'submitted_at' => now(),
        ]);
        IdentityProofingCase::create([
            'id' => (string) Str::uuid(), 'subject_type' => 'TAXPAYER_REGISTRATION', 'subject_reference' => $application->vat_number,
            'registration_application_id' => $application->id, 'provider' => 'ITAS', 'provider_environment' => 'PRODUCTION_EQUIVALENT',
            'status' => 'CANDIDATE_FOUND', 'confidence_bps' => 8200, 'reason_code' => 'AUTOMATED_MATCH',
            'requested_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/registrations');

        $response->assertOk()->assertViewIs('registrations.index');
        $response->assertSee('Regview Trading Co');
        $response->assertSee('82.00%');
        $response->assertSee('AUTOMATED MATCH');
    }

    public function test_the_self_serve_queue_is_hidden_from_a_non_national_scope_actor(): void
    {
        $owner = $this->taxpayerOwner();
        $plan = LicensePlan::create([
            'id' => (string) Str::uuid(), 'code' => 'STARTER', 'name' => 'Starter', 'version' => 1,
            'plan_domain' => 'COMMERCIAL_SAAS', 'status' => 'ACTIVE', 'effective_from' => now()->subDay(),
        ]);
        SelfServeSignupApplication::create([
            'id' => (string) Str::uuid(), 'public_reference' => 'VMS-2026-REGVIEWSELF01', 'idempotency_key' => 'selfserve-key-0001',
            'request_hash' => str_repeat('b', 64), 'applicant_name' => 'Self Serve Applicant', 'applicant_role' => 'COMPANY_ADMIN',
            'contact_email' => 'applicant@selfserve.test', 'onboarding_path' => 'COMPANY_ADMIN', 'country_code' => 'NA',
            'requested_plan_id' => $plan->id, 'vat_number' => 'VAT-SELFSERVE-0001', 'tin' => 'TIN-SELFSERVE-0001',
            'legal_name' => 'Self Serve Trading', 'taxpayer_type' => 'PRIVATE_COMPANY', 'return_frequency' => 'MONTHLY',
            'address' => '1 Self Serve Street', 'terms_version' => 'v1', 'privacy_notice_version' => 'v1',
            'status' => 'PENDING_VERIFICATION', 'identity_status' => 'VERIFICATION_REQUIRED',
            'taxpayer_verification_status' => 'AWAITING_PROVIDER_CONTRACT', 'licence_status' => 'NOT_ACTIVATED',
            'submitted_at' => now(),
        ]);

        $response = $this->actingAs($owner)->get('/registrations');
        $response->assertOk();
        $response->assertDontSee('Self-serve signup queue');
        $response->assertDontSee('Self Serve Trading');

        $admin = $this->nationalAdmin();
        $nationalResponse = $this->actingAs($admin)->get('/registrations');
        $nationalResponse->assertOk();
        $nationalResponse->assertSee('Self-serve signup queue');
        $nationalResponse->assertSee('Self Serve Trading');
    }

    public function test_the_self_serve_queue_shows_the_identity_conflict_flag(): void
    {
        $admin = $this->nationalAdmin();
        $plan = LicensePlan::create([
            'id' => (string) Str::uuid(), 'code' => 'STARTER', 'name' => 'Starter', 'version' => 1,
            'plan_domain' => 'COMMERCIAL_SAAS', 'status' => 'ACTIVE', 'effective_from' => now()->subDay(),
        ]);
        $shared = [
            'onboarding_path' => 'COMPANY_ADMIN', 'country_code' => 'NA', 'requested_plan_id' => $plan->id,
            'applicant_role' => 'COMPANY_ADMIN', 'taxpayer_type' => 'PRIVATE_COMPANY', 'return_frequency' => 'MONTHLY',
            'address' => '1 Conflict Street', 'terms_version' => 'v1', 'privacy_notice_version' => 'v1',
            'status' => 'PENDING_VERIFICATION', 'identity_status' => 'VERIFICATION_REQUIRED',
            'taxpayer_verification_status' => 'AWAITING_PROVIDER_CONTRACT', 'licence_status' => 'NOT_ACTIVATED', 'submitted_at' => now(),
        ];
        SelfServeSignupApplication::create([...$shared,
            'id' => (string) Str::uuid(), 'public_reference' => 'VMS-2026-CONFLICTED01', 'idempotency_key' => 'selfserve-conflict-key-0001',
            'request_hash' => str_repeat('c', 64), 'applicant_name' => 'Conflicted Applicant', 'contact_email' => 'conflicted@selfserve.test',
            'vat_number' => 'VAT-CONFLICT-0001', 'tin' => 'TIN-CONFLICT-0001', 'legal_name' => 'Conflicted Trading',
            'identity_conflict_detected' => true,
        ]);
        SelfServeSignupApplication::create([...$shared,
            'id' => (string) Str::uuid(), 'public_reference' => 'VMS-2026-CLEARAPP01', 'idempotency_key' => 'selfserve-clear-key-0001',
            'request_hash' => str_repeat('d', 64), 'applicant_name' => 'Clear Applicant', 'contact_email' => 'clear@selfserve.test',
            'vat_number' => 'VAT-CLEAR-0001', 'tin' => 'TIN-CLEAR-0001', 'legal_name' => 'Clear Trading',
            'identity_conflict_detected' => false,
        ]);

        $response = $this->actingAs($admin)->get('/registrations');

        $response->assertOk();
        $response->assertSeeInOrder(['Conflicted Trading', 'Conflict detected']);
        $response->assertSeeInOrder(['Clear Trading', 'Clear']);
    }

    public function test_a_taxpayer_owner_can_submit_a_registration_application_through_the_form(): void
    {
        $owner = $this->taxpayerOwner();

        $response = $this->actingAs($owner)->post('/registrations', [
            'idempotency_key' => 'regview-form-key-0001',
            'vat_number' => 'VAT-REGFORM-0001', 'tin' => 'TIN-REGFORM-0001', 'legal_name' => 'Regform Trading Co',
            'taxpayer_type' => 'PRIVATE_COMPANY', 'return_frequency' => 'MONTHLY',
            'address' => '1 Regform Street, Windhoek', 'email' => 'finance@regform-trading.test',
        ]);

        $response->assertRedirect(route('registrations.index'));
        $this->assertDatabaseHas('registration_applications', ['vat_number' => 'VAT-REGFORM-0001', 'status' => 'PENDING_VERIFICATION']);
    }

    public function test_a_viewer_without_registrations_submit_is_denied_the_form(): void
    {
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Viewer', 'email' => 'viewer2@regview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_VIEWER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($viewer)->post('/registrations', [
            'idempotency_key' => 'regview-form-key-0002',
            'vat_number' => 'VAT-REGFORM-0002', 'tin' => 'TIN-REGFORM-0002', 'legal_name' => 'Denied Co',
            'taxpayer_type' => 'PRIVATE_COMPANY', 'return_frequency' => 'MONTHLY',
            'address' => '1 Denied Street', 'email' => 'finance@denied.test',
        ]);

        $response->assertForbidden();
    }
}
