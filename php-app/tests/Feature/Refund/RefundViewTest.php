<?php

namespace Tests\Feature\Refund;

use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\User;
use App\Models\VatPeriod;
use App\Models\VatReturnVersion;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TaxRuleSetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for the refund claim register
 * (App\Http\Controllers\Refund\RefundViewController /
 * resources/views/refunds/index.blade.php) -- ported from the source's
 * own app/refunds/page.tsx. Reuses ComplianceSnapshotTest's own
 * "insert refund_claims/refund_claim_transitions directly, via a real
 * vat_periods/vat_return_versions row" fixture convention -- see that
 * class's own doc comment for why: RefundClaimTest already covers the
 * RequestRefund command chain end to end, so this file's own job is
 * proving the view renders that data and is gated correctly.
 */
class RefundViewTest extends TestCase
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
            'id' => (string) Str::uuid(), 'name' => 'Developer Partner', 'email' => 'developer-'.Str::random(8).'@refundview.test',
            'password' => bcrypt('password'), 'role' => 'DEVELOPER_PARTNER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    /** @return array{claimId: string} */
    private function insertRefundFixture(Organisation $organisation, Taxpayer $taxpayer, User $actor, int $amountCents = 200000): array
    {
        $periodId = (string) Str::uuid();
        VatPeriod::create([
            'id' => $periodId, 'organisation_id' => $organisation->id, 'taxpayer_id' => $taxpayer->id,
            'period_code' => '2026-08', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'due_date' => '2026-09-25',
            'status' => 'OPEN', 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $versionId = (string) Str::uuid();
        VatReturnVersion::create([
            'id' => $versionId, 'vat_period_id' => $periodId, 'organisation_id' => $organisation->id, 'taxpayer_id' => $taxpayer->id,
            'version_number' => 1, 'parent_version_id' => null, 'tax_rule_set_id' => 'taxrule-na-pilot-2026-1',
            'output_tax_cents' => 0, 'input_tax_cents' => $amountCents, 'adjustment_cents' => 0, 'net_payable_cents' => -$amountCents,
            'status' => 'FILED', 'ledger_snapshot_hash' => str_repeat('c', 64), 'generated_by' => $actor->id, 'generated_at' => now(),
        ]);
        $claimId = (string) Str::uuid();
        $now = now();
        DB::table('refund_claims')->insert([
            'id' => $claimId, 'claim_number' => 'RFD-2026-'.mb_strtoupper(Str::random(8)), 'organisation_id' => $organisation->id,
            'taxpayer_id' => $taxpayer->id, 'vat_return_version_id' => $versionId, 'amount_cents' => $amountCents, 'currency' => 'NAD',
            'status' => 'RECEIVED', 'evidence_status' => 'PENDING_REVIEW', 'risk_tier' => 'MEDIUM', 'requested_by' => $actor->id,
            'requested_at' => $now, 'offset_amount_cents' => 0,
        ]);

        return ['claimId' => $claimId];
    }

    public function test_the_refunds_page_requires_authentication(): void
    {
        $this->get('/refunds')->assertRedirect('/login');
    }

    public function test_the_refunds_page_requires_the_refunds_read_permission(): void
    {
        $this->actingAs($this->developerPartner())->get('/refunds')->assertForbidden();
    }

    public function test_the_refunds_page_renders_a_refund_claim(): void
    {
        $tp = $this->makeTaxpayer('VAT-REFUNDVIEW-0001');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'owner@refundview.test');
        $this->insertRefundFixture($tp['organisation'], $tp['taxpayer'], $owner, 200000);

        $response = $this->actingAs($owner)->get('/refunds');

        $response->assertOk()->assertViewIs('refunds.index');
        $response->assertSee('Evidence, risk and payment authorisation');
        // A taxpayer-scoped actor's own query never joins taxpayers (matching
        // the source's own unscoped-vs-scoped branch) -- the taxpayer_id
        // fallback is what actually renders here, not legal_name.
        $response->assertSee($tp['taxpayer']->id);
        $response->assertSee('2026-08');
        $response->assertSee('NAD 2,000.00'); // requested value metric + claim amount
        $response->assertSee('Payment execution remains disabled by design.');
        $response->assertSee('<caption class="visually-hidden">', false);
        $response->assertSee('scope="col"', false);
    }

    public function test_the_refunds_page_is_scoped_to_the_taxpayers_own_claims(): void
    {
        $tpA = $this->makeTaxpayer('VAT-REFUNDVIEW-0002');
        $tpB = $this->makeTaxpayer('VAT-REFUNDVIEW-0003');
        $ownerA = $this->taxpayerOwner($tpA['taxpayer']->id, 'owner-a@refundview.test');
        $ownerB = $this->taxpayerOwner($tpB['taxpayer']->id, 'owner-b@refundview.test');
        $this->insertRefundFixture($tpA['organisation'], $tpA['taxpayer'], $ownerA, 100000);
        $this->insertRefundFixture($tpB['organisation'], $tpB['taxpayer'], $ownerB, 100000);

        $response = $this->actingAs($ownerA)->get('/refunds');

        $response->assertOk();
        $this->assertSame(1, count($response->viewData('snapshot')['refunds']));
    }
}
