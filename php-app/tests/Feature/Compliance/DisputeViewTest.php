<?php

namespace Tests\Feature\Compliance;

use App\Models\Dispute;
use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for the disputes module --
 * App\Http\Controllers\Compliance\DisputeViewController /
 * resources/views/disputes/** -- the frontend UI build-out's seventh slice,
 * the first fresh, smaller PR after PR #2 (VAT Returns & Periods, Refunds,
 * Risk Indicators, Audit Cases) merged to main. Reuses RiskViewTest's own
 * makeTaxpayer/namraAuditor/taxpayerOwner fixture pattern.
 *
 * Unlike Risk Indicators (officer-only) and Audit Cases (officer-initiated,
 * taxpayer read-only), disputes are taxpayer-INITIATED: DisputeService::file()
 * lets a taxpayer self-file against their own case/finding/return/decision,
 * so this file exercises both a taxpayer filing for themselves (no VAT-number
 * picker) and a national officer filing on a taxpayer's behalf (picker shown).
 */
class DisputeViewTest extends TestCase
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

    private function taxpayerOwner(string $taxpayerId): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Owner', 'email' => 'owner-'.Str::random(8).'@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayerId, 'status' => 'ACTIVE',
        ]);
    }

    private function namraAuditor(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Auditor', 'email' => 'auditor-'.Str::random(8).'@namra.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_AUDITOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    /** Holds compliance:read but not disputes:manage -- the read-only fixture. */
    private function namraRefundOfficer(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Refund Officer', 'email' => 'refund-officer-'.Str::random(8).'@namra.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_REFUND_OFFICER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    public function test_the_disputes_list_requires_authentication(): void
    {
        $this->get('/disputes')->assertRedirect('/login');
    }

    public function test_a_taxpayer_can_self_file_a_dispute_with_no_taxpayer_picker(): void
    {
        $tp = $this->makeTaxpayer('VAT-VIEW-DSP-0001');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id);

        $index = $this->actingAs($owner)->get('/disputes');
        $index->assertOk()->assertViewIs('disputes.index');
        $index->assertDontSee('Taxpayer VAT number');

        $response = $this->actingAs($owner)->post(route('disputes.store'), [
            'disputed_resource_type' => 'VAT_RETURN', 'disputed_resource_id' => (string) Str::uuid(),
            'grounds' => 'The assessed liability does not reflect the input tax credits actually claimed on the return.',
            'disputed_amount' => '1250.50',
        ]);

        $dispute = Dispute::where('taxpayer_id', $tp['taxpayer']->id)->firstOrFail();
        $response->assertRedirect(route('disputes.show', $dispute->id));
        $this->assertSame('FILED', $dispute->status);
        $this->assertSame(125050, $dispute->disputed_amount_cents);

        $show = $this->actingAs($owner)->get(route('disputes.show', $dispute->id));
        $show->assertOk()->assertViewIs('disputes.show');
        $show->assertSee($dispute->dispute_number);
        $show->assertSee('Awaiting review assignment');
    }

    public function test_a_national_officer_filing_on_behalf_of_a_taxpayer_sees_a_vat_number_picker(): void
    {
        $tp = $this->makeTaxpayer('VAT-VIEW-DSP-0002');
        $auditor = $this->namraAuditor();

        $index = $this->actingAs($auditor)->get('/disputes');
        $index->assertOk();
        $index->assertSee('Taxpayer VAT number');

        $response = $this->actingAs($auditor)->post(route('disputes.store'), [
            'vat_number' => 'VAT-VIEW-DSP-0002',
            'disputed_resource_type' => 'REFUND_DECISION', 'disputed_resource_id' => (string) Str::uuid(),
            'grounds' => 'The refund decision under-credited input tax that was properly substantiated by the taxpayer.',
            'disputed_amount' => '500',
        ]);

        $dispute = Dispute::where('taxpayer_id', $tp['taxpayer']->id)->firstOrFail();
        $response->assertRedirect(route('disputes.show', $dispute->id));
    }

    public function test_filing_against_an_unknown_vat_number_shows_a_friendly_form_error(): void
    {
        $response = $this->actingAs($this->namraAuditor())->post(route('disputes.store'), [
            'vat_number' => 'VAT-DOES-NOT-EXIST',
            'disputed_resource_type' => 'OBLIGATION', 'disputed_resource_id' => (string) Str::uuid(),
            'grounds' => 'Disputing an obligation raised against an unregistered VAT number.',
            'disputed_amount' => '100',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('vat_number');
        $this->assertDatabaseCount('disputes', 0);
    }

    public function test_filing_with_grounds_too_short_shows_a_field_error(): void
    {
        $tp = $this->makeTaxpayer('VAT-VIEW-DSP-0003');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id);

        $response = $this->actingAs($owner)->post(route('disputes.store'), [
            'disputed_resource_type' => 'VAT_RETURN', 'disputed_resource_id' => (string) Str::uuid(),
            'grounds' => 'Too short.', 'disputed_amount' => '100',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('grounds');
        $this->assertDatabaseCount('disputes', 0);
    }

    public function test_a_read_only_officer_sees_no_filing_form_and_cannot_post(): void
    {
        $officer = $this->namraRefundOfficer();

        $index = $this->actingAs($officer)->get('/disputes');
        $index->assertOk();
        $index->assertDontSee('File a dispute');

        $attempt = $this->actingAs($officer)->post(route('disputes.store'), [
            'vat_number' => 'VAT-VIEW-DSP-0004',
            'disputed_resource_type' => 'OBLIGATION', 'disputed_resource_id' => (string) Str::uuid(),
            'grounds' => 'Attempting to file without the disputes:manage permission held.',
            'disputed_amount' => '100',
        ]);
        $attempt->assertForbidden();
    }

    public function test_a_taxpayer_cannot_view_another_taxpayers_dispute(): void
    {
        $tpA = $this->makeTaxpayer('VAT-VIEW-DSP-0005');
        $tpB = $this->makeTaxpayer('VAT-VIEW-DSP-0006');
        $ownerA = $this->taxpayerOwner($tpA['taxpayer']->id);
        $ownerB = $this->taxpayerOwner($tpB['taxpayer']->id);

        $this->actingAs($ownerA)->post(route('disputes.store'), [
            'disputed_resource_type' => 'VAT_RETURN', 'disputed_resource_id' => (string) Str::uuid(),
            'grounds' => 'A dispute belonging to taxpayer A only, not visible to taxpayer B.',
            'disputed_amount' => '75',
        ]);
        $dispute = Dispute::where('taxpayer_id', $tpA['taxpayer']->id)->firstOrFail();

        $this->actingAs($ownerB)->get(route('disputes.show', $dispute->id))->assertNotFound();
    }

    public function test_the_list_page_filters_by_status(): void
    {
        $tp = $this->makeTaxpayer('VAT-VIEW-DSP-0007');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id);
        $this->actingAs($owner)->post(route('disputes.store'), [
            'disputed_resource_type' => 'AUDIT_FINDING', 'disputed_resource_id' => (string) Str::uuid(),
            'grounds' => 'Disputing a finding that mischaracterised a legitimate zero-rated export sale.',
            'disputed_amount' => '300',
        ]);

        $matched = $this->actingAs($owner)->get(route('disputes.index', ['status' => 'FILED']));
        $matched->assertSee('Audit Finding');

        $unmatched = $this->actingAs($owner)->get(route('disputes.index', ['status' => 'DISPUTED']));
        $unmatched->assertSee('No disputes match this view.');
    }
}
