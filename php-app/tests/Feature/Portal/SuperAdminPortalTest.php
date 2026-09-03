<?php

namespace Tests\Feature\Portal;

use App\Models\Taxpayer;
use App\Models\Organisation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for the Super Administration portal dashboard
 * (App\Http\Controllers\Portal\SuperAdminPortalController /
 * resources/views/portal/super-admin.blade.php) -- ported from the
 * source's own app/portal/super-admin/page.tsx. Reuses
 * App\Services\Platform\PlatformSnapshotService::getTechnicalSnapshot
 * directly (no new query of its own), so this file's own job is proving
 * the portal-access gate -- specifically its `platform:read` nuance, see
 * the controller's own doc comment -- and the view's own rendering.
 */
class SuperAdminPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function pilotAdmin(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Pilot Admin', 'email' => 'pilot@superadminportal.test',
            'password' => bcrypt('password'), 'role' => 'PILOT_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    public function test_the_super_admin_portal_requires_authentication(): void
    {
        $this->get('/portal/super-admin')->assertRedirect('/login');
    }

    public function test_a_role_not_on_the_super_admin_portals_list_is_denied(): void
    {
        $tp = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => 'VAT-SUPERADMINPORTAL-0001', 'tin' => 'TIN-SUPERADMINPORTAL-0001',
            'legal_name' => 'Trading Co', 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => 'super-admin-portal@test.test',
        ]);
        Organisation::create(['id' => (string) Str::uuid(), 'taxpayer_id' => $tp->id, 'legal_name' => $tp->legal_name, 'status' => 'ACTIVE']);
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Owner', 'email' => 'owner@superadminportal.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $tp->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($owner)->get('/portal/super-admin')->assertForbidden();
    }

    /**
     * SECURITY_ANALYST is on PortalDefinitions' own super-admin role list
     * (role/capability check alone would pass) but does not hold
     * platform:read -- the exact fidelity gap the controller's own doc
     * comment documents. Confirms the gate is genuinely platform:read,
     * not the dashboard:read every sibling portal happens to use.
     */
    public function test_a_role_on_the_list_but_missing_platform_read_is_denied(): void
    {
        $analyst = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Security Analyst', 'email' => 'analyst@superadminportal.test',
            'password' => bcrypt('password'), 'role' => 'SECURITY_ANALYST', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($analyst)->get('/portal/super-admin')->assertForbidden();
    }

    public function test_the_super_admin_portal_renders_component_integration_and_event_metrics(): void
    {
        $admin = $this->pilotAdmin();

        // A real command for a real PENDING outbox row -- every command in
        // this migration writes one via CommandLedger::outbox.
        $this->actingAs($admin)->postJson('/api/v1/obligations', [
            'schema_version' => '1.0.0', 'taxpayer_id' => $this->seedTaxpayer()->id, 'obligation_type' => 'VAT_RETURN',
            'period_code' => '2026-07', 'due_date' => '2026-08-25', 'amount_cents' => 100000, 'currency' => 'NAD',
        ], ['Idempotency-Key' => 'test-idem-superadminportal-obligation-0001'])->assertStatus(201);

        // service_components/integration_connections/security_events have
        // no write command anywhere in this migration yet (confirmed by
        // each table's own migration doc comment) -- inserted directly,
        // matching ComplianceSnapshotTest's own established convention for
        // command-less tables.
        DB::table('service_components')->insert([
            'id' => (string) Str::uuid(), 'component_key' => 'invoice-certification', 'display_name' => 'Invoice Certification Service',
            'component_type' => 'CORE_SERVICE', 'criticality' => 'CRITICAL', 'configuration_status' => 'CONFIGURED',
            'operational_status' => 'HEALTHY', 'dependency_summary' => 'MySQL primary, no external dependency.', 'status_detail' => 'Nominal.',
        ]);
        DB::table('integration_connections')->insert([
            'id' => (string) Str::uuid(), 'organisation_id' => null, 'provider_key' => 'BANK_FEED', 'category' => 'PAYMENTS',
            'display_name' => 'Bank Feed Connector', 'capabilities' => json_encode(['STATEMENT_IMPORT']),
            'configuration_status' => 'NOT_CONFIGURED', 'operational_status' => 'DISABLED', 'data_classification' => 'FINANCIAL',
        ]);
        DB::table('security_events')->insert([
            'id' => (string) Str::uuid(), 'event_type' => 'MULTIPLE_FAILED_LOGINS', 'severity' => 'CRITICAL', 'actor_id' => null,
            'source_token' => 'test-source', 'correlation_id' => (string) Str::uuid(), 'action' => 'LOGIN', 'outcome' => 'BLOCKED',
            'details' => json_encode(['attempts' => 5]), 'occurred_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/portal/super-admin');

        $response->assertOk()->assertViewIs('portal.super-admin');
        $response->assertSee('Technical health, security and integration configuration');
        $response->assertSee('Invoice Certification Service');
        // The integrations catalogue itself is never rendered as a table on
        // this page (only a "Disabled integrations" count) -- matching the
        // source's own page exactly, verified below via the raw snapshot.
        $response->assertSee('<caption class="visually-hidden">', false);
        $response->assertSee('scope="col"', false);

        $snapshot = $response->viewData('snapshot');
        $this->assertSame(1, count($snapshot['components']));
        $this->assertSame(1, collect($snapshot['integrations'])->where('operational_status', 'DISABLED')->count());
        $this->assertSame(1, collect($snapshot['securityEvents'])->firstWhere('severity', 'CRITICAL')['count']);
        $this->assertGreaterThanOrEqual(1, collect($snapshot['outbox'])->firstWhere('status', 'PENDING')['count'] ?? 0);
    }

    private function seedTaxpayer(): Taxpayer
    {
        $vatNumber = 'VAT-SUPERADMINPORTAL-'.Str::random(6);
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => strtolower($vatNumber).'@test.test',
        ]);
        Organisation::create(['id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE']);

        return $taxpayer;
    }
}
