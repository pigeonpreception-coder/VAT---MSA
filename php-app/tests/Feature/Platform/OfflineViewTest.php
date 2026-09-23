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
 * Covers the real Blade UI for the offline continuity register
 * (App\Http\Controllers\Platform\OfflineViewController /
 * resources/views/offline/index.blade.php) -- ported from the source's own
 * app/offline/page.tsx. Gap-finding pass (2026-09-23): this page had no
 * Laravel Blade equivalent at all -- only the JSON API surface
 * (PlatformSnapshotController, OfflineSyncController) existed. Purely
 * read-only, reusing PlatformSnapshotService::getSnapshot() directly, so
 * this file's own job is the access gate and the view's own rendering.
 */
class OfflineViewTest extends TestCase
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
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@offlineview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    public function test_the_offline_page_requires_authentication(): void
    {
        $this->get('/offline')->assertRedirect('/login');
    }

    public function test_a_role_without_offline_read_is_denied(): void
    {
        $org = $this->makeOrganisation('VAT-OFFLINE-DENY');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Viewer', 'email' => 'viewer@offlineview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_VIEWER', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($viewer)->get('/offline')->assertForbidden();
    }

    public function test_the_offline_page_renders_its_metric_tiles_and_registers(): void
    {
        $org = $this->makeOrganisation('VAT-OFFLINE-0001');
        $deviceId = (string) Str::uuid();
        DB::table('offline_devices')->insert([
            'id' => $deviceId, 'organisation_id' => $org['organisation']->id, 'device_code' => 'DEV-001',
            'display_name' => 'Branch Tablet 1', 'status' => 'ACTIVE', 'enrolment_status' => 'VERIFIED',
            'last_accepted_sequence' => 42, 'last_seen_at' => now(), 'created_at' => now(),
        ]);
        DB::table('offline_number_ranges')->insert([
            'id' => (string) Str::uuid(), 'offline_device_id' => $deviceId, 'document_type' => 'TAX_INVOICE',
            'prefix' => 'OFF', 'range_start' => 1, 'range_end' => 1000, 'next_number' => 5, 'status' => 'ACTIVE',
            'valid_from' => now()->subDay(), 'valid_to' => now()->addYear(),
        ]);
        $batchId = (string) Str::uuid();
        DB::table('offline_sync_batches')->insert([
            'id' => $batchId, 'offline_device_id' => $deviceId, 'client_batch_id' => 'BATCH-001',
            'sequence_from' => 1, 'sequence_to' => 5, 'batch_hash' => str_repeat('a', 64), 'signature' => 'sig',
            'document_count' => 5, 'status' => 'REJECTED', 'rejection_reason' => 'Hash chain mismatch', 'received_at' => now(),
        ]);
        DB::table('offline_conflicts')->insert([
            'id' => (string) Str::uuid(), 'offline_sync_batch_id' => $batchId, 'conflict_type' => 'DUPLICATE_SEQUENCE',
            'source_document_id' => 'doc-1', 'status' => 'OPEN', 'created_at' => now(),
        ]);

        $response = $this->actingAs($org['owner'])->get('/offline');

        $response->assertOk()->assertViewIs('offline.index');
        $response->assertSee('Offline devices, number ranges and ordered synchronisation');
        $response->assertSeeInOrder(['Devices', '1']);
        $response->assertSeeInOrder(['Active ranges', '1']);
        $response->assertSeeInOrder(['Rejected batches', '1']);
        $response->assertSeeInOrder(['Open conflicts', '1']);
        $response->assertSee('Branch Tablet 1');
        $response->assertSee('BATCH-001');
    }

    public function test_the_offline_page_renders_cleanly_with_no_data(): void
    {
        $org = $this->makeOrganisation('VAT-OFFLINE-0002');

        $response = $this->actingAs($org['owner'])->get('/offline');

        $response->assertOk();
        $response->assertSee('No offline devices are enrolled.');
        $response->assertSee('No offline batches received.');
    }
}
