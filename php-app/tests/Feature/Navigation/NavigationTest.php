<?php

namespace Tests\Feature\Navigation;

use App\Models\LicensePlanEntitlement;
use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\OrganisationLicense;
use App\Models\OrganisationMembership;
use App\Models\OrganisationRole;
use App\Models\OrganisationRolePermission;
use App\Models\Subscription;
use App\Models\Taxpayer;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Database\Seeders\LicensePlanSeeder;
use Database\Seeders\NavigationSeeder;
use Database\Seeders\OrganisationAdministratorRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers App\Services\Navigation\NavigationService (ported from
 * lib/data/control-plane-repository.ts's getEffectiveNavigation/
 * getNavigationChildren/getNavigationItemActions/saveNavigationPreference)
 * -- Phase 12 slice 3 (portal navigation). Also exercises the
 * User::hasAppPermission/App\Support\Access\DynamicPermissions fix this
 * slice's own correctness required (see that class' doc comment): a
 * Phase-7-deferred gap, closed here.
 */
class NavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(LicensePlanSeeder::class);
        $this->seed(OrganisationAdministratorRoleSeeder::class);
        $this->seed(NavigationSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, owner: User} */
    private function makeLicensedOrganisation(string $vatNumber, array $capabilities = []): array
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
        $subscription = Subscription::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'provider' => 'LOCAL_SYNTHETIC',
            'provider_reference' => 'synthetic-'.Str::random(12), 'status' => 'ACTIVE', 'activated_at' => now()->subMonth(),
            'current_period_start' => now()->subMonth()->toDateString(), 'current_period_end' => now()->addMonths(2)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        OrganisationLicense::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'subscription_id' => $subscription->id,
            'license_plan_id' => 'plan-pilot-professional-v1', 'state' => 'ACTIVE', 'state_version' => 1,
            'effective_from' => now()->subMonth(), 'effective_to' => null, 'retention_policy' => 'NON_DESTRUCTIVE_TAX_RETENTION', 'updated_at' => now(),
        ]);
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    public function test_effective_navigation_reflects_permission_feature_and_capability_gating(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-NAV-0001', ['SELLER']);
        LicensePlanEntitlement::where('license_plan_id', 'plan-pilot-professional-v1')->where('feature_key', 'ANALYTICS')->update(['enabled' => false]);

        $response = $this->actingAs($ctx['owner'])->getJson('/api/v1/navigation/workspace');
        $response->assertStatus(200)->assertJsonPath('organisation.id', $ctx['organisation']->id);

        $workspaces = $response->json('workspaces');
        $itemKeys = collect($workspaces)->flatMap(fn ($w) => collect($w['folders'])->flatMap(fn ($f) => collect($f['items'])->pluck('key')))->all();

        // Held capability (SELLER) + enabled feature (CORE_VAT) + granted permission (commercial:read): visible.
        $this->assertContains('commercial', $itemKeys);
        $this->assertContains('invoices', $itemKeys);
        // Capability the organisation does NOT hold (BUYER): excluded even though the owner holds expenses:read.
        $this->assertNotContains('operations', $itemKeys);
        // Feature explicitly disabled on the plan (ANALYTICS): excluded even though the owner holds reports:read.
        $this->assertNotContains('reports', $itemKeys);

        // The tree groups every item for a folder under that one folder object, not one row per item.
        $salesWorkspace = collect($workspaces)->firstWhere('key', 'sales');
        $this->assertNotNull($salesWorkspace);
        $this->assertCount(1, $salesWorkspace['folders']);
        $this->assertGreaterThanOrEqual(3, count($salesWorkspace['folders'][0]['items']));
    }

    public function test_effective_navigation_hides_items_a_role_lacks_permission_for(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-NAV-0002', ['SELLER', 'BUYER']);
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Viewer', 'email' => 'viewer-nav-0002@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_VIEWER', 'taxpayer_id' => $ctx['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($viewer)->getJson('/api/v1/navigation/workspace');
        $response->assertStatus(200);
        $itemKeys = collect($response->json('workspaces'))->flatMap(fn ($w) => collect($w['folders'])->flatMap(fn ($f) => collect($f['items'])->pluck('key')))->all();

        // TAXPAYER_VIEWER holds commercial:read (dashboard visible).
        $this->assertContains('commercial', $itemKeys);
        // TAXPAYER_VIEWER holds neither exceptions:read nor security:read.
        $this->assertNotContains('reconciliation', $itemKeys);
        $this->assertNotContains('security', $itemKeys);
    }

    public function test_a_role_without_workspace_read_is_denied_every_navigation_route(): void
    {
        // No built-in role actually lacks workspace:read (every one of the
        // 22 grants it -- confirmed against Permissions::CONTROL_PLANE_
        // PERMISSIONS), so this is exercised via Gate::authorize's own
        // isActive() half instead: a suspended user is denied regardless
        // of role.
        $ctx = $this->makeLicensedOrganisation('VAT-NAV-0003');
        $ctx['owner']->update(['status' => 'SUSPENDED']);

        $this->actingAs($ctx['owner'])->getJson('/api/v1/navigation/workspace')->assertStatus(403);
    }

    public function test_navigation_children_drills_from_a_workspace_into_a_folder(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-NAV-0004', ['SELLER']);

        $workspaceLevel = $this->actingAs($ctx['owner'])->getJson('/api/v1/navigation/children?parent_type=workspace&parent_id=nav-sales');
        $workspaceLevel->assertStatus(200)->assertJsonPath('parentType', 'workspace')->assertJsonPath('workspace.key', 'sales');
        $this->assertCount(1, $workspaceLevel->json('folders'));
        $folderId = $workspaceLevel->json('folders.0.id');

        $folderLevel = $this->actingAs($ctx['owner'])->getJson("/api/v1/navigation/children?parent_type=folder&parent_id={$folderId}");
        $folderLevel->assertStatus(200)->assertJsonPath('parentType', 'folder');
        $itemKeys = collect($folderLevel->json('items'))->pluck('key')->all();
        $this->assertContains('commercial', $itemKeys);

        $this->actingAs($ctx['owner'])->getJson('/api/v1/navigation/children?parent_type=workspace&parent_id=does-not-exist')
            ->assertStatus(422)->assertJsonPath('code', 'WORKSPACE_NOT_FOUND');
        $this->actingAs($ctx['owner'])->getJson('/api/v1/navigation/children?parent_type=bogus&parent_id=nav-sales')
            ->assertStatus(422)->assertJsonPath('code', 'PARENT_TYPE_INVALID');
    }

    public function test_navigation_item_actions_reports_allowed_and_denied_reasons(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-NAV-0005', ['SELLER']);

        $allowed = $this->actingAs($ctx['owner'])->getJson('/api/v1/navigation/actions?item_key=commercial');
        $allowed->assertStatus(200)->assertJsonPath('allowed', true)->assertJsonPath('actions.0.action', 'VIEW');

        // BUYER capability the organisation does not hold.
        $denied = $this->actingAs($ctx['owner'])->getJson('/api/v1/navigation/actions?item_key=operations');
        $denied->assertStatus(200)->assertJsonPath('allowed', false);
        $this->assertNotEmpty($denied->json('deniedReasons'));

        $this->actingAs($ctx['owner'])->getJson('/api/v1/navigation/actions')
            ->assertStatus(422)->assertJsonPath('code', 'ITEM_KEY_REQUIRED');
        $this->actingAs($ctx['owner'])->getJson('/api/v1/navigation/actions?item_key=does-not-exist')
            ->assertStatus(422)->assertJsonPath('code', 'NAVIGATION_ITEM_NOT_FOUND');
    }

    public function test_saving_a_navigation_preference_upserts_the_callers_own_row(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-NAV-0006');

        $first = $this->actingAs($ctx['owner'])->postJson('/api/v1/navigation/preferences', ['preference_type' => 'sidebar_collapsed', 'value' => true]);
        $first->assertStatus(200)->assertJsonPath('preference.value', true);
        $this->assertDatabaseCount('navigation_preferences', 1);

        $second = $this->actingAs($ctx['owner'])->postJson('/api/v1/navigation/preferences', ['preference_type' => 'sidebar_collapsed', 'value' => false]);
        $second->assertStatus(200)->assertJsonPath('preference.value', false);
        // Upsert, not a second row.
        $this->assertDatabaseCount('navigation_preferences', 1);

        $this->actingAs($ctx['owner'])->postJson('/api/v1/navigation/preferences', ['preference_type' => 'BAD TYPE', 'value' => 1])
            ->assertStatus(422)->assertJsonPath('code', 'PREFERENCE_TYPE_INVALID');
        $this->actingAs($ctx['owner'])->postJson('/api/v1/navigation/preferences', ['preference_type' => 'missing_value'])
            ->assertStatus(422)->assertJsonPath('code', 'VALUE_REQUIRED');
    }

    /**
     * The load-bearing test for this slice's own DynamicPermissions fix:
     * a role that genuinely lacks a static permission gains it, and only
     * it, through an organisation-defined custom role -- proving
     * User::hasAppPermission (and therefore both Gate::authorize and
     * NavigationService's own row filter) actually consult
     * user_role_assignments now, not just the static role map.
     */
    public function test_an_organisation_defined_role_grants_a_permission_the_static_role_lacks(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-NAV-0007');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Dynamic Viewer', 'email' => 'dynamic-viewer@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_VIEWER', 'taxpayer_id' => $ctx['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        // TAXPAYER_VIEWER does not hold exceptions:read -- confirmed absent
        // from Permissions::ROLE_PERMISSIONS['TAXPAYER_VIEWER'].
        $before = $this->actingAs($viewer)->getJson('/api/v1/navigation/actions?item_key=reconciliation');
        $before->assertStatus(200)->assertJsonPath('allowed', false);

        OrganisationMembership::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'user_id' => $viewer->id,
            'role_code' => 'TAXPAYER_VIEWER', 'branch_id' => null, 'status' => 'ACTIVE', 'valid_from' => now(), 'valid_to' => null,
            'assigned_by' => $ctx['owner']->id, 'created_at' => now(),
        ]);
        $role = OrganisationRole::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'name' => 'Reconciliation Viewer',
            'description' => 'Read-only VAT reconciliation access.', 'version' => 1, 'branch_scope' => '[]',
            'approval_limit_cents' => null, 'status' => 'ACTIVE', 'created_by' => $ctx['owner']->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        OrganisationRolePermission::create([
            'id' => (string) Str::uuid(), 'organisation_role_id' => $role->id, 'permission_code' => 'exceptions:read',
            'record_scope' => 'ORGANISATION', 'effect' => 'ALLOW', 'created_at' => now(),
        ]);
        UserRoleAssignment::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'user_id' => $viewer->id,
            'employee_id' => null, 'organisation_role_id' => $role->id, 'status' => 'ACTIVE',
            'effective_from' => now(), 'effective_to' => null, 'assigned_by' => $ctx['owner']->id, 'created_at' => now(),
        ]);

        $after = $this->actingAs($viewer)->getJson('/api/v1/navigation/actions?item_key=reconciliation');
        $after->assertStatus(200)->assertJsonPath('allowed', true);

        // The grant is scoped to exceptions:read only -- a permission the
        // custom role never touched stays denied.
        $stillDenied = $this->actingAs($viewer)->getJson('/api/v1/navigation/actions?item_key=security');
        $stillDenied->assertStatus(200)->assertJsonPath('allowed', false);
    }
}
