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
 * Covers the real Blade UI for the audit-case register
 * (App\Http\Controllers\Compliance\AuditCaseViewController /
 * resources/views/compliance/cases.blade.php) -- ported from the
 * source's own app/cases/page.tsx. Reuses
 * App\Services\Compliance\ComplianceSnapshotService, already covered end
 * to end (field shape, scoping, joins) by ComplianceSnapshotTest -- this
 * file's own job is proving the view renders that data and is gated
 * correctly, matching InvoiceViewTest's own division of labour.
 */
class AuditCaseViewTest extends TestCase
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

    private function namraAuditor(string $email = 'auditor@casesview.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Auditor', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'NAMRA_AUDITOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function namraSupervisor(string $email = 'supervisor@casesview.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Supervisor', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'NAMRA_SUPERVISOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function taxpayerOwner(string $taxpayerId, string $email): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Owner', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayerId, 'status' => 'ACTIVE',
        ]);
    }

    /** Advances a freshly opened case through AUTHORIZE/ASSIGN/ADVANCE* to the given target status, asserting 200 at every step. */
    private function advanceCaseTo(User $actor, string $caseId, string $targetStatus, string $keyPrefix): void
    {
        $order = ['PROPOSED', 'AUTHORIZED', 'ASSIGNED', 'PLANNING', 'EVIDENCE_COLLECTION', 'ANALYSIS', 'TAXPAYER_RESPONSE', 'FINDINGS_REVIEW', 'DECISION'];
        $steps = array_slice($order, 1, array_search($targetStatus, $order, true));
        foreach ($steps as $i => $status) {
            $action = $status === 'AUTHORIZED' ? 'AUTHORIZE' : ($status === 'ASSIGNED' ? 'ASSIGN' : 'ADVANCE');
            $payload = ['schema_version' => '1.0.0', 'action' => $action, 'reason' => "Advancing the case to {$status}."];
            if ($action === 'ASSIGN') {
                $payload['officer_id'] = $actor->id;
            }
            $this->actingAs($actor)->postJson("/api/v1/audit-cases/{$caseId}/transition", $payload, ['Idempotency-Key' => "{$keyPrefix}-{$i}"])->assertStatus(200);
        }
    }

    public function test_the_cases_page_requires_authentication(): void
    {
        $this->get('/cases')->assertRedirect('/login');
    }

    public function test_the_cases_page_requires_the_cases_manage_permission(): void
    {
        $tp = $this->makeTaxpayer('VAT-CASESVIEW-0001');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'owner@casesview.test');

        // TAXPAYER_OWNER holds compliance:read but not cases:manage.
        $this->actingAs($owner)->get('/cases')->assertForbidden();
    }

    public function test_the_cases_page_renders_a_case_and_its_finding(): void
    {
        $tp = $this->makeTaxpayer('VAT-CASESVIEW-0002');
        $auditor = $this->namraAuditor();
        $supervisor = $this->namraSupervisor();

        $caseId = $this->actingAs($auditor)->postJson('/api/v1/audit-cases', [
            'schema_version' => '1.0.0', 'taxpayer_id' => $tp['taxpayer']->id, 'case_type' => 'VAT_AUDIT',
            'title' => 'Suspected under-declaration of output VAT', 'opening_reason' => 'Recurring high-value invoice risk pattern flagged by the risk engine.',
            'risk_tier' => 'HIGH',
        ], ['Idempotency-Key' => 'test-idem-casesview-case-0001'])->assertStatus(201)->json('resource.id');

        $this->advanceCaseTo($auditor, $caseId, 'ANALYSIS', 'test-idem-casesview-advance');
        $this->actingAs($supervisor)->postJson("/api/v1/audit-cases/{$caseId}/findings", [
            'schema_version' => '1.0.0', 'finding_code' => 'UNDERSTATED-OUTPUT-VAT', 'title' => 'Understated output VAT',
            'description' => 'Output VAT for the period appears understated relative to certified invoice records.', 'amount_cents' => 500000, 'currency' => 'NAD',
        ], ['Idempotency-Key' => 'test-idem-casesview-finding-0001'])->assertStatus(201);

        $response = $this->actingAs($auditor)->get('/cases');

        $response->assertOk()->assertViewIs('compliance.cases');
        $response->assertSee('Evidence-led audit cases and advisory risk');
        $response->assertSee($tp['taxpayer']->legal_name);
        $response->assertSee('Suspected under-declaration of output VAT');
        $response->assertSee('Understated output VAT');
        $response->assertSee('NAD 5,000.00'); // finding amount
        $response->assertSee('<caption class="visually-hidden">', false);
        $response->assertSee('scope="col"', false);
    }

    public function test_the_cases_page_is_scoped_to_the_taxpayers_own_cases_for_a_national_actor(): void
    {
        $tpA = $this->makeTaxpayer('VAT-CASESVIEW-0003');
        $tpB = $this->makeTaxpayer('VAT-CASESVIEW-0004');
        $auditor = $this->namraAuditor();

        $this->actingAs($auditor)->postJson('/api/v1/audit-cases', [
            'schema_version' => '1.0.0', 'taxpayer_id' => $tpA['taxpayer']->id, 'case_type' => 'VAT_AUDIT',
            'title' => 'Case Alpha', 'opening_reason' => 'Risk pattern flagged for taxpayer A.', 'risk_tier' => 'MEDIUM',
        ], ['Idempotency-Key' => 'test-idem-casesview-scope-a'])->assertStatus(201);
        $this->actingAs($auditor)->postJson('/api/v1/audit-cases', [
            'schema_version' => '1.0.0', 'taxpayer_id' => $tpB['taxpayer']->id, 'case_type' => 'VAT_AUDIT',
            'title' => 'Case Bravo', 'opening_reason' => 'Risk pattern flagged for taxpayer B.', 'risk_tier' => 'MEDIUM',
        ], ['Idempotency-Key' => 'test-idem-casesview-scope-b'])->assertStatus(201);

        // A national actor's /cases page is unscoped -- both cases appear.
        $response = $this->actingAs($auditor)->get('/cases');
        $response->assertOk()->assertSee('Case Alpha')->assertSee('Case Bravo');
    }
}
