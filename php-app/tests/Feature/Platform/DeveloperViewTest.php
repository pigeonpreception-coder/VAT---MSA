<?php

namespace Tests\Feature\Platform;

use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for the API client registry and webhook
 * subscriptions (App\Http\Controllers\Platform\DeveloperViewController /
 * resources/views/developer/index.blade.php) -- ported from the source's
 * own app/developer/page.tsx. Gap-finding pass (2026-09-23): this page
 * had no Laravel Blade equivalent at all, distinct from
 * App\Http\Controllers\Portal\DeveloperPortalController (`/portal/developer`,
 * the Developer Portal switchboard destination with its own write
 * actions) -- confirmed by reading both pages in full and by a
 * repo-wide search finding no `/developer` GET route. Purely read-only,
 * reusing PlatformSnapshotService::getSnapshot() directly, so this
 * file's own job is the access gate and the view's own rendering.
 */
class DeveloperViewTest extends TestCase
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

    public function test_the_developer_page_requires_authentication(): void
    {
        $this->get('/developer')->assertRedirect('/login');
    }

    public function test_a_role_without_developer_read_is_denied(): void
    {
        $tp = $this->makeTaxpayer('VAT-DEVVIEW-DENY');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Viewer', 'email' => 'viewer@devview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_VIEWER', 'taxpayer_id' => $tp['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($viewer)->get('/developer')->assertForbidden();
    }

    public function test_the_developer_page_renders_its_metric_tiles_and_registers(): void
    {
        $tp = $this->makeTaxpayer('VAT-DEVVIEW-0001');
        $admin = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Admin', 'email' => 'admin@devview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_ADMIN', 'taxpayer_id' => $tp['taxpayer']->id, 'status' => 'ACTIVE',
        ]);
        $clientId = (string) Str::uuid();
        DB::table('api_clients')->insert([
            'id' => $clientId, 'organisation_id' => $tp['organisation']->id, 'name' => 'Test Integration Client',
            'client_key' => 'client-test-001', 'scopes' => 'invoices:read', 'credential_reference' => 'secretmanager://client-test-001',
            'status' => 'ACTIVE', 'rate_limit_profile' => 'STANDARD', 'created_by' => $admin->id, 'created_at' => now(),
        ]);
        DB::table('webhook_subscriptions')->insert([
            'id' => (string) Str::uuid(), 'api_client_id' => $clientId, 'event_types' => 'INVOICE_CERTIFIED',
            'endpoint_url' => 'https://example.test/webhooks/vat-msa', 'signing_key_reference' => 'secretmanager://signing-key-001',
            'status' => 'ACTIVE', 'created_at' => now(),
        ]);
        DB::table('outbox_events')->insert([
            'id' => (string) Str::uuid(), 'aggregate_type' => 'TEST', 'aggregate_id' => (string) Str::uuid(),
            'event_type' => 'TEST_EVENT', 'event_version' => 1, 'partition_key' => 'test', 'payload' => '{}',
            'status' => 'PENDING', 'occurred_at' => now(), 'available_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/developer');

        $response->assertOk()->assertViewIs('developer.index');
        $response->assertSeeInOrder(['API clients', '1']);
        $response->assertSeeInOrder(['Active clients', '1']);
        $response->assertSeeInOrder(['Webhooks', '1']);
        $response->assertSeeInOrder(['Outbox pending', '1']);
        $response->assertSee('Test Integration Client');
        $response->assertSee('https://example.test/webhooks/vat-msa');
    }

    public function test_the_developer_page_renders_cleanly_with_no_data(): void
    {
        $tp = $this->makeTaxpayer('VAT-DEVVIEW-0002');
        $admin = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Admin', 'email' => 'admin2@devview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_ADMIN', 'taxpayer_id' => $tp['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($admin)->get('/developer');

        $response->assertOk();
        $response->assertSee('No API clients.');
        $response->assertSee('No webhook subscriptions.');
    }
}
