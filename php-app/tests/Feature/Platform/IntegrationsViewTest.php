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
 * Covers the real Blade UI for the integration registry and service
 * component posture (App\Http\Controllers\Platform\IntegrationsViewController /
 * resources/views/integrations/index.blade.php) -- ported from the
 * source's own app/integrations/page.tsx. Gap-finding pass (2026-09-23):
 * this page had no Laravel Blade equivalent at all -- only the JSON API
 * surface (PlatformSnapshotController, IntegrationConnectionController)
 * existed. Purely read-only, reusing PlatformSnapshotService directly, so
 * this file's own job is the access gate, the role-based technical/scoped
 * snapshot dispatch, and the view's own rendering.
 */
class IntegrationsViewTest extends TestCase
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
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@integrationsview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    public function test_the_integrations_page_requires_authentication(): void
    {
        $this->get('/integrations')->assertRedirect('/login');
    }

    public function test_a_role_without_integrations_read_is_denied(): void
    {
        $org = $this->makeOrganisation('VAT-INTEG-DENY');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Viewer', 'email' => 'viewer@integrationsview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_VIEWER', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($viewer)->get('/integrations')->assertForbidden();
    }

    public function test_the_integrations_page_renders_its_metric_tiles_and_registers_for_a_scoped_actor(): void
    {
        $org = $this->makeOrganisation('VAT-INTEG-0001');
        $connectionId = (string) Str::uuid();
        DB::table('integration_connections')->insert([
            'id' => $connectionId, 'organisation_id' => null, 'provider_key' => 'ITAS', 'category' => 'GOVERNMENT',
            'display_name' => 'ITAS statutory integration', 'capabilities' => 'VERIFY,SUBMIT', 'configuration_status' => 'CONFIGURED',
            'operational_status' => 'OPERATIONAL', 'data_classification' => 'CONFIDENTIAL', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('service_components')->insert([
            'id' => (string) Str::uuid(), 'component_key' => 'WEB_APP', 'display_name' => 'VAT-MSA web application',
            'component_type' => 'APPLICATION', 'criticality' => 'HIGH', 'configuration_status' => 'CONFIGURED',
            'operational_status' => 'OPERATIONAL', 'dependency_summary' => 'Laravel runtime', 'status_detail' => 'Healthy.',
        ]);
        DB::table('sync_jobs')->insert([
            'id' => (string) Str::uuid(), 'integration_connection_id' => $connectionId, 'organisation_id' => $org['organisation']->id,
            'job_type' => 'BANK_IMPORT', 'direction' => 'INBOUND', 'status' => 'BLOCKED',
            'requested_by' => $org['owner']->id, 'requested_at' => now(),
        ]);
        DB::table('outbox_events')->insert([
            'id' => (string) Str::uuid(), 'aggregate_type' => 'TEST', 'aggregate_id' => (string) Str::uuid(),
            'event_type' => 'TEST_EVENT', 'event_version' => 1, 'partition_key' => 'test', 'payload' => '{}',
            'status' => 'PENDING', 'occurred_at' => now(), 'available_at' => now(),
        ]);

        $response = $this->actingAs($org['owner'])->get('/integrations');

        $response->assertOk()->assertViewIs('integrations.index');
        $response->assertSee('Integration contracts and operational health');
        $response->assertSeeInOrder(['Connections', '1']);
        $response->assertSeeInOrder(['Configured', '1']);
        $response->assertSeeInOrder(['Blocked jobs', '1']);
        $response->assertSeeInOrder(['Outbox pending', '1']);
        $response->assertSee('ITAS statutory integration');
        $response->assertSee('VAT-MSA web application');
    }

    public function test_a_technical_admin_sees_the_unscoped_technical_snapshot(): void
    {
        $admin = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Super Admin', 'email' => 'super@integrationsview.test',
            'password' => bcrypt('password'), 'role' => 'SUPER_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
        DB::table('integration_connections')->insert([
            'id' => (string) Str::uuid(), 'organisation_id' => null, 'provider_key' => 'ITAS', 'category' => 'GOVERNMENT',
            'display_name' => 'ITAS statutory integration', 'capabilities' => 'VERIFY,SUBMIT', 'configuration_status' => 'CONFIGURED',
            'operational_status' => 'OPERATIONAL', 'data_classification' => 'CONFIDENTIAL', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/integrations');

        $response->assertOk();
        $response->assertSee('ITAS statutory integration');
    }

    public function test_the_integrations_page_renders_cleanly_with_no_data(): void
    {
        $org = $this->makeOrganisation('VAT-INTEG-0002');

        $response = $this->actingAs($org['owner'])->get('/integrations');

        $response->assertOk();
        $response->assertSee('No integration connections are registered.');
        $response->assertSee('No service components are registered.');
    }
}
