<?php

namespace Tests\Feature\Navigation;

use App\Models\LicensePlanEntitlement;
use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\OrganisationLicense;
use App\Models\Subscription;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\LicensePlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real rendered sidebar in resources/views/layouts/app.blade.php
 * -- a *different*, older navigation surface than App\Services\Navigation\
 * NavigationService's own JSON-driven workspace tree (see NavigationTest).
 *
 * User's own explicit request (2026-09-15): every sidebar link and group
 * header is now shown to every authenticated user unconditionally,
 * regardless of the permission its destination requires -- the earlier
 * per-permission gating (RT-021, 2026-09-14, and the follow-up sidebar
 * audit the same day) is deliberately reverted at the menu level. This is
 * NOT a security change: every destination controller's own
 * `authorize('permission', ...)` call is completely untouched, so a role
 * that clicks a link it cannot use still gets a clean 403, not access. It
 * is purely a decision that the menu should always show the full
 * application rather than adapt per role.
 */
class SidebarLinkPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, owner: User} */
    private function makeOrganisation(string $vatNumber, string $role, array $capabilities = ['BUYER', 'SELLER']): array
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
            'password' => bcrypt('password'), 'role' => $role, 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    /**
     * Some of the Operations group's pages (Human Resources, the fixed-
     * asset registers) sit behind both a permission gate and an
     * EntitlementGate::assert license check -- this licensed variant is
     * only needed for the positive-control test that actually loads those
     * pages, not the ones proving a link is rendered.
     *
     * @return array{taxpayer: Taxpayer, organisation: Organisation, owner: User}
     */
    private function makeLicensedOrganisation(string $vatNumber, string $role, array $capabilities = ['BUYER', 'SELLER']): array
    {
        $ctx = $this->makeOrganisation($vatNumber, $role, $capabilities);
        $this->seed(LicensePlanSeeder::class);
        $subscription = Subscription::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'provider' => 'LOCAL_SYNTHETIC',
            'provider_reference' => 'synthetic-'.Str::random(12), 'status' => 'ACTIVE', 'activated_at' => now()->subMonth(),
            'current_period_start' => now()->subMonth()->toDateString(), 'current_period_end' => now()->addMonths(2)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        OrganisationLicense::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'subscription_id' => $subscription->id,
            'license_plan_id' => 'plan-pilot-professional-v1', 'state' => 'ACTIVE', 'state_version' => 1,
            'effective_from' => now()->subMonth(), 'effective_to' => null, 'retention_policy' => 'NON_DESTRUCTIVE_TAX_RETENTION', 'updated_at' => now(),
        ]);
        LicensePlanEntitlement::where('license_plan_id', 'plan-pilot-professional-v1')->update(['enabled' => true]);

        return $ctx;
    }

    /** A role holding every permission a link needs still sees and can use it -- the baseline case. */
    public function test_a_role_with_both_invoices_and_imports_read_sees_a_working_foreign_invoices_link(): void
    {
        $ctx = $this->makeOrganisation('VAT-SIDEBAR-0001', 'TAXPAYER_OWNER');

        $dashboard = $this->actingAs($ctx['owner'])->get('/dashboard');
        $dashboard->assertOk()->assertSee(route('invoice-management.foreign'), false);
        $this->actingAs($ctx['owner'])->get('/invoice-management/foreign')->assertOk();
    }

    /**
     * The sidebar always shows the Foreign Invoices link now, even to a
     * role that cannot use it (NAMRA_VAT_AUDITOR holds invoices:read but
     * not imports:read) -- the user's own explicit choice. The
     * destination's own authorize() gate is untouched, so clicking it
     * still correctly 403s; only the menu itself no longer adapts.
     */
    public function test_the_foreign_invoices_link_is_shown_even_to_a_role_that_cannot_use_it(): void
    {
        $auditor = User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Auditor', 'email' => 'auditor-sidebar-ux@test.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_VAT_AUDITOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $dashboard = $this->actingAs($auditor)->get('/dashboard');
        $dashboard->assertOk()->assertSee(route('invoice-management.foreign'), false);
        $this->actingAs($auditor)->get('/invoice-management/foreign')->assertForbidden();
    }

    /**
     * Every Operations link is shown regardless of permission, including
     * to BUYER_ADMIN (holds expenses:read only, none of employees:read/
     * fixed-assets:read/logistics:read/inventory:read) -- but each
     * destination's own authorize() gate still correctly refuses the ones
     * it cannot use.
     */
    public function test_every_operations_link_is_shown_even_to_a_role_missing_most_of_their_permissions(): void
    {
        $ctx = $this->makeOrganisation('VAT-SIDEBAR-0002', 'BUYER_ADMIN', ['BUYER']);

        $dashboard = $this->actingAs($ctx['owner'])->get('/dashboard');
        $dashboard->assertOk();
        foreach (['operations.index', 'operations.human-resources', 'operations.immovable-assets', 'operations.movable-assets', 'operations.logistics', 'operations.inventory', 'operations.erp'] as $routeName) {
            $dashboard->assertSee(route($routeName), false);
        }

        $this->actingAs($ctx['owner'])->get('/operations')->assertOk();
        $this->actingAs($ctx['owner'])->get('/operations/human-resources')->assertForbidden();
        $this->actingAs($ctx['owner'])->get('/operations/immovable-assets')->assertForbidden();
        $this->actingAs($ctx['owner'])->get('/operations/movable-assets')->assertForbidden();
        $this->actingAs($ctx['owner'])->get('/operations/logistics')->assertForbidden();
        $this->actingAs($ctx['owner'])->get('/operations/inventory')->assertForbidden();
    }

    /** A role holding every Operations permission still sees and can use every Operations link. */
    public function test_a_role_with_every_operations_permission_still_sees_and_can_use_every_operations_link(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-SIDEBAR-0003', 'TAXPAYER_OWNER');

        $dashboard = $this->actingAs($ctx['owner'])->get('/dashboard');
        $dashboard->assertOk();
        foreach (['operations.index', 'operations.human-resources', 'operations.immovable-assets', 'operations.movable-assets', 'operations.logistics', 'operations.inventory', 'operations.erp'] as $routeName) {
            $dashboard->assertSee(route($routeName), false);
        }

        $this->actingAs($ctx['owner'])->get('/operations/human-resources')->assertOk();
        $this->actingAs($ctx['owner'])->get('/operations/immovable-assets')->assertOk();
        $this->actingAs($ctx['owner'])->get('/operations/movable-assets')->assertOk();
        $this->actingAs($ctx['owner'])->get('/operations/logistics')->assertOk();
        $this->actingAs($ctx['owner'])->get('/operations/inventory')->assertOk();
    }

    /**
     * Every sidebar group header is shown to every user now, even a
     * permission-light role like SUPER_ADMIN (which holds only Platform
     * and Access Rights among the standalone links, and none of the nine
     * accordion groups' own permissions) -- clicking into a group whose
     * links it can't use just shows those links, which then correctly
     * 403 on their own destinations.
     */
    public function test_every_group_header_and_link_is_shown_even_to_a_permission_light_role(): void
    {
        $superAdmin = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Platform Super Admin', 'email' => 'super-admin-sidebar-ux@test.test',
            'password' => bcrypt('password'), 'role' => 'SUPER_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $dashboard = $this->actingAs($superAdmin)->get('/dashboard');
        $dashboard->assertOk();
        foreach (['VAT Management', 'Invoice Management', 'Accounting &amp; Finance', 'Operations</span>', 'Quotation</span>', 'Project Management', 'Registered</span>', 'New Registration', 'Administration</span>'] as $groupHeading) {
            $dashboard->assertSee($groupHeading, false);
        }
        $dashboard->assertSee(route('platform.index'), false);
        $dashboard->assertSee(route('access-rights.index'), false);
        $dashboard->assertSee(route('vat-periods.index'), false);

        // The menu shows the link; the destination still correctly refuses it.
        $this->actingAs($superAdmin)->get('/vat-periods')->assertForbidden();
    }
}
