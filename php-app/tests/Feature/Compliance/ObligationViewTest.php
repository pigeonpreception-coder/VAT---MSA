<?php

namespace Tests\Feature\Compliance;

use App\Models\Organisation;
use App\Models\TaxObligation;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for the tax obligations module (Module 3 Phase
 * D) -- App\Http\Controllers\Compliance\ObligationViewController /
 * resources/views/obligations/index.blade.php -- the frontend UI
 * build-out's eighth slice, and the second fresh, smaller PR after
 * Disputes. Reuses RiskViewTest's own makeTaxpayer/namraAuditor/
 * taxpayerOwner/namraRefundOfficer fixture pattern.
 *
 * Like Risk Indicators (officer-only writes) but unlike Disputes
 * (taxpayer-initiated): ObligationService::create()/markSatisfied() both
 * independently enforce national-scope only, so the create and
 * mark-satisfied forms are officer-gated, while the list itself stays
 * readable by a taxpayer for their own obligations (compliance:read).
 */
class ObligationViewTest extends TestCase
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

    private function namraAuditor(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Auditor', 'email' => 'auditor-'.Str::random(8).'@namra.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_AUDITOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    /** Holds compliance:read but not obligations:manage -- the read-only fixture. */
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

    public function test_the_obligations_list_requires_authentication(): void
    {
        $this->get('/obligations')->assertRedirect('/login');
    }

    public function test_a_taxpayer_can_read_their_own_obligations_but_sees_no_create_form(): void
    {
        $tp = $this->makeTaxpayer('VAT-VIEW-OBL-0001');
        TaxObligation::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $tp['organisation']->id, 'taxpayer_id' => $tp['taxpayer']->id,
            'obligation_type' => 'VAT_RETURN', 'period_code' => '2026-06', 'due_date' => '2026-07-25', 'amount_cents' => 100000,
            'currency' => 'NAD', 'status' => 'PENDING', 'source_system' => 'VAT_MSA', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->taxpayerOwner($tp['taxpayer']->id))->get('/obligations');

        $response->assertOk()->assertViewIs('obligations.index');
        $response->assertSee('VAT-VIEW-OBL-0001 Trading Co');
        $response->assertDontSee('Create an obligation');
        $response->assertDontSee('Mark satisfied');
    }

    public function test_a_taxpayer_never_sees_another_taxpayers_obligation(): void
    {
        $tpA = $this->makeTaxpayer('VAT-VIEW-OBL-0002');
        $tpB = $this->makeTaxpayer('VAT-VIEW-OBL-0003');
        TaxObligation::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $tpB['organisation']->id, 'taxpayer_id' => $tpB['taxpayer']->id,
            'obligation_type' => 'VAT_RETURN', 'period_code' => '2026-06', 'due_date' => '2026-07-25', 'amount_cents' => 50000,
            'currency' => 'NAD', 'status' => 'PENDING', 'source_system' => 'VAT_MSA', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->taxpayerOwner($tpA['taxpayer']->id))->get('/obligations');

        $response->assertOk();
        $response->assertDontSee('VAT-VIEW-OBL-0003 Trading Co');
        $response->assertSee('No obligations match this view.');
    }

    public function test_a_national_officer_can_create_an_obligation_by_vat_number(): void
    {
        $tp = $this->makeTaxpayer('VAT-VIEW-OBL-0004');
        $auditor = $this->namraAuditor();

        $index = $this->actingAs($auditor)->get('/obligations');
        $index->assertOk()->assertSee('Create an obligation');

        $response = $this->actingAs($auditor)->post(route('obligations.store'), [
            'vat_number' => 'VAT-VIEW-OBL-0004', 'obligation_type' => 'vat_return', 'period_code' => '2026-08',
            'due_date' => '2026-09-25', 'amount' => '2500.00',
        ]);

        $obligation = TaxObligation::where('taxpayer_id', $tp['taxpayer']->id)->firstOrFail();
        $response->assertRedirect(route('obligations.index'));
        $this->assertSame('VAT_RETURN', $obligation->obligation_type);
        $this->assertSame('PENDING', $obligation->status);
        $this->assertSame(250000, $obligation->amount_cents);
    }

    public function test_creating_against_an_unknown_vat_number_shows_a_friendly_form_error(): void
    {
        $response = $this->actingAs($this->namraAuditor())->post(route('obligations.store'), [
            'vat_number' => 'VAT-DOES-NOT-EXIST', 'obligation_type' => 'VAT_RETURN', 'period_code' => '2026-08',
            'due_date' => '2026-09-25', 'amount' => '100',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('vat_number');
        $this->assertDatabaseCount('tax_obligations', 0);
    }

    public function test_creating_a_duplicate_obligation_for_the_same_taxpayer_type_and_period_is_a_friendly_form_error(): void
    {
        $tp = $this->makeTaxpayer('VAT-VIEW-OBL-0005');
        $auditor = $this->namraAuditor();
        $this->actingAs($auditor)->post(route('obligations.store'), [
            'vat_number' => 'VAT-VIEW-OBL-0005', 'obligation_type' => 'VAT_RETURN', 'period_code' => '2026-08',
            'due_date' => '2026-09-25', 'amount' => '100',
        ]);

        $response = $this->actingAs($auditor)->post(route('obligations.store'), [
            'vat_number' => 'VAT-VIEW-OBL-0005', 'obligation_type' => 'VAT_RETURN', 'period_code' => '2026-08',
            'due_date' => '2026-09-25', 'amount' => '150',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('form');
        $this->assertSame(1, TaxObligation::where('taxpayer_id', $tp['taxpayer']->id)->count());
    }

    public function test_marking_an_obligation_satisfied_updates_its_status_and_removes_the_action(): void
    {
        $tp = $this->makeTaxpayer('VAT-VIEW-OBL-0006');
        $auditor = $this->namraAuditor();
        $this->actingAs($auditor)->post(route('obligations.store'), [
            'vat_number' => 'VAT-VIEW-OBL-0006', 'obligation_type' => 'VAT_RETURN', 'period_code' => '2026-08',
            'due_date' => '2026-09-25', 'amount' => '100',
        ]);
        $obligation = TaxObligation::where('taxpayer_id', $tp['taxpayer']->id)->firstOrFail();

        $response = $this->actingAs($auditor)->post(route('obligations.satisfaction.store', $obligation->id), [
            'notes' => 'Payment confirmed and reconciled against the pilot ledger.',
        ]);

        $response->assertRedirect(route('obligations.index'));
        $this->assertSame('SATISFIED', $obligation->fresh()->status);

        $index = $this->actingAs($auditor)->get(route('obligations.index'));
        $index->assertDontSee('Mark satisfied');
    }

    public function test_marking_satisfied_with_notes_too_short_shows_a_field_error(): void
    {
        $tp = $this->makeTaxpayer('VAT-VIEW-OBL-0007');
        $auditor = $this->namraAuditor();
        $this->actingAs($auditor)->post(route('obligations.store'), [
            'vat_number' => 'VAT-VIEW-OBL-0007', 'obligation_type' => 'VAT_RETURN', 'period_code' => '2026-08',
            'due_date' => '2026-09-25', 'amount' => '100',
        ]);
        $obligation = TaxObligation::where('taxpayer_id', $tp['taxpayer']->id)->firstOrFail();

        $response = $this->actingAs($auditor)->post(route('obligations.satisfaction.store', $obligation->id), [
            'notes' => 'Short',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('notes');
        $this->assertSame('PENDING', $obligation->fresh()->status);
    }

    public function test_a_read_only_officer_sees_no_forms_and_cannot_post(): void
    {
        $tp = $this->makeTaxpayer('VAT-VIEW-OBL-0008');
        $auditor = $this->namraAuditor();
        $this->actingAs($auditor)->post(route('obligations.store'), [
            'vat_number' => 'VAT-VIEW-OBL-0008', 'obligation_type' => 'VAT_RETURN', 'period_code' => '2026-08',
            'due_date' => '2026-09-25', 'amount' => '100',
        ]);
        $obligation = TaxObligation::where('taxpayer_id', $tp['taxpayer']->id)->firstOrFail();
        $officer = $this->namraRefundOfficer();

        $index = $this->actingAs($officer)->get('/obligations');
        $index->assertOk();
        $index->assertDontSee('Create an obligation');
        $index->assertDontSee('Mark satisfied');

        $createAttempt = $this->actingAs($officer)->post(route('obligations.store'), [
            'vat_number' => 'VAT-VIEW-OBL-0008', 'obligation_type' => 'PAYE', 'period_code' => '2026-08',
            'due_date' => '2026-09-25', 'amount' => '100',
        ]);
        $createAttempt->assertForbidden();

        $satisfyAttempt = $this->actingAs($officer)->post(route('obligations.satisfaction.store', $obligation->id), [
            'notes' => 'Attempting to satisfy without obligations:manage held.',
        ]);
        $satisfyAttempt->assertForbidden();
    }

    public function test_the_list_page_filters_by_status(): void
    {
        $tp = $this->makeTaxpayer('VAT-VIEW-OBL-0009');
        $auditor = $this->namraAuditor();
        $this->actingAs($auditor)->post(route('obligations.store'), [
            'vat_number' => 'VAT-VIEW-OBL-0009', 'obligation_type' => 'VAT_RETURN', 'period_code' => '2026-08',
            'due_date' => '2026-09-25', 'amount' => '100',
        ]);

        $matched = $this->actingAs($auditor)->get(route('obligations.index', ['status' => 'PENDING']));
        $matched->assertSee('VAT-VIEW-OBL-0009 Trading Co');

        $unmatched = $this->actingAs($auditor)->get(route('obligations.index', ['status' => 'SATISFIED']));
        $unmatched->assertSee('No obligations match this view.');
    }
}
