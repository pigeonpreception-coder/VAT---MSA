<?php

namespace Tests\Feature\Business;

use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers Issue 3's counterparty trust boundary in full
 * (05-security/issue3-counterparty-trust-boundary.md): App\Domain\Business\
 * CounterpartyTrustEvaluator, App\Services\Business\CounterpartyTrustService,
 * App\Support\Business\CounterpartyTrustGate, and the gate's wiring into
 * App\Services\Business\QuotationService/ExpenseService/ProjectService --
 * plus the company_registration_number field the same gap closed. A newly
 * created party starts PENDING_PROVIDER (not transaction-eligible); the
 * only way this port (or the source) can move it out of that state today
 * is the labelled SYNTHETIC_VALID path exercised throughout below --
 * AUTHORITY_VERIFIED itself requires the NamRA/ITAS/BIPA provider
 * integration, `BLOCKED -- EXTERNAL DEPENDENCY REQUIRED` per that same
 * design doc.
 */
class CounterpartyTrustTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, owner: User, accountant: User} */
    private function makeOrganisation(string $vatNumber): array
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => strtolower($vatNumber).'@test.test',
        ]);
        $organisation = Organisation::create([
            'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE',
        ]);
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);
        $accountant = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Accountant", 'email' => strtolower($vatNumber).'-accountant@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_ACCOUNTANT', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner', 'accountant');
    }

    /** @return array{id: string, resource: array<string, mixed>} */
    private function createParty(User $owner, array $overrides = []): array
    {
        $payload = array_replace([
            'schema_version' => '1.0.0', 'display_name' => 'Counterparty Co', 'relationships' => ['CUSTOMER', 'SUPPLIER'],
        ], $overrides);
        $response = $this->actingAs($owner)->postJson('/api/v1/business-parties', $payload, ['Idempotency-Key' => 'test-idem-cpt-'.Str::random(10)]);
        $response->assertStatus(201);

        return ['id' => $response->json('resource.id'), 'resource' => $response->json('resource')];
    }

    private function syntheticVerify(User $owner, string $partyId, array $authorityRecord, int $expectStatus = 200): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($owner)->postJson("/api/v1/business-parties/{$partyId}/synthetic-verification", [
            'schema_version' => '1.0.0', 'authority_record' => $authorityRecord,
        ], ['Idempotency-Key' => 'test-idem-synthverify-'.Str::random(10)])->assertStatus($expectStatus);
    }

    // -- creation lifecycle --

    public function test_a_newly_created_party_starts_pending_provider_and_is_not_transaction_eligible(): void
    {
        $org = $this->makeOrganisation('VAT-CPT-0001');
        $party = $this->createParty($org['owner'], ['vat_number' => 'VAT-CPT-0001-CUST']);

        $this->assertSame('PENDING_PROVIDER', $party['resource']['trust_status']);
        $this->assertSame('UNKNOWN', $party['resource']['tax_registration_status']);
        $this->assertDatabaseHas('counterparty_trust_profiles', [
            'business_party_id' => $party['id'], 'trust_status' => 'PENDING_PROVIDER', 'provider' => 'ITAS_BIPA',
            'provider_environment' => 'CONTRACT_PENDING', 'vat_verification_status' => 'PENDING',
        ]);
        $this->assertDatabaseHas('counterparty_trust_events', [
            'event_type' => 'CounterpartyVerificationRequested', 'to_status' => 'PENDING_PROVIDER',
            'reason_code' => 'AUTHORITY_PROVIDER_CONTRACT_REQUIRED',
        ]);
    }

    public function test_changing_an_identifier_field_returns_an_already_verified_party_to_pending_provider(): void
    {
        $org = $this->makeOrganisation('VAT-CPT-0002');
        $party = $this->createParty($org['owner'], ['display_name' => 'Acme Co', 'vat_number' => 'VAT-CPT-0002-CUST']);
        $this->syntheticVerify($org['owner'], $party['id'], [
            'legal_name' => 'Acme Co', 'vat_number' => 'VAT-CPT-0002-CUST', 'tax_registration_status' => 'ACTIVE',
        ])->assertJsonPath('resource.trust_status', 'SYNTHETIC_VALID');

        $update = $this->actingAs($org['owner'])->patchJson("/api/v1/business-parties/{$party['id']}", [
            'schema_version' => '1.0.0', 'display_name' => 'Acme Co', 'vat_number' => 'VAT-CPT-0002-CUST-CHANGED',
            'relationships' => ['CUSTOMER', 'SUPPLIER'],
        ], ['Idempotency-Key' => 'test-idem-cpt-update-'.Str::random(6)]);

        $update->assertStatus(200)->assertJsonPath('resource.trust_status', 'PENDING_PROVIDER');
        $this->assertDatabaseHas('counterparty_trust_events', [
            'event_type' => 'CounterpartyIdentityChanged', 'from_status' => 'SYNTHETIC_VALID', 'to_status' => 'PENDING_PROVIDER',
            'reason_code' => 'IDENTITY_CHANGE_REQUIRES_REVERIFICATION',
        ]);
    }

    // -- App\Domain\Business\CounterpartyTrustEvaluator, exercised through the real endpoint --

    public function test_synthetic_verification_matches_identifiers_and_legal_name_for_a_full_confidence_score(): void
    {
        $org = $this->makeOrganisation('VAT-CPT-0003');
        $party = $this->createParty($org['owner'], [
            'display_name' => 'Full Match Co', 'vat_number' => 'VAT-CPT-0003-CUST', 'tin' => 'TIN-CPT-0003',
            'company_registration_number' => 'CRN-CPT-0003',
        ]);

        $response = $this->syntheticVerify($org['owner'], $party['id'], [
            'legal_name' => 'Full Match Co', 'vat_number' => 'VAT-CPT-0003-CUST', 'tin' => 'TIN-CPT-0003',
            'company_registration_number' => 'CRN-CPT-0003', 'tax_registration_status' => 'ACTIVE',
        ]);

        $response->assertJsonPath('resource.trust_status', 'SYNTHETIC_VALID')
            ->assertJsonPath('resource.tax_registration_status', 'ACTIVE')
            ->assertJsonPath('resource.confidence_bps', 4_000 + 3_500 + 1_500 + 1_000)
            ->assertJsonPath('resource.provider_environment', 'SYNTHETIC_TEST');
        $this->assertDatabaseHas('counterparty_verification_snapshots', ['trust_status' => 'SYNTHETIC_VALID', 'confidence_bps' => 10_000]);
        $this->assertDatabaseHas('counterparty_trust_events', ['event_type' => 'CounterpartyTrustEvaluated', 'to_status' => 'SYNTHETIC_VALID', 'reason_code' => 'SYNTHETIC_COUNTERPARTY_MATCH']);
    }

    public function test_synthetic_verification_reports_mismatch_when_a_provided_identifier_disagrees(): void
    {
        $org = $this->makeOrganisation('VAT-CPT-0004');
        $party = $this->createParty($org['owner'], ['display_name' => 'Mismatch Co', 'vat_number' => 'VAT-CPT-0004-CUST']);

        $response = $this->syntheticVerify($org['owner'], $party['id'], [
            'legal_name' => 'Mismatch Co', 'vat_number' => 'VAT-CPT-0004-DIFFERENT', 'tax_registration_status' => 'ACTIVE',
        ]);

        $response->assertJsonPath('resource.trust_status', 'MISMATCH');
        $this->assertDatabaseHas('counterparty_trust_events', ['event_type' => 'CounterpartyTrustEvaluated', 'to_status' => 'MISMATCH', 'reason_code' => 'COUNTERPARTY_AUTHORITY_MISMATCH']);

        // A mismatched party is no more transaction-eligible than a
        // never-verified one -- the gate rejects it identically.
        $quotation = $this->actingAs($org['owner'])->postJson('/api/v1/quotations', $this->quotationPayload($party['id']), ['Idempotency-Key' => 'test-idem-cpt-quo-'.Str::random(6)]);
        $quotation->assertStatus(422)->assertSee('not currently trusted', false);
    }

    public function test_synthetic_verification_reports_invalid_when_no_identifier_is_provided_on_either_side(): void
    {
        $org = $this->makeOrganisation('VAT-CPT-0005');
        // No vat_number/tin/company_registration_number recorded on the party at all.
        $party = $this->createParty($org['owner'], ['display_name' => 'No Identifiers Co']);

        // The submission itself still needs at least one identifier (a
        // schema requirement of the synthetic authority record, not of the
        // party) -- it just won't match anything the party has on file.
        $response = $this->syntheticVerify($org['owner'], $party['id'], [
            'legal_name' => 'No Identifiers Co', 'vat_number' => 'VAT-UNRELATED-0001', 'tax_registration_status' => 'ACTIVE',
        ]);

        $response->assertJsonPath('resource.trust_status', 'INVALID')
            ->assertJsonPath('resource.vat_verification_status', 'NOT_PROVIDED');
        $this->assertDatabaseHas('counterparty_trust_events', ['event_type' => 'CounterpartyTrustEvaluated', 'to_status' => 'INVALID', 'reason_code' => 'COUNTERPARTY_IDENTIFIER_REQUIRED']);
    }

    public function test_the_synthetic_authority_record_requires_at_least_one_identifier_and_a_supported_tax_status(): void
    {
        $org = $this->makeOrganisation('VAT-CPT-0006');
        $party = $this->createParty($org['owner'], ['display_name' => 'Bad Submission Co']);

        $this->actingAs($org['owner'])->postJson("/api/v1/business-parties/{$party['id']}/synthetic-verification", [
            'schema_version' => '1.0.0', 'authority_record' => ['legal_name' => 'Bad Submission Co', 'tax_registration_status' => 'ACTIVE'],
        ], ['Idempotency-Key' => 'test-idem-cpt-bad-1'])->assertStatus(422);

        $this->actingAs($org['owner'])->postJson("/api/v1/business-parties/{$party['id']}/synthetic-verification", [
            'schema_version' => '1.0.0', 'authority_record' => ['legal_name' => 'Bad Submission Co', 'vat_number' => 'VAT-X', 'tax_registration_status' => 'NOT_A_REAL_STATUS'],
        ], ['Idempotency-Key' => 'test-idem-cpt-bad-2'])->assertStatus(422);
    }

    // -- the transaction gate: quotations, projects, tax-bearing expenses --

    public function test_a_quotation_is_rejected_for_a_pending_provider_customer_and_accepted_once_synthetically_verified(): void
    {
        $org = $this->makeOrganisation('VAT-CPT-0010');
        $customer = $this->createParty($org['owner'], ['display_name' => 'Gate Customer Co', 'vat_number' => 'VAT-CPT-0010-CUST', 'relationships' => ['CUSTOMER']]);

        $rejected = $this->actingAs($org['owner'])->postJson('/api/v1/quotations', $this->quotationPayload($customer['id']), ['Idempotency-Key' => 'test-idem-cpt-quo-pending']);
        $rejected->assertStatus(422)->assertSee('not currently trusted', false);
        $this->assertDatabaseMissing('quotations', ['quotation_number' => 'QUO-CPT-TEST']);

        $this->syntheticVerify($org['owner'], $customer['id'], [
            'legal_name' => 'Gate Customer Co', 'vat_number' => 'VAT-CPT-0010-CUST', 'tax_registration_status' => 'ACTIVE',
        ])->assertJsonPath('resource.trust_status', 'SYNTHETIC_VALID');

        $accepted = $this->actingAs($org['owner'])->postJson('/api/v1/quotations', $this->quotationPayload($customer['id']), ['Idempotency-Key' => 'test-idem-cpt-quo-trusted']);
        $accepted->assertStatus(201);
    }

    public function test_a_project_with_a_customer_is_rejected_for_a_pending_provider_customer_and_accepted_once_synthetically_verified(): void
    {
        $org = $this->makeOrganisation('VAT-CPT-0011');
        $customer = $this->createParty($org['owner'], ['display_name' => 'Gate Project Customer Co', 'vat_number' => 'VAT-CPT-0011-CUST', 'relationships' => ['CUSTOMER']]);

        $rejected = $this->actingAs($org['owner'])->postJson('/api/v1/projects', [
            'schema_version' => '1.0.0', 'code' => 'PROJ-CPT-0001', 'name' => 'Gated project', 'customer_party_id' => $customer['id'],
            'currency' => 'NAD', 'start_date' => '2026-09-01',
        ], ['Idempotency-Key' => 'test-idem-cpt-proj-pending']);
        $rejected->assertStatus(422)->assertSee('not currently trusted', false);

        $this->syntheticVerify($org['owner'], $customer['id'], [
            'legal_name' => 'Gate Project Customer Co', 'vat_number' => 'VAT-CPT-0011-CUST', 'tax_registration_status' => 'ACTIVE',
        ]);

        $accepted = $this->actingAs($org['owner'])->postJson('/api/v1/projects', [
            'schema_version' => '1.0.0', 'code' => 'PROJ-CPT-0001', 'name' => 'Gated project', 'customer_party_id' => $customer['id'],
            'currency' => 'NAD', 'start_date' => '2026-09-01',
        ], ['Idempotency-Key' => 'test-idem-cpt-proj-trusted']);
        $accepted->assertStatus(201);
    }

    public function test_a_tax_bearing_expense_additionally_requires_active_tax_registration_evidence(): void
    {
        $org = $this->makeOrganisation('VAT-CPT-0012');
        $supplier = $this->createParty($org['owner'], ['display_name' => 'Gate Supplier Co', 'vat_number' => 'VAT-CPT-0012-SUP', 'relationships' => ['SUPPLIER']]);
        $category = $this->actingAs($org['owner'])->postJson('/api/v1/expenses/categories', [
            'schema_version' => '1.0.0', 'code' => 'CPTCAT', 'name' => 'Trust gate category', 'default_tax_category' => 'STANDARD', 'requires_receipt' => false,
        ], ['Idempotency-Key' => 'test-idem-cpt-cat'])->json('resource.id');

        // Verified for identity, but the authority record itself reports a
        // SUSPENDED tax registration -- SYNTHETIC_VALID identity trust, but
        // still not eligible for a tax-bearing transaction.
        $this->syntheticVerify($org['owner'], $supplier['id'], [
            'legal_name' => 'Gate Supplier Co', 'vat_number' => 'VAT-CPT-0012-SUP', 'tax_registration_status' => 'SUSPENDED',
        ])->assertJsonPath('resource.trust_status', 'SYNTHETIC_VALID')->assertJsonPath('resource.tax_registration_status', 'SUSPENDED');

        $taxedRejected = $this->actingAs($org['owner'])->postJson('/api/v1/expenses', $this->expensePayload($category, $supplier['id'], ['tax_cents' => 15_000, 'total_cents' => 115_000]), ['Idempotency-Key' => 'test-idem-cpt-exp-suspended']);
        $taxedRejected->assertStatus(422)->assertSee('ACTIVE tax-registration evidence', false);

        // A zero-tax expense against the same still-SUSPENDED supplier is
        // fine -- requireActiveTaxRegistration only applies when tax_cents > 0.
        $zeroTax = $this->actingAs($org['owner'])->postJson('/api/v1/expenses', $this->expensePayload($category, $supplier['id'], [
            'expense_number' => 'EXP-CPT-ZEROTAX', 'net_cents' => 100_000, 'tax_cents' => 0, 'total_cents' => 100_000,
        ]), ['Idempotency-Key' => 'test-idem-cpt-exp-zerotax']);
        $zeroTax->assertStatus(201);

        // Re-verified as ACTIVE: the tax-bearing expense now succeeds.
        $this->syntheticVerify($org['owner'], $supplier['id'], [
            'legal_name' => 'Gate Supplier Co', 'vat_number' => 'VAT-CPT-0012-SUP', 'tax_registration_status' => 'ACTIVE',
        ]);
        $taxedAccepted = $this->actingAs($org['owner'])->postJson('/api/v1/expenses', $this->expensePayload($category, $supplier['id'], ['expense_number' => 'EXP-CPT-TAXED-OK', 'tax_cents' => 15_000, 'total_cents' => 115_000]), ['Idempotency-Key' => 'test-idem-cpt-exp-active']);
        $taxedAccepted->assertStatus(201);
    }

    // -- environment gating (App\Support\Business\CounterpartyTrustGate::syntheticEnabled) --

    /**
     * The `testing` environment this whole suite runs in is itself one of
     * the always-enabled branches (local/testing always accept
     * SYNTHETIC_VALID; production never does; staging only behind an
     * explicit config flag) -- every test above already exercises that
     * enabled path end to end. This test additionally exercises the
     * disabled branches directly by overriding the resolved environment
     * for the duration of one request, since this port has no existing
     * convention (grepped for app()->environment( assertions under tests/)
     * for stubbing environment-dependent behaviour otherwise.
     */
    public function test_synthetic_verification_is_disabled_in_production_and_flag_gated_in_staging(): void
    {
        // Overriding the container's 'env' binding (the only lever
        // App\Support\Business\CounterpartyTrustGate::syntheticEnabled's
        // app()->environment() calls read) also flips
        // Application::runningUnitTests(), which Laravel's own CSRF
        // middleware uses to exempt the test client -- bypass it
        // explicitly so this test still exercises a real POST, not a 419.
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $org = $this->makeOrganisation('VAT-CPT-0013');
        $party = $this->createParty($org['owner'], ['display_name' => 'Env Gated Co', 'vat_number' => 'VAT-CPT-0013-CUST']);
        $authority = ['legal_name' => 'Env Gated Co', 'vat_number' => 'VAT-CPT-0013-CUST', 'tax_registration_status' => 'ACTIVE'];

        app()->instance('env', 'production');
        $this->syntheticVerify($org['owner'], $party['id'], $authority, 403);

        app()->instance('env', 'staging');
        config(['services.vat_msa.enable_synthetic_counterparty_trust' => false]);
        $this->syntheticVerify($org['owner'], $party['id'], $authority, 403);

        config(['services.vat_msa.enable_synthetic_counterparty_trust' => true]);
        $this->syntheticVerify($org['owner'], $party['id'], $authority, 200)
            ->assertJsonPath('resource.trust_status', 'SYNTHETIC_VALID');
    }

    // -- company_registration_number (the gap this same finding closed alongside Issue 3) --

    public function test_company_registration_number_is_validated_deduplicated_and_persisted(): void
    {
        $org = $this->makeOrganisation('VAT-CPT-0020');

        $invalid = $this->actingAs($org['owner'])->postJson('/api/v1/business-parties', [
            'schema_version' => '1.0.0', 'display_name' => 'Bad CRN Co', 'company_registration_number' => 'bad crn!', 'relationships' => ['CUSTOMER'],
        ], ['Idempotency-Key' => 'test-idem-cpt-crn-invalid']);
        $invalid->assertStatus(422)->assertJsonFragment(['code' => 'COMPANY_REGISTRATION_NUMBER_INVALID']);

        $first = $this->createParty($org['owner'], ['display_name' => 'CRN Holder Co', 'company_registration_number' => 'crn-2026-0001']);
        $this->assertSame('CRN-2026-0001', $first['resource']['company_registration_number']);
        $this->assertDatabaseHas('business_parties', ['id' => $first['id'], 'company_registration_number' => 'CRN-2026-0001']);

        $duplicate = $this->actingAs($org['owner'])->postJson('/api/v1/business-parties', [
            'schema_version' => '1.0.0', 'display_name' => 'Another Co', 'company_registration_number' => 'CRN-2026-0001', 'relationships' => ['SUPPLIER'],
        ], ['Idempotency-Key' => 'test-idem-cpt-crn-dupe']);
        $duplicate->assertStatus(409);
    }

    // -- payload helpers --

    private function quotationPayload(string $customerPartyId): array
    {
        return [
            'schema_version' => '1.0.0', 'customer_party_id' => $customerPartyId, 'quotation_number' => 'QUO-CPT-TEST',
            'currency' => 'NAD', 'issue_date' => '2026-09-01', 'valid_until' => '2026-09-30',
            'lines' => [['description' => 'Consulting services', 'quantity_micros' => 1_000_000, 'unit_code' => 'EA', 'unit_price_cents' => 100_000, 'tax_category' => 'STANDARD', 'tax_rate_bps' => 1500]],
        ];
    }

    private function expensePayload(string $categoryId, string $supplierPartyId, array $overrides = []): array
    {
        return array_replace([
            'schema_version' => '1.0.0', 'category_id' => $categoryId, 'supplier_party_id' => $supplierPartyId, 'expense_number' => 'EXP-CPT-TEST',
            'expense_date' => '2026-09-01', 'description' => 'Trust gate expense', 'currency' => 'NAD',
            'net_cents' => 100_000, 'tax_cents' => 15_000, 'total_cents' => 115_000,
        ], $overrides);
    }
}
