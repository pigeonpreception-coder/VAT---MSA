<?php

namespace Tests\Feature\Navigation;

use App\Models\Invoice;
use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for workspace search
 * (App\Http\Controllers\Navigation\WorkspaceSearchViewController /
 * resources/views/workspace-search/index.blade.php) -- ported from the
 * source's own app/workspace-search/page.tsx. Gap-finding pass
 * (2026-09-23, a doc-comment/route-inventory sweep after the metric-tile
 * angle ran dry): the underlying search itself was already fully ported
 * (App\Services\Navigation\NavigationService::searchWorkspace, already
 * exercised via the JSON GET /search route), but no Blade page anywhere
 * reached it -- confirmed by a repo-wide search finding no
 * `/workspace-search` route and no `resources/views/workspace-search`
 * directory. Purely read-only, reusing the same service method the JSON
 * route already calls, so this file's own job is the access gate, the
 * short-query guard and the view's own rendering.
 */
class WorkspaceSearchViewTest extends TestCase
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

    public function test_the_workspace_search_page_requires_authentication(): void
    {
        $this->get('/workspace-search')->assertRedirect('/login');
    }

    public function test_a_role_without_search_read_is_denied(): void
    {
        $tp = $this->makeTaxpayer('VAT-WSSEARCH-DENY');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Seller Viewer', 'email' => 'viewer@wssearch.test',
            'password' => bcrypt('password'), 'role' => 'SELLER_VIEWER', 'taxpayer_id' => $tp['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($viewer)->get('/workspace-search')->assertForbidden();
    }

    public function test_a_short_query_prompts_for_more_characters_without_searching(): void
    {
        $tp = $this->makeTaxpayer('VAT-WSSEARCH-0001');
        $staff = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Staff', 'email' => 'staff@wssearch.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_STAFF', 'taxpayer_id' => $tp['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($staff)->get('/workspace-search?q=a');

        $response->assertOk()->assertViewIs('workspace-search.index');
        $response->assertSee('Enter at least two characters.');
    }

    public function test_a_matching_query_renders_real_authorised_results(): void
    {
        $tp = $this->makeTaxpayer('VAT-WSSEARCH-0002');
        $staff = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Staff', 'email' => 'staff2@wssearch.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_STAFF', 'taxpayer_id' => $tp['taxpayer']->id, 'status' => 'ACTIVE',
        ]);
        Invoice::create([
            'id' => (string) Str::uuid(), 'invoice_number' => 'INV-WSSEARCH-9001', 'document_type' => 'TAX_INVOICE',
            'source_system' => 'test', 'source_document_id' => 'doc-wssearch-9001',
            'supplier_taxpayer_id' => $tp['taxpayer']->id, 'supplier_name' => $tp['taxpayer']->legal_name,
            'supplier_vat_number' => $tp['taxpayer']->vat_number, 'customer_taxpayer_id' => $tp['taxpayer']->id,
            'customer_name' => 'Search Test Customer', 'issue_date' => now()->toDateString(),
            'currency' => 'NAD', 'line_net_cents' => 10000, 'tax_cents' => 1500, 'total_cents' => 11500, 'status' => 'CERTIFIED',
            'risk_level' => 'LOW', 'payload_hash' => str_repeat('a', 64), 'transaction_id' => (string) Str::uuid(),
            'certificate_id' => (string) Str::uuid(), 'verification_token' => 'vfy_'.Str::random(32),
        ]);

        $response = $this->actingAs($staff)->get('/workspace-search?q=WSSEARCH-9001');

        $response->assertOk();
        $response->assertSee('INV-WSSEARCH-9001');
        $response->assertSee('Invoice');
    }

    public function test_no_matches_renders_a_friendly_empty_state(): void
    {
        $tp = $this->makeTaxpayer('VAT-WSSEARCH-0003');
        $staff = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Staff', 'email' => 'staff3@wssearch.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_STAFF', 'taxpayer_id' => $tp['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($staff)->get('/workspace-search?q=nonexistent-zzz');

        $response->assertOk();
        $response->assertSee('No authorised matches.');
    }
}
