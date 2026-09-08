<?php

namespace Tests\Feature\Portal;

use App\Models\ApprovalTask;
use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\TaxObligation;
use App\Models\User;
use App\Models\VatPeriod;
use App\Models\VatReturnVersion;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TaxRuleSetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for the NamRA portal dashboard
 * (App\Http\Controllers\Portal\NamraPortalController /
 * resources/views/portal/namra.blade.php / App\Services\Portal\
 * NamraPortalSnapshotService) -- ported from the source's own
 * app/portal/namra/page.tsx. NamraPortalSnapshotService is pure
 * composition (no new query of its own), so this file's own job is
 * proving the portal-access gate and the view's own rendering --
 * ComplianceSnapshotTest/VatReturnLifecycleTest/the identity foundation
 * tests already cover the underlying services end to end.
 */
class NamraPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(TaxRuleSetSeeder::class);
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

    private function namraAuditor(string $email = 'auditor@namraportal.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Auditor', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'NAMRA_AUDITOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    public function test_the_namra_portal_requires_authentication(): void
    {
        $this->get('/portal/namra')->assertRedirect('/login');
    }

    public function test_a_role_not_on_the_namra_portals_list_is_denied(): void
    {
        $tp = $this->makeTaxpayer('VAT-NAMRAPORTAL-0001');
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Owner', 'email' => 'owner@namraportal.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $tp['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($owner)->get('/portal/namra')->assertForbidden();
    }

    public function test_the_namra_portal_renders_taxpayer_case_risk_and_approval_metrics(): void
    {
        $tp = $this->makeTaxpayer('VAT-NAMRAPORTAL-0002');
        $auditor = $this->namraAuditor();

        $caseId = $this->actingAs($auditor)->postJson('/api/v1/audit-cases', [
            'schema_version' => '1.0.0', 'taxpayer_id' => $tp['taxpayer']->id, 'case_type' => 'VAT_AUDIT',
            'title' => 'Suspected under-declaration of output VAT', 'opening_reason' => 'Recurring high-value invoice risk pattern flagged by the risk engine.',
            'risk_tier' => 'HIGH',
        ], ['Idempotency-Key' => 'test-idem-namraportal-case-0001'])->assertStatus(201)->json('resource.id');

        TaxObligation::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $tp['organisation']->id, 'taxpayer_id' => $tp['taxpayer']->id,
            'obligation_type' => 'VAT_RETURN', 'period_code' => '2026-06', 'due_date' => '2026-07-25', 'amount_cents' => 100000,
            'currency' => 'NAD', 'status' => 'PENDING', 'source_system' => 'VAT_MSA', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($auditor)->postJson("/api/v1/taxpayers/{$tp['taxpayer']->id}/risk-evaluation", ['schema_version' => '1.0.0'], ['Idempotency-Key' => 'test-idem-namraportal-risk-0001'])->assertStatus(200);

        $periodId = (string) Str::uuid();
        VatPeriod::create([
            'id' => $periodId, 'organisation_id' => $tp['organisation']->id, 'taxpayer_id' => $tp['taxpayer']->id,
            'period_code' => '2026-08', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'due_date' => '2026-09-25',
            'status' => 'OPEN', 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $versionId = (string) Str::uuid();
        VatReturnVersion::create([
            'id' => $versionId, 'vat_period_id' => $periodId, 'organisation_id' => $tp['organisation']->id, 'taxpayer_id' => $tp['taxpayer']->id,
            'version_number' => 1, 'parent_version_id' => null, 'tax_rule_set_id' => 'taxrule-na-pilot-2026-1',
            'output_tax_cents' => 15000, 'input_tax_cents' => 0, 'adjustment_cents' => 0, 'net_payable_cents' => 15000,
            'status' => 'PENDING_APPROVAL', 'ledger_snapshot_hash' => str_repeat('c', 64), 'generated_by' => $auditor->id, 'generated_at' => now(),
        ]);
        ApprovalTask::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $tp['organisation']->id, 'taxpayer_id' => $tp['taxpayer']->id,
            'domain' => 'VAT_RETURN', 'resource_type' => 'VAT_RETURN_VERSION', 'resource_id' => $versionId,
            'requested_action' => 'APPROVE_RETURN', 'risk_tier' => 'MEDIUM', 'status' => 'PENDING',
            'requested_by' => $auditor->id, 'assigned_role' => 'NAMRA_SUPERVISOR', 'requested_at' => now(),
        ]);

        $response = $this->actingAs($auditor)->get('/portal/namra');

        $response->assertOk()->assertViewIs('portal.namra');
        $response->assertSee('Due, abnormal, unresolved and assigned work');
        $response->assertSee('Suspected under-declaration of output VAT');
        $response->assertSee($tp['taxpayer']->legal_name);
        $response->assertSee('<caption class="visually-hidden">', false);
        $response->assertSee('scope="col"', false);

        $snapshot = $response->viewData('snapshot');
        $this->assertGreaterThanOrEqual(1, count($snapshot['identity']['organisations']));
        $this->assertTrue(collect($snapshot['compliance']['cases'])->contains('id', $caseId));
        $this->assertTrue(collect($snapshot['compliance']['risks'])->contains(fn ($r) => $r['taxpayer_id'] === $tp['taxpayer']->id && $r['status'] === 'OPEN'));
        $this->assertTrue(collect($snapshot['vat']['approvals'])->contains(fn ($a) => $a['resource_id'] === $versionId && $a['status'] === 'PENDING'));
    }
}
