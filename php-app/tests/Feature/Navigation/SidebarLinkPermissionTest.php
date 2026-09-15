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
 * UX Failure Discovery pass (2026-09-14): covers the real rendered sidebar
 * in resources/views/layouts/app.blade.php -- a *different*, older
 * navigation surface than App\Services\Navigation\NavigationService's own
 * JSON-driven workspace tree (see NavigationTest). A live, full-sidebar
 * Playwright crawl across ten distinct roles found two spots where a link
 * was rendered under one permission's `@can` gate while its destination
 * controller's own `authorize('permission', ...)` call required a
 * *different* permission some of those roles held the first of but not the
 * second -- a dead-end click, not a security hole (the controller still
 * correctly refused the request; only the sidebar was inconsistent with
 * it). Both are fixed by gating each link on its own actual permission
 * instead of bundling it under a sibling link's gate.
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
     * pages, not the ones proving a link is/isn't rendered.
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

    /**
     * RT-021a: BUYER_ADMIN holds invoices:read + imports:read (both), so
     * this is a control confirming the fix did not remove the link for a
     * role that genuinely has access -- see the negative case below for
     * the actual regression.
     */
    public function test_a_role_with_both_invoices_and_imports_read_sees_a_working_foreign_invoices_link(): void
    {
        $ctx = $this->makeOrganisation('VAT-SIDEBAR-0001', 'TAXPAYER_OWNER');

        $dashboard = $this->actingAs($ctx['owner'])->get('/dashboard');
        $dashboard->assertOk()->assertSee(route('invoice-management.foreign'), false);
        $this->actingAs($ctx['owner'])->get('/invoice-management/foreign')->assertOk();
    }

    /**
     * RT-021a: NAMRA_VAT_AUDITOR holds invoices:read but not imports:read
     * (confirmed against Permissions::effectiveForRole) -- the sidebar
     * used to render this link anyway (gated on the sibling permission)
     * and it 403'd on click.
     */
    public function test_a_role_with_invoices_read_but_not_imports_read_does_not_see_the_foreign_invoices_link(): void
    {
        $auditor = User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Auditor', 'email' => 'auditor-sidebar-ux@test.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_VAT_AUDITOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $dashboard = $this->actingAs($auditor)->get('/dashboard');
        $dashboard->assertOk()->assertDontSee(route('invoice-management.foreign'), false);
        $this->actingAs($auditor)->get('/invoice-management/foreign')->assertForbidden();
    }

    /**
     * RT-021b: BUYER_ADMIN holds expenses:read but none of
     * employees:read/fixed-assets:read/logistics:read (confirmed against
     * Permissions::effectiveForRole) -- the Operations group's Human
     * Resources / Immovable / Movable / Logistics links all used to render
     * under the expenses:read gate and all 403'd on click.
     */
    public function test_a_role_with_expenses_read_but_not_the_operations_sibling_permissions_does_not_see_those_links(): void
    {
        $ctx = $this->makeOrganisation('VAT-SIDEBAR-0002', 'BUYER_ADMIN', ['BUYER']);

        $dashboard = $this->actingAs($ctx['owner'])->get('/dashboard');
        $dashboard->assertOk();
        // Still sees the two links its own permission genuinely covers.
        $dashboard->assertSee(route('operations.index'), false);
        $dashboard->assertSee(route('operations.erp'), false);
        // No longer sees the four it does not hold permission for.
        $dashboard->assertDontSee(route('operations.human-resources'), false);
        $dashboard->assertDontSee(route('operations.immovable-assets'), false);
        $dashboard->assertDontSee(route('operations.movable-assets'), false);
        $dashboard->assertDontSee(route('operations.logistics'), false);

        $this->actingAs($ctx['owner'])->get('/operations/human-resources')->assertForbidden();
        $this->actingAs($ctx['owner'])->get('/operations/immovable-assets')->assertForbidden();
        $this->actingAs($ctx['owner'])->get('/operations/movable-assets')->assertForbidden();
        $this->actingAs($ctx['owner'])->get('/operations/logistics')->assertForbidden();
    }

    /**
     * RT-021b, positive control: TAXPAYER_OWNER holds all four of the
     * Operations group's permissions, so every link should still render
     * and work after splitting the single shared gate into four.
     */
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
     * Sidebar audit (2026-09-14): PosViewController's Inventory Module
     * (Operations > Inventory, App\Http\Controllers\Operations\
     * PosViewController, gated on inventory:read) was a real, fully built
     * page -- ported from the source's own app/operations/inventory/
     * {page.tsx,PosTerminal.tsx} -- with no sidebar entry anywhere: unlike
     * every other Operations page, it wasn't dead-linked by a mismatched
     * permission, it simply had no link at all, reachable only by typing
     * the URL directly. BUYER_ADMIN, which lacks inventory:read, is the
     * negative control here; the positive control (a role that does hold
     * it) is covered by the "every operations permission" test above.
     */
    public function test_a_role_without_inventory_read_does_not_see_the_new_inventory_module_link(): void
    {
        $ctx = $this->makeOrganisation('VAT-SIDEBAR-0004', 'BUYER_ADMIN', ['BUYER']);

        $dashboard = $this->actingAs($ctx['owner'])->get('/dashboard');
        $dashboard->assertOk()->assertDontSee(route('operations.inventory'), false);
        $this->actingAs($ctx['owner'])->get('/operations/inventory')->assertForbidden();
    }

    /**
     * Sidebar audit (2026-09-14): a group whose every item is gated on a
     * permission the user lacks used to still render its own accordion
     * header -- clicking it expanded to a visibly empty list, since every
     * item's own @can already hid it, but nothing hid the now-pointless
     * header itself. SUPER_ADMIN is the clearest live case: of the nine
     * dropdown-style groups, it holds a permission for only "Dashboard"
     * (ungated) and "Administration" was previously the only one with any
     * content by coincidence of route testing -- SUPER_ADMIN actually
     * holds none of administration:read/identity:read/workflows:read
     * either, per Permissions::ROLE_PERMISSIONS, so that group should be
     * hidden too, leaving only Platform and Access Rights (both single,
     * non-accordion links) alongside Dashboard.
     */
    public function test_a_role_holding_none_of_a_groups_permissions_does_not_see_that_groups_header_at_all(): void
    {
        $superAdmin = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Platform Super Admin', 'email' => 'super-admin-sidebar-ux@test.test',
            'password' => bcrypt('password'), 'role' => 'SUPER_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $dashboard = $this->actingAs($superAdmin)->get('/dashboard');
        $dashboard->assertOk();
        foreach (['VAT Management', 'Invoice Management', 'Accounting &amp; Finance', 'Operations</span>', 'Quotation</span>', 'Project Management', 'Registered</span>', 'New Registration', 'Administration</span>'] as $emptyGroupHeading) {
            $dashboard->assertDontSee($emptyGroupHeading, false);
        }
        // Still sees the two single-permission links it genuinely holds.
        $dashboard->assertSee(route('platform.index'), false);
        $dashboard->assertSee(route('access-rights.index'), false);
    }
}
