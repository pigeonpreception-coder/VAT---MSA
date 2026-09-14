<?php

namespace Tests\Feature\Operations;

use App\Exceptions\RepositoryConflictException;
use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\Taxpayer;
use App\Models\User;
use App\Services\Operations\FixedAssetService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for Operations > Immovable Asset Management and
 * Movable Asset Management (App\Http\Controllers\Operations\
 * FixedAssetViewController / resources/views/operations/fixed-assets/
 * index.blade.php) -- ported from the source's own app/operations/
 * {immovable-assets,movable-assets}/page.tsx + components/
 * FixedAssetManager.tsx. One shared controller/service/table backs both
 * pages via the asset_class discriminator, so this file covers both from
 * one register rather than duplicating coverage per page.
 */
class FixedAssetViewTest extends TestCase
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
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@fixedasset.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    public function test_the_immovable_assets_page_requires_authentication(): void
    {
        $this->get('/operations/immovable-assets')->assertRedirect('/login');
    }

    public function test_a_role_without_fixed_assets_read_is_forbidden(): void
    {
        $org = $this->makeOrganisation('VAT-FA-0001');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Buyer Viewer', 'email' => 'buyer-viewer@fixedasset.test',
            'password' => bcrypt('password'), 'role' => 'BUYER_USER', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($viewer)->get('/operations/immovable-assets')->assertForbidden();
    }

    public function test_registering_an_immovable_asset_shows_on_that_register_only(): void
    {
        $org = $this->makeOrganisation('VAT-FA-0002');

        $response = $this->actingAs($org['owner'])->post('/operations/fixed-assets', [
            'asset_class' => 'IMMOVABLE', 'asset_code' => 'BLD-001', 'category' => 'BUILDING',
            'description' => 'Head office building', 'location_or_address' => '10 Independence Ave, Windhoek',
            'acquisition_date' => '2020-01-15', 'acquisition_cost_cents' => 500_000_00,
            'return_to' => 'operations.immovable-assets',
        ]);

        $response->assertRedirect(route('operations.immovable-assets'));
        $this->assertDatabaseHas('fixed_assets', ['organisation_id' => $org['organisation']->id, 'asset_code' => 'BLD-001', 'asset_class' => 'IMMOVABLE', 'status' => 'ACTIVE']);

        $immovable = $this->actingAs($org['owner'])->get('/operations/immovable-assets');
        $immovable->assertOk()->assertSee('BLD-001');

        $movable = $this->actingAs($org['owner'])->get('/operations/movable-assets');
        $movable->assertOk()->assertDontSee('BLD-001');
    }

    /**
     * Input Validation & Robustness pass (2026-09-14): store() used to
     * `(int) $request->input('acquisition_cost_cents', 0)` before
     * validation -- a non-numeric submission silently coerced to 0
     * rather than being rejected. Now rejected cleanly via
     * Controller::safeIntegerInput().
     */
    public function test_a_non_numeric_acquisition_cost_is_rejected_not_silently_zeroed(): void
    {
        $org = $this->makeOrganisation('VAT-FA-0002B');

        $response = $this->actingAs($org['owner'])->post('/operations/fixed-assets', [
            'asset_class' => 'IMMOVABLE', 'asset_code' => 'BLD-NAN-001', 'category' => 'BUILDING',
            'description' => 'Fat-fingered cost', 'location_or_address' => '10 Independence Ave, Windhoek',
            'acquisition_date' => '2020-01-15', 'acquisition_cost_cents' => 'not-a-number',
            'return_to' => 'operations.immovable-assets',
        ]);

        $response->assertRedirect(route('operations.immovable-assets'));
        $response->assertSessionHasErrors();
        $this->assertDatabaseMissing('fixed_assets', ['asset_code' => 'BLD-NAN-001']);
    }

    public function test_a_duplicate_asset_code_is_a_friendly_form_error_not_a_raw_409(): void
    {
        $org = $this->makeOrganisation('VAT-FA-0003');
        $this->actingAs($org['owner'])->post('/operations/fixed-assets', [
            'asset_class' => 'MOVABLE', 'asset_code' => 'VEH-001', 'category' => 'VEHICLE', 'description' => 'Delivery van',
            'location_or_address' => 'Main depot', 'acquisition_date' => '2022-06-01', 'acquisition_cost_cents' => 250_000_00,
            'return_to' => 'operations.movable-assets',
        ])->assertRedirect(route('operations.movable-assets'));

        $response = $this->actingAs($org['owner'])->post('/operations/fixed-assets', [
            'asset_class' => 'MOVABLE', 'asset_code' => 'VEH-001', 'category' => 'VEHICLE', 'description' => 'Second van',
            'location_or_address' => 'Main depot', 'acquisition_date' => '2023-01-01', 'acquisition_cost_cents' => 260_000_00,
            'return_to' => 'operations.movable-assets',
        ]);

        $response->assertRedirect(route('operations.movable-assets'));
        $response->assertSessionHasErrors('asset');
        $this->assertSame(1, \App\Models\FixedAsset::where('organisation_id', $org['organisation']->id)->where('asset_code', 'VEH-001')->count());
    }

    public function test_the_full_maintenance_and_disposal_lifecycle_transitions_correctly(): void
    {
        $org = $this->makeOrganisation('VAT-FA-0004');
        $this->actingAs($org['owner'])->post('/operations/fixed-assets', [
            'asset_class' => 'MOVABLE', 'asset_code' => 'VEH-002', 'category' => 'VEHICLE', 'description' => 'Forklift',
            'location_or_address' => 'Warehouse A', 'acquisition_date' => '2021-03-10', 'acquisition_cost_cents' => 180_000_00,
            'return_to' => 'operations.movable-assets',
        ]);
        $assetId = \App\Models\FixedAsset::where('organisation_id', $org['organisation']->id)->where('asset_code', 'VEH-002')->firstOrFail()->id;

        $this->actingAs($org['owner'])->post("/operations/fixed-assets/{$assetId}/maintenance")->assertRedirect(route('operations.immovable-assets'));
        $this->assertDatabaseHas('fixed_assets', ['id' => $assetId, 'status' => 'UNDER_MAINTENANCE']);

        // Disposing directly from ACTIVE would be valid too, but here we restore first to exercise both edges.
        $this->actingAs($org['owner'])->post("/operations/fixed-assets/{$assetId}/restoration")->assertRedirect(route('operations.immovable-assets'));
        $this->assertDatabaseHas('fixed_assets', ['id' => $assetId, 'status' => 'ACTIVE']);

        $this->actingAs($org['owner'])->post("/operations/fixed-assets/{$assetId}/disposal", ['reason' => 'Beyond economical repair.'])
            ->assertRedirect(route('operations.immovable-assets'));
        $this->assertDatabaseHas('fixed_assets', ['id' => $assetId, 'status' => 'DISPOSED', 'disposal_reason' => 'Beyond economical repair.']);

        // A disposed asset cannot be flagged for maintenance again -- a friendly form error, not a raw exception.
        $reflag = $this->actingAs($org['owner'])->post("/operations/fixed-assets/{$assetId}/maintenance");
        $reflag->assertSessionHasErrors();
    }

    public function test_a_taxpayer_cannot_act_on_another_organisations_asset(): void
    {
        $orgA = $this->makeOrganisation('VAT-FA-0005');
        $orgB = $this->makeOrganisation('VAT-FA-0006');
        $this->actingAs($orgB['owner'])->post('/operations/fixed-assets', [
            'asset_class' => 'IMMOVABLE', 'asset_code' => 'BLD-002', 'category' => 'LAND', 'description' => 'Vacant plot',
            'location_or_address' => 'Otjiwarongo', 'acquisition_date' => '2019-09-01', 'acquisition_cost_cents' => 90_000_00,
            'return_to' => 'operations.immovable-assets',
        ]);
        $assetId = \App\Models\FixedAsset::withoutOrganisationScope()->where('organisation_id', $orgB['organisation']->id)->where('asset_code', 'BLD-002')->firstOrFail()->id;

        // App\Models\Scopes\OrganisationScope (the automatic tenant-scope
        // backstop every BelongsToOrganisation model registers) filters this
        // row out of orgA's own query entirely, so FixedAssetService::
        // loadForActor's lookup genuinely finds nothing and throws a 404-
        // classed BusinessResourceException -- but this controller's
        // runTransition (matching QuotationViewController's own
        // runTransition precedent for a POST transition route) always turns
        // that into a friendly redirect with a flashed form error, never a
        // raw HTTP 404 page, regardless of the exception's own status code.
        $response = $this->actingAs($orgA['owner'])->post("/operations/fixed-assets/{$assetId}/maintenance");
        $response->assertRedirect(route('operations.immovable-assets'));
        $response->assertSessionHasErrors('asset');
    }

    /**
     * Resilience to User Errors pass (2026-09-14): FixedAssetService::
     * transition() read the asset's status once, validated the requested
     * action against that in-memory value, then wrote the new status with
     * a plain `where('id', $id)->update(...)` -- no re-check that the row
     * was still in the status it was read as. Two different action
     * buttons clicked from the same stale page (e.g. "Flag for
     * Maintenance" then "Dispose") could both pass their own validation
     * against the same stale row and both writes would land, the second
     * silently overwriting the first with no conflict signal and an
     * audit/outbox trail inconsistent with the row's real history.
     *
     * A genuinely concurrent double-click can't be produced by a single
     * synchronous PHPUnit process, so this test simulates the exact race
     * window instead of merely asserting the end state: a `DB::listen()`
     * hook fires a real, separate UPDATE against the same row the instant
     * after `transition()`'s own read query returns -- i.e. after this
     * request has already decided the asset is ACTIVE but before its own
     * guarded UPDATE runs -- reproducing precisely what a second, faster
     * concurrent request would have done.
     */
    public function test_a_status_change_that_races_a_concurrent_transition_is_rejected_not_silently_applied(): void
    {
        $org = $this->makeOrganisation('VAT-FA-0007');
        $service = app(FixedAssetService::class);
        $created = $service->register([
            'schema_version' => '1.0.0', 'asset_class' => 'MOVABLE', 'asset_code' => 'VEH-RACE-001', 'category' => 'VEHICLE', 'description' => 'Race-condition probe vehicle',
            'location_or_address' => 'Main depot', 'acquisition_date' => '2022-06-01', 'acquisition_cost_cents' => 250_000_00,
        ], $org['owner'], (string) Str::uuid(), (string) Str::uuid(), null);
        $assetId = $created['id'];
        $this->assertSame('ACTIVE', $created['status']);

        $sabotaged = false;
        DB::listen(function ($query) use (&$sabotaged, $assetId) {
            if ($sabotaged || ! str_contains($query->sql, 'select * from `fixed_assets`')) {
                return;
            }
            $sabotaged = true;
            // Simulates a second, concurrent request (e.g. "Dispose") that
            // completes its own transition first, changing the row's real
            // status out from under this still-in-flight request.
            DB::table('fixed_assets')->where('id', $assetId)->update(['status' => 'DISPOSED']);
        });

        try {
            $this->expectException(RepositoryConflictException::class);
            $service->flagMaintenance($assetId, $org['owner'], (string) Str::uuid(), (string) Str::uuid());
        } finally {
            DB::flushQueryLog();
        }

        $this->assertSame('DISPOSED', DB::table('fixed_assets')->where('id', $assetId)->value('status'), 'The concurrent winners status must survive untouched.');
        $this->assertDatabaseMissing('audit_events', ['action' => 'FIXED_ASSET_FLAG_MAINTENANCED', 'resource_id' => $assetId]);
    }
}
