<?php

namespace Tests\Feature\Compliance;

use App\Models\AuditCase;
use App\Models\Organisation;
use App\Models\RiskIndicator;
use App\Models\TaxObligation;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for Module 4 Phases A-B (risk indicators) --
 * App\Http\Controllers\Compliance\RiskViewController /
 * resources/views/risk-indicators/** -- the frontend UI build-out's fifth
 * slice, after Dashboard, Invoices, VAT Returns, and Refunds. Reuses
 * ComplianceCaseTest's own makeTaxpayer/namraAuditor/TaxObligation
 * fixture pattern, since a real indicator genuinely depends on live
 * evidence RiskService's own rule catalogue reads, not a fixture
 * inserted directly.
 */
class RiskViewTest extends TestCase
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

    private function namraAuditor(string $email = 'auditor@namra.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Auditor', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'NAMRA_AUDITOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    /** Holds risk:read but not risk:review -- the read-only fixture. */
    private function namraRefundOfficer(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Refund Officer', 'email' => 'refund-officer-'.Str::random(8).'@namra.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_REFUND_OFFICER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function taxpayerOwner(string $taxpayerId): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Owner', 'email' => 'owner-'.Str::random(8).'@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayerId, 'status' => 'ACTIVE',
        ]);
    }

    private function makeOverdueObligation(array $tp, string $periodCode = '2026-06'): void
    {
        TaxObligation::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $tp['organisation']->id, 'taxpayer_id' => $tp['taxpayer']->id,
            'obligation_type' => 'VAT_RETURN', 'period_code' => $periodCode, 'due_date' => '2026-07-25', 'amount_cents' => 100000,
            'currency' => 'NAD', 'status' => 'PENDING', 'source_system' => 'VAT_MSA', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_risk_indicators_list_requires_authentication(): void
    {
        $this->get('/risk-indicators')->assertRedirect('/login');
    }

    public function test_the_risk_indicators_list_is_never_taxpayer_visible(): void
    {
        $tp = $this->makeTaxpayer('VAT-VIEW-RISK-0001');

        $this->actingAs($this->taxpayerOwner($tp['taxpayer']->id))->get('/risk-indicators')->assertForbidden();
    }

    public function test_evaluating_a_taxpayer_raises_a_real_indicator_and_redirects_to_the_filtered_list(): void
    {
        $tp = $this->makeTaxpayer('VAT-VIEW-RISK-0002');
        $this->makeOverdueObligation($tp);

        $response = $this->actingAs($this->namraAuditor())->post(route('risk-indicators.evaluation.store'), [
            'vat_number' => 'VAT-VIEW-RISK-0002',
        ]);

        $indicator = RiskIndicator::where('taxpayer_id', $tp['taxpayer']->id)->where('indicator_code', 'OBLIGATION_OVERDUE')->firstOrFail();
        $response->assertRedirect(route('risk-indicators.index', ['taxpayer_id' => $tp['taxpayer']->id]));
        $this->assertSame('OPEN', $indicator->status);

        $list = $this->actingAs($this->namraAuditor('auditor2@namra.test'))->get(route('risk-indicators.index'));
        $list->assertOk()->assertViewIs('risk-indicators.index');
        $list->assertSee('Obligation Overdue');
        $list->assertSee('VAT-VIEW-RISK-0002 Trading Co');
    }

    public function test_evaluating_an_unknown_vat_number_shows_a_friendly_form_error(): void
    {
        $response = $this->actingAs($this->namraAuditor())->post(route('risk-indicators.evaluation.store'), [
            'vat_number' => 'VAT-DOES-NOT-EXIST',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('vat_number');
    }

    public function test_the_list_page_filters_by_status_and_severity(): void
    {
        $tp = $this->makeTaxpayer('VAT-VIEW-RISK-0003');
        $this->makeOverdueObligation($tp);
        $auditor = $this->namraAuditor();
        $this->actingAs($auditor)->post(route('risk-indicators.evaluation.store'), ['vat_number' => 'VAT-VIEW-RISK-0003']);

        $matched = $this->actingAs($auditor)->get(route('risk-indicators.index', ['status' => 'OPEN', 'severity' => 'HIGH']));
        $matched->assertSee('Obligation Overdue');

        $unmatched = $this->actingAs($auditor)->get(route('risk-indicators.index', ['status' => 'DISMISSED']));
        $unmatched->assertDontSee('Obligation Overdue');
        $unmatched->assertSee('No risk indicators match this view.');
    }

    public function test_a_decision_cannot_be_recorded_before_a_review_is_assigned(): void
    {
        $tp = $this->makeTaxpayer('VAT-VIEW-RISK-0004');
        $this->makeOverdueObligation($tp);
        $auditor = $this->namraAuditor();
        $this->actingAs($auditor)->post(route('risk-indicators.evaluation.store'), ['vat_number' => 'VAT-VIEW-RISK-0004']);
        $indicator = RiskIndicator::where('taxpayer_id', $tp['taxpayer']->id)->firstOrFail();

        $tooEarly = $this->actingAs($auditor)->post(route('risk-indicators.decision.store', $indicator->id), [
            'decision' => 'DISMISS', 'rationale' => 'Attempting to decide before assignment.',
        ]);

        $tooEarly->assertRedirect();
        $tooEarly->assertSessionHasErrors('form');
        $this->assertSame('OPEN', $indicator->fresh()->status);
    }

    public function test_assigning_and_escalating_a_review_creates_a_real_audit_case(): void
    {
        $tp = $this->makeTaxpayer('VAT-VIEW-RISK-0005');
        $this->makeOverdueObligation($tp);
        $auditor = $this->namraAuditor();
        $this->actingAs($auditor)->post(route('risk-indicators.evaluation.store'), ['vat_number' => 'VAT-VIEW-RISK-0005']);
        $indicator = RiskIndicator::where('taxpayer_id', $tp['taxpayer']->id)->firstOrFail();

        $showBefore = $this->actingAs($auditor)->get(route('risk-indicators.show', $indicator->id));
        $showBefore->assertOk()->assertViewIs('risk-indicators.show');
        $showBefore->assertSee('Assign for review');

        $assign = $this->actingAs($auditor)->post(route('risk-indicators.assignment.store', $indicator->id), [
            'officer_id' => $auditor->id,
        ]);
        $assign->assertRedirect(route('risk-indicators.show', $indicator->id));
        $this->assertSame('UNDER_REVIEW', $indicator->fresh()->status);

        $showAfter = $this->actingAs($auditor)->get(route('risk-indicators.show', $indicator->id));
        $showAfter->assertSee('Record a decision');

        $escalate = $this->actingAs($auditor)->post(route('risk-indicators.decision.store', $indicator->id), [
            'decision' => 'ESCALATE_TO_CASE', 'rationale' => 'Confirmed genuine non-compliance pattern warranting a formal audit.',
            'case_type' => 'DESK_REVIEW', 'case_title' => 'Desk review triggered by overdue obligation risk indicator',
        ]);
        $escalate->assertRedirect(route('risk-indicators.show', $indicator->id));
        $indicator->refresh();
        $this->assertSame('ESCALATED_TO_CASE', $indicator->status);
        $this->assertNotNull($indicator->escalated_case_id);
        $this->assertDatabaseHas('audit_cases', ['id' => $indicator->escalated_case_id, 'opening_reason' => 'Confirmed genuine non-compliance pattern warranting a formal audit.']);

        $final = $this->actingAs($auditor)->get(route('risk-indicators.show', $indicator->id));
        $case = AuditCase::findOrFail($indicator->escalated_case_id);
        $final->assertSee($case->case_number);
    }

    public function test_a_read_only_officer_sees_no_action_forms(): void
    {
        $tp = $this->makeTaxpayer('VAT-VIEW-RISK-0006');
        $this->makeOverdueObligation($tp);
        $this->actingAs($this->namraAuditor())->post(route('risk-indicators.evaluation.store'), ['vat_number' => 'VAT-VIEW-RISK-0006']);
        $indicator = RiskIndicator::where('taxpayer_id', $tp['taxpayer']->id)->firstOrFail();

        $response = $this->actingAs($this->namraRefundOfficer())->get(route('risk-indicators.show', $indicator->id));

        $response->assertOk();
        $response->assertDontSee('Assign for review');

        $evaluateAttempt = $this->actingAs($this->namraRefundOfficer())->post(route('risk-indicators.evaluation.store'), ['vat_number' => 'VAT-VIEW-RISK-0006']);
        $evaluateAttempt->assertForbidden();
    }
}
