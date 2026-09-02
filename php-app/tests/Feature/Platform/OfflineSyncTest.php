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
 * Covers App\Services\Platform\OfflineSyncService (ported from
 * lib/data/platform-repository.ts's receiveOfflineBatch) -- Module 22's
 * offline-invoicing sync-batch intake, Phase 13's third slice.
 */
class OfflineSyncTest extends TestCase
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

    private function taxpayerOwner(string $taxpayerId, string $email): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Owner', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayerId, 'status' => 'ACTIVE',
        ]);
    }

    private function taxpayerStaff(string $taxpayerId, string $email): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Staff', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_STAFF', 'taxpayer_id' => $taxpayerId, 'status' => 'ACTIVE',
        ]);
    }

    private function pilotAdmin(string $email = 'pilot@offlinetest.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Pilot Admin', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'PILOT_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    /** @return string the new device's id */
    private function makeDevice(string $organisationId, array $overrides = []): string
    {
        $id = (string) Str::uuid();
        DB::table('offline_devices')->insert(array_merge([
            'id' => $id, 'organisation_id' => $organisationId, 'device_code' => 'DEV-'.mb_substr($id, 0, 8),
            'display_name' => 'Test Till', 'public_key_reference' => 'pk-ref-'.mb_substr($id, 0, 8),
            'status' => 'ACTIVE', 'enrolment_status' => 'VERIFIED', 'last_accepted_sequence' => 0,
            'last_batch_hash' => null, 'created_at' => now(),
        ], $overrides));

        return $id;
    }

    private function isoNow(): string
    {
        return now()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    /** @return array<string, mixed> */
    private function validPayload(string $deviceId, array $overrides = []): array
    {
        return array_merge([
            'device_id' => $deviceId,
            'batch_id' => (string) Str::uuid(),
            'sequence_from' => 1,
            'sequence_to' => 1,
            'created_at' => $this->isoNow(),
            'documents' => [['local_id' => 'doc-1', 'type' => 'TAX_INVOICE']],
            'device_signature' => str_repeat('a', 64),
        ], $overrides);
    }

    public function test_offline_sync_permission_is_required(): void
    {
        $tp = $this->makeTaxpayer('VAT-OFF-0001');
        $staff = $this->taxpayerStaff($tp['taxpayer']->id, 'staff@offlinetest.test');
        $deviceId = $this->makeDevice($tp['organisation']->id);

        $this->actingAs($staff)->postJson('/api/v1/offline/batches', $this->validPayload($deviceId))
            ->assertStatus(403);
    }

    public function test_unknown_device_returns_404(): void
    {
        $tp = $this->makeTaxpayer('VAT-OFF-0002');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'owner-404@offlinetest.test');

        $this->actingAs($owner)->postJson('/api/v1/offline/batches', $this->validPayload('DEV-NONEXISTENT'))
            ->assertStatus(404);
    }

    public function test_device_outside_the_actors_taxpayer_scope_is_denied(): void
    {
        $ownerTp = $this->makeTaxpayer('VAT-OFF-0003');
        $otherTp = $this->makeTaxpayer('VAT-OFF-0004');
        $owner = $this->taxpayerOwner($ownerTp['taxpayer']->id, 'owner-scope@offlinetest.test');
        $otherDeviceId = $this->makeDevice($otherTp['organisation']->id);

        $this->actingAs($owner)->postJson('/api/v1/offline/batches', $this->validPayload($otherDeviceId))
            ->assertStatus(403);
    }

    public function test_national_scope_actor_can_submit_for_any_devices_taxpayer(): void
    {
        $tp = $this->makeTaxpayer('VAT-OFF-0005');
        $admin = $this->pilotAdmin();
        $deviceId = $this->makeDevice($tp['organisation']->id);

        $response = $this->actingAs($admin)->postJson('/api/v1/offline/batches', $this->validPayload($deviceId));

        $response->assertStatus(202);
        $this->assertSame('REJECTED', $response->json('batch.status'));
    }

    /**
     * Faithful-port coverage for OfflineSyncService's own doc comment: the
     * source never wired up real device-signature verification, so a batch
     * that clears every other check still falls through to the
     * "SIGNATURE_VERIFIER_NOT_CONFIGURED" default and is recorded REJECTED.
     */
    public function test_a_batch_that_passes_every_other_check_is_still_rejected_for_no_signature_verifier(): void
    {
        $tp = $this->makeTaxpayer('VAT-OFF-0006');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'owner-noverifier@offlinetest.test');
        $deviceId = $this->makeDevice($tp['organisation']->id);

        $response = $this->actingAs($owner)->postJson('/api/v1/offline/batches', $this->validPayload($deviceId));

        $response->assertStatus(202);
        $body = $response->json('batch');
        $this->assertSame('REJECTED', $body['status']);
        $this->assertSame('SIGNATURE_VERIFIER_NOT_CONFIGURED', $body['rejection_reason']);
        $this->assertDatabaseCount('offline_sync_batches', 1);
    }

    public function test_a_device_not_yet_verified_is_rejected_for_device_trust_not_established(): void
    {
        $tp = $this->makeTaxpayer('VAT-OFF-0007');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'owner-untrusted@offlinetest.test');
        $deviceId = $this->makeDevice($tp['organisation']->id, ['enrolment_status' => 'PENDING']);

        $response = $this->actingAs($owner)->postJson('/api/v1/offline/batches', $this->validPayload($deviceId));

        $response->assertStatus(202);
        $this->assertSame('DEVICE_TRUST_NOT_ESTABLISHED', $response->json('batch.rejection_reason'));
    }

    public function test_a_sequence_gap_is_rejected_for_sequence_gap_or_replay(): void
    {
        $tp = $this->makeTaxpayer('VAT-OFF-0008');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'owner-gap@offlinetest.test');
        $deviceId = $this->makeDevice($tp['organisation']->id, ['last_accepted_sequence' => 5]);

        // sequence_from must equal last_accepted_sequence + 1 (i.e. 6); 1 is a gap.
        $response = $this->actingAs($owner)->postJson('/api/v1/offline/batches', $this->validPayload($deviceId));

        $response->assertStatus(202);
        $this->assertSame('SEQUENCE_GAP_OR_REPLAY', $response->json('batch.rejection_reason'));
    }

    public function test_a_hash_chain_mismatch_is_rejected_for_hash_chain_mismatch(): void
    {
        $tp = $this->makeTaxpayer('VAT-OFF-0009');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'owner-hash@offlinetest.test');
        $deviceId = $this->makeDevice($tp['organisation']->id, ['last_batch_hash' => str_repeat('b', 64)]);

        // No previous_batch_hash supplied, so it cannot match the device's stored chain tip.
        $response = $this->actingAs($owner)->postJson('/api/v1/offline/batches', $this->validPayload($deviceId));

        $response->assertStatus(202);
        $this->assertSame('HASH_CHAIN_MISMATCH', $response->json('batch.rejection_reason'));
    }

    public function test_replaying_the_same_batch_id_with_identical_content_returns_the_prior_batch_without_inserting_again(): void
    {
        $tp = $this->makeTaxpayer('VAT-OFF-0010');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'owner-replay@offlinetest.test');
        $deviceId = $this->makeDevice($tp['organisation']->id);
        $payload = $this->validPayload($deviceId);

        $first = $this->actingAs($owner)->postJson('/api/v1/offline/batches', $payload);
        $first->assertStatus(202);
        $firstId = $first->json('batch.id');

        $second = $this->actingAs($owner)->postJson('/api/v1/offline/batches', $payload);
        $second->assertStatus(202);

        $this->assertSame($firstId, $second->json('batch.id'));
        $this->assertDatabaseCount('offline_sync_batches', 1);
    }

    public function test_replaying_the_same_batch_id_with_different_content_conflicts(): void
    {
        $tp = $this->makeTaxpayer('VAT-OFF-0011');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'owner-conflict@offlinetest.test');
        $deviceId = $this->makeDevice($tp['organisation']->id);
        $batchId = (string) Str::uuid();

        $this->actingAs($owner)->postJson('/api/v1/offline/batches', $this->validPayload($deviceId, ['batch_id' => $batchId]))
            ->assertStatus(202);

        $conflict = $this->actingAs($owner)->postJson('/api/v1/offline/batches', $this->validPayload($deviceId, [
            'batch_id' => $batchId, 'device_signature' => str_repeat('c', 64),
        ]));

        $conflict->assertStatus(409);
        $this->assertDatabaseCount('offline_sync_batches', 1);
    }

    public function test_an_invalid_payload_fails_validation_with_a_list_of_errors(): void
    {
        $tp = $this->makeTaxpayer('VAT-OFF-0012');
        $owner = $this->taxpayerOwner($tp['taxpayer']->id, 'owner-invalid@offlinetest.test');
        $deviceId = $this->makeDevice($tp['organisation']->id);

        // sequence_to/documents count mismatch (2 documents for a 1-item range) and a too-short signature.
        $response = $this->actingAs($owner)->postJson('/api/v1/offline/batches', $this->validPayload($deviceId, [
            'documents' => [['local_id' => 'doc-1'], ['local_id' => 'doc-2']],
            'device_signature' => 'too-short',
        ]));

        $response->assertStatus(422);
        $codes = collect($response->json('errors'))->pluck('code');
        $this->assertTrue($codes->contains('SEQUENCE_DOCUMENT_MISMATCH'));
        $this->assertTrue($codes->contains('SIGNATURE_INVALID'));
    }
}
