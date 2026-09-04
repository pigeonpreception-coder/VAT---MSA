<?php

namespace Tests\Feature\Business;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for the accounting dashboard
 * (App\Http\Controllers\Business\AccountingViewController /
 * resources/views/accounting/index.blade.php) -- ported from the source's
 * own app/accounting/page.tsx, a read-only journal register + chart of
 * accounts (the source itself has no write forms on this screen; see
 * AccountingViewController's own doc comment). Reuses direct
 * ChartOfAccount/JournalEntry reads the same way
 * App\Http\Controllers\Business\AccountingController::indexAccounts/
 * indexJournals already does for the JSON API, already covered end to end
 * by tests/Feature/Business/AccountingTest.php, so this file's own job is
 * the access gate and the view's own rendering.
 */
class AccountingViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, owner: User} */
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
        foreach (['BUYER', 'SELLER'] as $capability) {
            OrganisationCapability::create([
                'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'capability' => $capability,
                'status' => 'ACTIVE', 'effective_from' => now()->subDay(), 'created_at' => now(),
            ]);
        }
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@acctview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    public function test_the_accounting_page_requires_authentication(): void
    {
        $this->get('/accounting')->assertRedirect('/login');
    }

    public function test_a_role_without_accounting_read_is_denied(): void
    {
        $seller = $this->makeOrganisation('VAT-DENY-0001');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Seller Viewer', 'email' => 'viewer@acctview.test',
            'password' => bcrypt('password'), 'role' => 'SELLER_VIEWER', 'taxpayer_id' => $seller['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($viewer)->get('/accounting')->assertForbidden();
    }

    public function test_the_accounting_page_renders_the_ledger_and_chart_of_accounts(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0001');
        ChartOfAccount::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $seller['organisation']->id, 'code' => 'CASH-001',
            'name' => 'Cash on hand', 'account_type' => 'ASSET', 'currency' => 'NAD', 'control_type' => null,
            'status' => 'ACTIVE', 'created_at' => now(),
        ]);
        JournalEntry::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $seller['organisation']->id, 'journal_number' => 'JRN-0001',
            'journal_date' => now()->toDateString(), 'description' => 'Opening balance entry', 'currency' => 'NAD',
            'status' => 'POSTED', 'source_type' => 'MANUAL', 'created_by' => $seller['owner']->id, 'created_at' => now(), 'posted_at' => now(),
        ]);

        $response = $this->actingAs($seller['owner'])->get('/accounting');

        $response->assertOk()->assertViewIs('accounting.index');
        $response->assertSee('Controlled general ledger');
        $response->assertSee('CASH-001');
        $response->assertSee('Cash on hand');
        $response->assertSee('JRN-0001');
        $response->assertSee('Opening balance entry');
        $response->assertSee('Posting interface is active.');
        $response->assertSee('<caption class="visually-hidden">', false);
        $response->assertSee('scope="col"', false);
        $response->assertViewHas('postedCount', 1);
    }

    public function test_an_empty_ledger_renders_zero_state_rows(): void
    {
        $seller = $this->makeOrganisation('VAT-SELLER-0002');

        $response = $this->actingAs($seller['owner'])->get('/accounting');

        $response->assertOk();
        $response->assertSee('No journal entries on record.');
        $response->assertSee('No accounts on record.');
    }
}
