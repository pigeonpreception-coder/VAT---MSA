<?php

namespace Tests\Feature\Portal;

use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for the Developer portal dashboard
 * (App\Http\Controllers\Portal\DeveloperPortalController /
 * resources/views/portal/developer.blade.php) -- ported from the
 * source's own app/portal/developer/page.tsx. Reuses
 * App\Services\Platform\PlatformSnapshotService::developerPortalSnapshot
 * directly (no new query of its own), so this file's own job is proving
 * the portal-access gate -- specifically its `developer:read` nuance,
 * the same pattern SuperAdminPortalTest already established for
 * `platform:read` -- and the view's own rendering.
 */
class DeveloperPortalTest extends TestCase
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

    public function test_the_developer_portal_requires_authentication(): void
    {
        $this->get('/portal/developer')->assertRedirect('/login');
    }

    public function test_a_role_not_on_the_developer_portals_list_is_denied(): void
    {
        $auditor = User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Auditor', 'email' => 'auditor@developerportal.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_AUDITOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($auditor)->get('/portal/developer')->assertForbidden();
    }

    /**
     * SELLER_ADMIN is on PortalDefinitions' own developer role list
     * (role/capability check alone would pass) but does not hold
     * developer:read -- the same fidelity gap SuperAdminPortalTest
     * confirmed for SECURITY_ANALYST/platform:read.
     */
    public function test_a_role_on_the_list_but_missing_developer_read_is_denied(): void
    {
        $tp = $this->makeTaxpayer('VAT-DEVPORTAL-0001');
        $sellerAdmin = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Seller Admin', 'email' => 'seller-admin@developerportal.test',
            'password' => bcrypt('password'), 'role' => 'SELLER_ADMIN', 'taxpayer_id' => $tp['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($sellerAdmin)->get('/portal/developer')->assertForbidden();
    }

    public function test_the_developer_portal_renders_applications_and_webhooks(): void
    {
        $tp = $this->makeTaxpayer('VAT-DEVPORTAL-0002');
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Owner', 'email' => 'owner@developerportal.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $tp['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        // api_clients/webhook_subscriptions have no write command anywhere
        // in this migration yet (confirmed by each table's own migration
        // doc comment) -- inserted directly, matching this initiative's
        // own established convention for command-less tables.
        $clientId = (string) Str::uuid();
        DB::table('api_clients')->insert([
            'id' => $clientId, 'organisation_id' => $tp['organisation']->id, 'developer_account_id' => null,
            'name' => 'ERP Sync Client', 'client_key' => 'client-'.Str::random(12), 'scopes' => json_encode(['invoices:read', 'expenses:read']),
            'credential_reference' => 'secret-ref-'.Str::random(8), 'status' => 'ACTIVE', 'rate_limit_profile' => 'STANDARD',
            'created_by' => $owner->id, 'created_at' => now(),
        ]);
        DB::table('webhook_subscriptions')->insert([
            'id' => (string) Str::uuid(), 'api_client_id' => $clientId, 'event_types' => json_encode(['InvoiceCertified']),
            'endpoint_url' => 'https://erp.example.test/webhooks', 'signing_key_reference' => 'signing-ref-'.Str::random(8),
            'status' => 'ACTIVE', 'created_at' => now(),
        ]);

        $response = $this->actingAs($owner)->get('/portal/developer');

        $response->assertOk()->assertViewIs('portal.developer');
        $response->assertSee('Applications, contracts, webhooks and conformance posture');
        $response->assertSee('ERP Sync Client');
        $response->assertSee('STANDARD');
        $response->assertSee('<caption class="visually-hidden">', false);
        $response->assertSee('scope="col"', false);

        $snapshot = $response->viewData('snapshot');
        $this->assertSame(1, count($snapshot['clients']));
        $this->assertSame(1, collect($snapshot['clients'])->where('status', 'ACTIVE')->count());
        $this->assertSame(1, count($snapshot['webhooks']));
    }

    public function test_an_unlinked_developer_partner_sees_the_empty_application_registry(): void
    {
        $partner = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Developer Partner', 'email' => 'partner@developerportal.test',
            'password' => bcrypt('password'), 'role' => 'DEVELOPER_PARTNER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($partner)->get('/portal/developer');

        $response->assertOk();
        $response->assertSee('No applications in scope');
        $this->assertSame([], $response->viewData('snapshot')['clients']);
    }
}
