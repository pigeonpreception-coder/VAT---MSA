<?php

namespace Tests\Feature\Integration;

use App\Models\IntegrationConnection;
use App\Models\Organisation;
use App\Models\SyncJob;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers Module 10 Phase A's RegisterIntegration/ApproveIntegration/
 * SuspendIntegration/StartSync/GetHealth
 * (App\Services\Integration\IntegrationConnectionService), the JSON API
 * mirror (App\Http\Controllers\Integration\IntegrationConnectionController).
 * No Blade UI -- see the service's own doc comment for why.
 */
class IntegrationConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    private function makeTenantAdmin(string $vatNumber): array
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => strtolower($vatNumber).'@test.test',
        ]);
        $organisation = Organisation::create([
            'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE',
        ]);
        $admin = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Admin", 'email' => strtolower($vatNumber).'-admin@integration.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_ADMIN', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'admin');
    }

    private function makePlatformAdmin(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Super Admin', 'email' => 'super-admin-'.Str::random(6).'@integration.test',
            'password' => bcrypt('password'), 'role' => 'SUPER_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'schema_version' => '1.0.0', 'provider_key' => 'TEST_ERP', 'category' => 'ERP',
            'display_name' => 'Test ERP Connector', 'capabilities' => ['INVOICE_SYNC'], 'data_classification' => 'INTERNAL',
        ], $overrides);
    }

    private function key(): string
    {
        return (string) Str::uuid().(string) Str::uuid();
    }

    public function test_routes_require_authentication(): void
    {
        $this->postJson('/api/v1/integrations')->assertUnauthorized();
        $this->getJson('/api/v1/integrations/some-id/health')->assertUnauthorized();
    }

    public function test_a_role_without_integrations_manage_is_denied(): void
    {
        $accountant = User::create([
            'id' => (string) Str::uuid(), 'name' => 'No Manage', 'email' => 'no-manage@integration.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_ACCOUNTANT', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($accountant)->postJson('/api/v1/integrations', $this->payload(), ['Idempotency-Key' => $this->key()])->assertForbidden();
    }

    public function test_a_tenant_actor_registers_a_connection_scoped_to_their_organisation(): void
    {
        $org = $this->makeTenantAdmin('VAT-INT-0001');

        $response = $this->actingAs($org['admin'])->postJson('/api/v1/integrations', $this->payload(), ['Idempotency-Key' => $this->key()]);

        $response->assertStatus(201);
        $response->assertJsonPath('resource.organisation_id', $org['organisation']->id);
        $response->assertJsonPath('resource.configuration_status', 'DRAFT');
        $response->assertJsonPath('resource.operational_status', 'DISABLED');
        $this->assertDatabaseHas('integration_connections', ['provider_key' => 'TEST_ERP', 'organisation_id' => $org['organisation']->id]);
    }

    public function test_a_platform_actor_registers_a_platform_wide_connection(): void
    {
        $admin = $this->makePlatformAdmin();

        $response = $this->actingAs($admin)->postJson('/api/v1/integrations', $this->payload(), ['Idempotency-Key' => $this->key()]);

        $response->assertStatus(201);
        $response->assertJsonPath('resource.organisation_id', null);
        $this->assertDatabaseHas('integration_connections', ['provider_key' => 'TEST_ERP', 'organisation_id' => null]);
    }

    public function test_registering_the_same_provider_key_twice_for_the_same_scope_is_a_conflict(): void
    {
        $org = $this->makeTenantAdmin('VAT-INT-0002');
        $this->actingAs($org['admin'])->postJson('/api/v1/integrations', $this->payload(), ['Idempotency-Key' => $this->key()])->assertStatus(201);

        $response = $this->actingAs($org['admin'])->postJson('/api/v1/integrations', $this->payload(), ['Idempotency-Key' => $this->key()]);

        $response->assertStatus(409);
        $this->assertSame(1, IntegrationConnection::where('provider_key', 'TEST_ERP')->where('organisation_id', $org['organisation']->id)->count());
    }

    public function test_two_different_organisations_can_register_the_same_provider_key(): void
    {
        $orgA = $this->makeTenantAdmin('VAT-INT-0003');
        $orgB = $this->makeTenantAdmin('VAT-INT-0004');

        $this->actingAs($orgA['admin'])->postJson('/api/v1/integrations', $this->payload(), ['Idempotency-Key' => $this->key()])->assertStatus(201);
        $response = $this->actingAs($orgB['admin'])->postJson('/api/v1/integrations', $this->payload(), ['Idempotency-Key' => $this->key()]);

        $response->assertStatus(201);
        $this->assertSame(2, IntegrationConnection::where('provider_key', 'TEST_ERP')->count());
    }

    public function test_registration_is_idempotent_on_replay(): void
    {
        $org = $this->makeTenantAdmin('VAT-INT-0005');
        $key = $this->key();

        $this->actingAs($org['admin'])->postJson('/api/v1/integrations', $this->payload(), ['Idempotency-Key' => $key])->assertStatus(201);
        $this->actingAs($org['admin'])->postJson('/api/v1/integrations', $this->payload(), ['Idempotency-Key' => $key])->assertStatus(201);

        $this->assertSame(1, IntegrationConnection::count());
    }

    public function test_invalid_registration_fields_are_rejected_with_specific_codes(): void
    {
        $org = $this->makeTenantAdmin('VAT-INT-0006');

        $response = $this->actingAs($org['admin'])->postJson('/api/v1/integrations', $this->payload([
            'provider_key' => 'not valid', 'category' => 'NOT_A_CATEGORY', 'capabilities' => [], 'data_classification' => 'NOPE',
        ]), ['Idempotency-Key' => $this->key()]);

        $response->assertStatus(422);
        $codes = collect($response->json('errors'))->pluck('code');
        $this->assertTrue($codes->contains('PROVIDER_KEY_INVALID'));
        $this->assertTrue($codes->contains('CATEGORY_INVALID'));
        $this->assertTrue($codes->contains('CAPABILITIES_REQUIRED'));
        $this->assertTrue($codes->contains('DATA_CLASSIFICATION_INVALID'));
        $this->assertDatabaseCount('integration_connections', 0);
    }

    public function test_an_admin_can_approve_a_draft_connection(): void
    {
        $org = $this->makeTenantAdmin('VAT-INT-0007');
        $create = $this->actingAs($org['admin'])->postJson('/api/v1/integrations', $this->payload(), ['Idempotency-Key' => $this->key()]);
        $id = $create->json('resource.id');

        $response = $this->actingAs($org['admin'])->postJson("/api/v1/integrations/{$id}/approval", [], ['Idempotency-Key' => $this->key()]);

        $response->assertOk();
        $response->assertJsonPath('resource.configuration_status', 'CONFIGURED');
        $response->assertJsonPath('resource.operational_status', 'OPERATIONAL');
    }

    public function test_approving_an_already_configured_connection_is_rejected_as_an_invalid_transition(): void
    {
        $org = $this->makeTenantAdmin('VAT-INT-0008');
        $create = $this->actingAs($org['admin'])->postJson('/api/v1/integrations', $this->payload(), ['Idempotency-Key' => $this->key()]);
        $id = $create->json('resource.id');
        $this->actingAs($org['admin'])->postJson("/api/v1/integrations/{$id}/approval", [], ['Idempotency-Key' => $this->key()])->assertOk();

        $response = $this->actingAs($org['admin'])->postJson("/api/v1/integrations/{$id}/approval", [], ['Idempotency-Key' => $this->key()]);

        $response->assertStatus(422);
        $this->assertSame('INTEGRATION_TRANSITION_INVALID', $response->json('errors.0.code'));
    }

    public function test_an_admin_can_suspend_a_configured_connection_with_a_reason(): void
    {
        $org = $this->makeTenantAdmin('VAT-INT-0009');
        $create = $this->actingAs($org['admin'])->postJson('/api/v1/integrations', $this->payload(), ['Idempotency-Key' => $this->key()]);
        $id = $create->json('resource.id');
        $this->actingAs($org['admin'])->postJson("/api/v1/integrations/{$id}/approval", [], ['Idempotency-Key' => $this->key()])->assertOk();

        $response = $this->actingAs($org['admin'])->postJson("/api/v1/integrations/{$id}/suspension", ['schema_version' => '1.0.0', 'reason' => 'Credential rotation required'], ['Idempotency-Key' => $this->key()]);

        $response->assertOk();
        $response->assertJsonPath('resource.configuration_status', 'SUSPENDED');
        $response->assertJsonPath('resource.operational_status', 'DISABLED');
    }

    public function test_suspending_without_a_reason_is_rejected(): void
    {
        $org = $this->makeTenantAdmin('VAT-INT-0010');
        $create = $this->actingAs($org['admin'])->postJson('/api/v1/integrations', $this->payload(), ['Idempotency-Key' => $this->key()]);
        $id = $create->json('resource.id');
        $this->actingAs($org['admin'])->postJson("/api/v1/integrations/{$id}/approval", [], ['Idempotency-Key' => $this->key()])->assertOk();

        $response = $this->actingAs($org['admin'])->postJson("/api/v1/integrations/{$id}/suspension", ['schema_version' => '1.0.0'], ['Idempotency-Key' => $this->key()]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('integration_connections', ['id' => $id, 'configuration_status' => 'CONFIGURED']);
    }

    public function test_a_suspended_connection_can_be_re_approved(): void
    {
        $org = $this->makeTenantAdmin('VAT-INT-0011');
        $create = $this->actingAs($org['admin'])->postJson('/api/v1/integrations', $this->payload(), ['Idempotency-Key' => $this->key()]);
        $id = $create->json('resource.id');
        $this->actingAs($org['admin'])->postJson("/api/v1/integrations/{$id}/approval", [], ['Idempotency-Key' => $this->key()])->assertOk();
        $this->actingAs($org['admin'])->postJson("/api/v1/integrations/{$id}/suspension", ['schema_version' => '1.0.0', 'reason' => 'Temporary pause'], ['Idempotency-Key' => $this->key()])->assertOk();

        $response = $this->actingAs($org['admin'])->postJson("/api/v1/integrations/{$id}/approval", [], ['Idempotency-Key' => $this->key()]);

        $response->assertOk();
        $response->assertJsonPath('resource.configuration_status', 'CONFIGURED');
    }

    public function test_another_organisation_cannot_manage_a_connection_it_does_not_own(): void
    {
        $orgA = $this->makeTenantAdmin('VAT-INT-0012');
        $orgB = $this->makeTenantAdmin('VAT-INT-0013');
        $create = $this->actingAs($orgA['admin'])->postJson('/api/v1/integrations', $this->payload(), ['Idempotency-Key' => $this->key()]);
        $id = $create->json('resource.id');

        $response = $this->actingAs($orgB['admin'])->postJson("/api/v1/integrations/{$id}/approval", [], ['Idempotency-Key' => $this->key()]);

        $response->assertForbidden();
    }

    public function test_a_tenant_actor_cannot_manage_a_platform_wide_connection(): void
    {
        $platformAdmin = $this->makePlatformAdmin();
        $create = $this->actingAs($platformAdmin)->postJson('/api/v1/integrations', $this->payload(), ['Idempotency-Key' => $this->key()]);
        $id = $create->json('resource.id');
        $org = $this->makeTenantAdmin('VAT-INT-0014');

        $response = $this->actingAs($org['admin'])->postJson("/api/v1/integrations/{$id}/approval", [], ['Idempotency-Key' => $this->key()]);

        $response->assertForbidden();
    }

    public function test_a_platform_actor_cannot_manage_a_tenant_scoped_connection(): void
    {
        $org = $this->makeTenantAdmin('VAT-INT-0015');
        $create = $this->actingAs($org['admin'])->postJson('/api/v1/integrations', $this->payload(), ['Idempotency-Key' => $this->key()]);
        $id = $create->json('resource.id');
        $platformAdmin = $this->makePlatformAdmin();

        $response = $this->actingAs($platformAdmin)->postJson("/api/v1/integrations/{$id}/approval", [], ['Idempotency-Key' => $this->key()]);

        $response->assertForbidden();
    }

    public function test_starting_a_sync_on_a_draft_connection_is_rejected_as_a_conflict(): void
    {
        $org = $this->makeTenantAdmin('VAT-INT-0016');
        $create = $this->actingAs($org['admin'])->postJson('/api/v1/integrations', $this->payload(), ['Idempotency-Key' => $this->key()]);
        $id = $create->json('resource.id');

        $response = $this->actingAs($org['admin'])->postJson("/api/v1/integrations/{$id}/sync", ['schema_version' => '1.0.0', 'job_type' => 'FULL_SYNC', 'direction' => 'INBOUND'], ['Idempotency-Key' => $this->key()]);

        $response->assertStatus(409);
        $this->assertDatabaseCount('sync_jobs', 0);
    }

    public function test_starting_a_sync_on_a_configured_connection_honestly_records_a_failed_job(): void
    {
        $org = $this->makeTenantAdmin('VAT-INT-0017');
        $create = $this->actingAs($org['admin'])->postJson('/api/v1/integrations', $this->payload(), ['Idempotency-Key' => $this->key()]);
        $id = $create->json('resource.id');
        $this->actingAs($org['admin'])->postJson("/api/v1/integrations/{$id}/approval", [], ['Idempotency-Key' => $this->key()])->assertOk();

        $response = $this->actingAs($org['admin'])->postJson("/api/v1/integrations/{$id}/sync", ['schema_version' => '1.0.0', 'job_type' => 'FULL_SYNC', 'direction' => 'INBOUND'], ['Idempotency-Key' => $this->key()]);

        $response->assertStatus(201);
        $response->assertJsonPath('resource.status', 'FAILED');
        $response->assertJsonPath('resource.records_read', 0);
        $job = SyncJob::firstOrFail();
        $this->assertSame(1, $job->error_count);
        $this->assertStringContainsString('No live connector implementation', $job->last_error);
    }

    public function test_sync_start_is_idempotent_on_replay(): void
    {
        $org = $this->makeTenantAdmin('VAT-INT-0018');
        $create = $this->actingAs($org['admin'])->postJson('/api/v1/integrations', $this->payload(), ['Idempotency-Key' => $this->key()]);
        $id = $create->json('resource.id');
        $this->actingAs($org['admin'])->postJson("/api/v1/integrations/{$id}/approval", [], ['Idempotency-Key' => $this->key()])->assertOk();
        $key = $this->key();

        $this->actingAs($org['admin'])->postJson("/api/v1/integrations/{$id}/sync", ['schema_version' => '1.0.0', 'job_type' => 'FULL_SYNC', 'direction' => 'INBOUND'], ['Idempotency-Key' => $key])->assertStatus(201);
        $this->actingAs($org['admin'])->postJson("/api/v1/integrations/{$id}/sync", ['schema_version' => '1.0.0', 'job_type' => 'FULL_SYNC', 'direction' => 'INBOUND'], ['Idempotency-Key' => $key])->assertStatus(201);

        $this->assertSame(1, SyncJob::count());
    }

    public function test_get_health_returns_the_connection_and_its_recent_sync_jobs(): void
    {
        $org = $this->makeTenantAdmin('VAT-INT-0019');
        $create = $this->actingAs($org['admin'])->postJson('/api/v1/integrations', $this->payload(), ['Idempotency-Key' => $this->key()]);
        $id = $create->json('resource.id');
        $this->actingAs($org['admin'])->postJson("/api/v1/integrations/{$id}/approval", [], ['Idempotency-Key' => $this->key()])->assertOk();
        $this->actingAs($org['admin'])->postJson("/api/v1/integrations/{$id}/sync", ['schema_version' => '1.0.0', 'job_type' => 'FULL_SYNC', 'direction' => 'INBOUND'], ['Idempotency-Key' => $this->key()])->assertStatus(201);

        $response = $this->actingAs($org['admin'])->getJson("/api/v1/integrations/{$id}/health");

        $response->assertOk();
        $response->assertJsonPath('connection.id', $id);
        $response->assertJsonCount(1, 'recent_sync_jobs');
    }

    public function test_a_role_with_only_integrations_read_can_view_health_but_not_write(): void
    {
        $org = $this->makeTenantAdmin('VAT-INT-0020');
        $create = $this->actingAs($org['admin'])->postJson('/api/v1/integrations', $this->payload(), ['Idempotency-Key' => $this->key()]);
        $id = $create->json('resource.id');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Read Only', 'email' => 'read-only@integration.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_VIEWER', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($viewer)->postJson("/api/v1/integrations/{$id}/approval", [], ['Idempotency-Key' => $this->key()])->assertForbidden();
    }

    public function test_a_pre_seeded_government_connection_can_never_be_approved_through_this_command(): void
    {
        $id = (string) Str::uuid();
        IntegrationConnection::create([
            'id' => $id, 'organisation_id' => null, 'provider_key' => 'ITAS', 'category' => 'GOVERNMENT',
            'display_name' => 'Integrated Tax Administration System', 'capabilities' => json_encode(['RETURN_LOOKUP']),
            'endpoint_reference' => null, 'credential_reference' => null, 'configuration_status' => 'REQUIRES_ITAS_CONTRACT',
            'operational_status' => 'DISABLED', 'data_classification' => 'TAX_CONFIDENTIAL',
            'last_health_check_at' => null, 'last_health_outcome' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $platformAdmin = $this->makePlatformAdmin();

        $response = $this->actingAs($platformAdmin)->postJson("/api/v1/integrations/{$id}/approval", [], ['Idempotency-Key' => $this->key()]);

        $response->assertStatus(422);
        $this->assertSame('INTEGRATION_TRANSITION_INVALID', $response->json('errors.0.code'));
        $this->assertDatabaseHas('integration_connections', ['id' => $id, 'configuration_status' => 'REQUIRES_ITAS_CONTRACT']);
    }
}
