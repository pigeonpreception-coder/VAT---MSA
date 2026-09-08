<?php

namespace Tests\Feature\Portal;

use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\Taxpayer;
use App\Models\User;
use App\Models\VatPeriod;
use App\Models\VatReturnVersion;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TaxRuleSetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for the Buyer portal dashboard
 * (App\Http\Controllers\Portal\BuyerPortalController /
 * resources/views/portal/buyer.blade.php / App\Services\Portal\
 * BuyerPortalSnapshotService) -- ported from the source's own
 * app/portal/buyer/page.tsx. Reuses ExpenseTest's own "create via the
 * real /api/v1/expenses command chain" convention (ExpenseService itself
 * is already covered end to end there) and PortalViewTest's own
 * capability-fixture convention (OrganisationCapability) -- this file's
 * own job is proving the portal-access gate and the view's own
 * rendering, not re-proving either of those services.
 */
class BuyerPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(TaxRuleSetSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, owner: User} */
    private function makeTradingParty(string $vatNumber, array $capabilities = ['BUYER']): array
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => strtolower($vatNumber).'@test.test',
        ]);
        $organisation = Organisation::create([
            'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE',
        ]);
        foreach ($capabilities as $capability) {
            OrganisationCapability::create([
                'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'capability' => $capability,
                'status' => 'ACTIVE', 'effective_from' => now()->subDay(), 'created_at' => now(),
            ]);
        }
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    private function createCategory(User $owner, string $code = 'TRAVEL'): string
    {
        $response = $this->actingAs($owner)->postJson('/api/v1/expenses/categories', [
            'schema_version' => '1.0.0', 'code' => $code, 'name' => "Category {$code}", 'default_tax_category' => 'STANDARD', 'requires_receipt' => true,
        ], ['Idempotency-Key' => 'test-idem-buyerportal-cat-'.$code]);

        return $response->json('resource.id');
    }

    private function createAndApproveExpense(User $owner, User $approver, string $categoryId, array $overrides = []): string
    {
        $payload = array_replace_recursive([
            'schema_version' => '1.0.0', 'category_id' => $categoryId, 'expense_number' => 'EXP-BUYERPORTAL-0001',
            'expense_date' => '2026-09-01', 'description' => 'Client travel expense', 'currency' => 'NAD',
            'net_cents' => 100000, 'tax_cents' => 15000, 'total_cents' => 115000,
        ], $overrides);
        $expenseId = $this->actingAs($owner)->postJson('/api/v1/expenses', $payload, ['Idempotency-Key' => 'test-idem-buyerportal-create-'.$payload['expense_number']])
            ->assertStatus(201)->json('resource.id');
        $this->actingAs($owner)->postJson("/api/v1/expenses/{$expenseId}/submission", [], ['Idempotency-Key' => 'test-idem-buyerportal-submit-'.$payload['expense_number']])->assertStatus(200);
        $this->actingAs($approver)->postJson("/api/v1/expenses/{$expenseId}/approval", [], ['Idempotency-Key' => 'test-idem-buyerportal-approve-'.$payload['expense_number']])->assertStatus(200);

        return $expenseId;
    }

    public function test_the_buyer_portal_requires_authentication(): void
    {
        $this->get('/portal/buyer')->assertRedirect('/login');
    }

    public function test_a_role_not_on_the_buyer_portals_list_is_denied(): void
    {
        $auditor = User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Auditor', 'email' => 'auditor@buyerportal.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_AUDITOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($auditor)->get('/portal/buyer')->assertForbidden();
    }

    public function test_a_taxpayer_owner_without_buyer_capability_is_denied(): void
    {
        $party = $this->makeTradingParty('VAT-BUYERPORTAL-0001', capabilities: []);

        $this->actingAs($party['owner'])->get('/portal/buyer')->assertForbidden();
    }

    public function test_the_buyer_portal_renders_expenses_and_metrics(): void
    {
        $party = $this->makeTradingParty('VAT-BUYERPORTAL-0002');
        $accountant = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Accountant', 'email' => 'accountant@buyerportal.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_ACCOUNTANT', 'taxpayer_id' => $party['taxpayer']->id, 'status' => 'ACTIVE',
        ]);
        $categoryId = $this->createCategory($party['owner']);
        $this->createAndApproveExpense($party['owner'], $accountant, $categoryId);

        $periodId = (string) Str::uuid();
        VatPeriod::create([
            'id' => $periodId, 'organisation_id' => $party['organisation']->id, 'taxpayer_id' => $party['taxpayer']->id,
            'period_code' => '2026-08', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'due_date' => '2026-09-25',
            'status' => 'OPEN', 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        VatReturnVersion::create([
            'id' => (string) Str::uuid(), 'vat_period_id' => $periodId, 'organisation_id' => $party['organisation']->id, 'taxpayer_id' => $party['taxpayer']->id,
            'version_number' => 1, 'parent_version_id' => null, 'tax_rule_set_id' => 'taxrule-na-pilot-2026-1',
            'output_tax_cents' => 0, 'input_tax_cents' => 15000, 'adjustment_cents' => 0, 'net_payable_cents' => -15000,
            'status' => 'DRAFT', 'ledger_snapshot_hash' => str_repeat('c', 64), 'generated_by' => $party['owner']->id, 'generated_at' => now(),
        ]);

        $response = $this->actingAs($party['owner'])->get('/portal/buyer');

        $response->assertOk()->assertViewIs('portal.buyer');
        $response->assertSee('Purchases, input VAT and evidence requiring action');
        $response->assertSee('EXP-BUYERPORTAL-0001');
        $response->assertSee('Unassigned'); // no supplier_party_id set
        $response->assertSee('Category TRAVEL');
        $response->assertSee('NAD 150.00'); // tax_cents column
        $response->assertSee('NAD 1,150.00'); // total_cents column
        $response->assertSee('NAD 150.00'); // Input VAT metric (input_tax_cents from the VAT return version)
        $response->assertSee('<caption class="visually-hidden">', false);
        $response->assertSee('scope="col"', false);
        $snapshot = $response->viewData('snapshot');
        $this->assertSame(115000, $snapshot['metrics']['expense_value_cents']);
        $this->assertSame(0, $snapshot['documents']['quarantined']);
    }

    public function test_the_buyer_portal_is_scoped_to_the_actors_own_organisation(): void
    {
        $partyA = $this->makeTradingParty('VAT-BUYERPORTAL-0003');
        $partyB = $this->makeTradingParty('VAT-BUYERPORTAL-0004');
        $accountantA = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Accountant A', 'email' => 'accountant-a@buyerportal.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_ACCOUNTANT', 'taxpayer_id' => $partyA['taxpayer']->id, 'status' => 'ACTIVE',
        ]);
        $categoryA = $this->createCategory($partyA['owner']);
        $this->createAndApproveExpense($partyA['owner'], $accountantA, $categoryA, ['expense_number' => 'EXP-BUYERPORTAL-SCOPE-A']);

        $response = $this->actingAs($partyB['owner'])->get('/portal/buyer');

        $response->assertOk();
        $this->assertSame([], $response->viewData('snapshot')['expenses']);
        $response->assertDontSee('EXP-BUYERPORTAL-SCOPE-A');
    }
}
