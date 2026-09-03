<?php

namespace Tests\Feature\Compliance;

use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for obligations/disputes/communications/
 * consent-and-delegation (App\Http\Controllers\Compliance\
 * ComplianceViewController / resources/views/compliance/index.blade.php)
 * -- ported from the source's own app/compliance/page.tsx. See
 * AuditCaseViewTest's own doc comment for why this stays light on
 * snapshot-shape assertions (ComplianceSnapshotTest already owns those).
 */
class ComplianceViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation} */
    private function makeTaxpayer(string $vatNumber): array
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => strtolower($vatNumber).'@test.test',
        ]);
        $organisation = Organisation::create([
            'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation');
    }

    private function namraAuditor(string $email = 'auditor@complianceview.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Auditor', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'NAMRA_AUDITOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function namraComplianceOfficer(string $email = 'officer@complianceview.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Compliance Officer', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'NAMRA_COMPLIANCE_OFFICER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function taxpayerOwner(string $taxpayerId, string $email): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Owner', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayerId, 'status' => 'ACTIVE',
        ]);
    }

    private function developerPartner(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Developer Partner', 'email' => 'developer-'.Str::random(8).'@complianceview.test',
            'password' => bcrypt('password'), 'role' => 'DEVELOPER_PARTNER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    public function test_the_compliance_page_requires_authentication(): void
    {
        $this->get('/compliance')->assertRedirect('/login');
    }

    public function test_the_compliance_page_requires_the_compliance_read_permission(): void
    {
        $this->actingAs($this->developerPartner())->get('/compliance')->assertForbidden();
    }

    public function test_the_compliance_page_renders_an_obligation_a_dispute_and_a_notice(): void
    {
        $tp = $this->makeTaxpayer('VAT-COMPLIANCEVIEW-0001');
        $auditor = $this->namraAuditor();
        $officer = $this->namraComplianceOfficer();
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'owner@complianceview.test');

        $obligationId = $this->actingAs($auditor)->postJson('/api/v1/obligations', [
            'schema_version' => '1.0.0', 'taxpayer_id' => $tp['taxpayer']->id, 'obligation_type' => 'VAT_RETURN',
            'period_code' => '2026-07', 'due_date' => '2026-08-25', 'amount_cents' => 250000, 'currency' => 'NAD',
        ], ['Idempotency-Key' => 'test-idem-complianceview-obligation-0001'])->assertStatus(201)->json('resource.id');

        $disputeNumber = $this->actingAs($owner)->postJson('/api/v1/disputes', [
            'schema_version' => '1.0.0', 'disputed_resource_type' => 'OBLIGATION', 'disputed_resource_id' => $obligationId,
            'grounds' => 'The obligation double-counts a period already satisfied by an earlier payment.',
            'disputed_amount_cents' => 250000, 'currency' => 'NAD',
        ], ['Idempotency-Key' => 'test-idem-complianceview-dispute-0001'])->assertStatus(201)->json('resource.dispute_number');

        $caseId = $this->actingAs($officer)->postJson('/api/v1/audit-cases', [
            'schema_version' => '1.0.0', 'taxpayer_id' => $tp['taxpayer']->id, 'case_type' => 'DESK_REVIEW',
            'title' => 'Obligation dispute review', 'opening_reason' => 'Reviewing a taxpayer-filed dispute against an obligation.',
            'risk_tier' => 'LOW',
        ], ['Idempotency-Key' => 'test-idem-complianceview-case-0001'])->assertStatus(201)->json('resource.id');

        $this->actingAs($officer)->postJson('/api/v1/communications/notices', [
            'schema_version' => '1.0.0', 'related_resource_type' => 'AUDIT_CASE', 'related_resource_id' => $caseId, 'channel' => 'PORTAL',
            'subject' => 'Request for supporting documentation', 'content_summary' => 'Please submit the invoices supporting the disputed period within 14 days.',
            'classification' => 'TAX_CONFIDENTIAL',
        ], ['Idempotency-Key' => 'test-idem-complianceview-notice-0001'])->assertStatus(201);

        $response = $this->actingAs($owner)->get('/compliance');

        $response->assertOk()->assertViewIs('compliance.index');
        $response->assertSee('Obligations, disputes and secure communications');
        $response->assertSee('NAD 2,500.00'); // obligation amount
        $response->assertSee($disputeNumber);
        $response->assertSee('Request for supporting documentation');
        $response->assertSee('<caption class="visually-hidden">', false);
        $response->assertSee('scope="col"', false);
    }

    public function test_the_compliance_page_is_scoped_to_the_taxpayers_own_data(): void
    {
        $tpA = $this->makeTaxpayer('VAT-COMPLIANCEVIEW-0002');
        $tpB = $this->makeTaxpayer('VAT-COMPLIANCEVIEW-0003');
        $auditor = $this->namraAuditor();
        $ownerA = $this->taxpayerOwner($tpA['taxpayer']->id, 'owner-a@complianceview.test');

        $this->actingAs($auditor)->postJson('/api/v1/obligations', [
            'schema_version' => '1.0.0', 'taxpayer_id' => $tpA['taxpayer']->id, 'obligation_type' => 'VAT_RETURN',
            'period_code' => '2026-07', 'due_date' => '2026-08-25', 'amount_cents' => 100000, 'currency' => 'NAD',
        ], ['Idempotency-Key' => 'test-idem-complianceview-scope-a'])->assertStatus(201);
        $this->actingAs($auditor)->postJson('/api/v1/obligations', [
            'schema_version' => '1.0.0', 'taxpayer_id' => $tpB['taxpayer']->id, 'obligation_type' => 'VAT_RETURN',
            'period_code' => '2026-07', 'due_date' => '2026-08-25', 'amount_cents' => 100000, 'currency' => 'NAD',
        ], ['Idempotency-Key' => 'test-idem-complianceview-scope-b'])->assertStatus(201);

        $response = $this->actingAs($ownerA)->get('/compliance');

        $response->assertOk();
        $this->assertSame(1, count($response->viewData('snapshot')['obligations']));
    }
}
