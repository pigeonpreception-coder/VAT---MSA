<?php

namespace Tests\Feature\TaxpayerSystem;

use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\TaxpayerSystemRegistration;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithStepUp;
use Tests\TestCase;

/**
 * Covers the NamRA e-VAT MS Registered Taxpayer Systems Framework
 * (App\Services\TaxpayerSystem\TaxpayerSystemService, ported from
 * lib/data/taxpayer-system-repository.ts) -- RegisterTaxpayerSystem/
 * ApproveTaxpayerSystem/SuspendTaxpayerSystem/RecordTaxpayerSystemSync/
 * GetTaxpayerSystem/ListTaxpayerSystems, over both the JSON API and the
 * Blade view built alongside it. Real HTTP, real MySQL, no mocks.
 */
class TaxpayerSystemTest extends TestCase
{
    use InteractsWithStepUp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, owner: User} */
    private function makeTradingParty(string $vatNumber): array
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => strtolower($vatNumber).'@tstest.test',
        ]);
        $organisation = Organisation::create([
            'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE',
        ]);
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@tstest.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    private function makeNamraSupervisor(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Supervisor', 'email' => 'supervisor-'.Str::random(8).'@tstest.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_VAT_SUPERVISOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function registrationPayload(array $overrides = []): array
    {
        return array_replace([
            'schema_version' => '1.0.0', 'system_name' => 'Acme ERP', 'system_vendor' => 'Acme Software',
            'system_category' => 'ERP', 'credential_reference' => 'ref-'.Str::random(10),
        ], $overrides);
    }

    public function test_registering_a_system_matches_the_actors_own_taxpayer_and_starts_as_draft(): void
    {
        $ctx = $this->makeTradingParty('VAT-TS-0001');

        $response = $this->actingAs($ctx['owner'])->postJson('/api/v1/taxpayer-systems', $this->registrationPayload([
            'vat_registration_number' => $ctx['taxpayer']->vat_number,
        ]), ['Idempotency-Key' => 'ts-'.Str::random(20)]);

        $response->assertStatus(201)
            ->assertJsonPath('resource.registration_status', 'DRAFT')
            ->assertJsonPath('resource.api_status', 'NOT_CONNECTED')
            ->assertJsonPath('resource.security_status', 'NOT_ASSESSED');
        $this->assertDatabaseHas('taxpayer_system_registrations', [
            'organisation_id' => $ctx['organisation']->id, 'system_name' => 'Acme ERP', 'system_vendor' => 'Acme Software',
        ]);
    }

    public function test_registering_a_system_with_a_vat_number_that_does_not_match_the_actors_own_taxpayer_is_rejected(): void
    {
        $ctx = $this->makeTradingParty('VAT-TS-0002');

        $response = $this->actingAs($ctx['owner'])->postJson('/api/v1/taxpayer-systems', $this->registrationPayload([
            'vat_registration_number' => 'VAT-SOMEONE-ELSE',
        ]), ['Idempotency-Key' => 'ts-'.Str::random(20)]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('taxpayer_system_registrations', 0);
    }

    public function test_registering_a_system_with_an_invalid_category_is_a_validation_error(): void
    {
        $ctx = $this->makeTradingParty('VAT-TS-0003');

        $response = $this->actingAs($ctx['owner'])->postJson('/api/v1/taxpayer-systems', $this->registrationPayload([
            'vat_registration_number' => $ctx['taxpayer']->vat_number, 'system_category' => 'NOT_A_CATEGORY',
        ]), ['Idempotency-Key' => 'ts-'.Str::random(20)]);

        $response->assertStatus(422);
    }

    public function test_a_duplicate_registration_for_the_same_organisation_system_name_and_vendor_is_a_conflict(): void
    {
        $ctx = $this->makeTradingParty('VAT-TS-0004');
        $payload = $this->registrationPayload(['vat_registration_number' => $ctx['taxpayer']->vat_number]);
        $this->actingAs($ctx['owner'])->postJson('/api/v1/taxpayer-systems', $payload, ['Idempotency-Key' => 'ts-'.Str::random(20)])->assertStatus(201);

        $response = $this->actingAs($ctx['owner'])->postJson('/api/v1/taxpayer-systems', $payload, ['Idempotency-Key' => 'ts-'.Str::random(20)]);

        $response->assertStatus(409);
        $this->assertDatabaseCount('taxpayer_system_registrations', 1);
    }

    public function test_a_replayed_idempotency_key_returns_the_same_resource_not_a_second_registration(): void
    {
        $ctx = $this->makeTradingParty('VAT-TS-0005');
        $payload = $this->registrationPayload(['vat_registration_number' => $ctx['taxpayer']->vat_number]);
        $key = 'ts-replay-'.Str::random(20);

        $first = $this->actingAs($ctx['owner'])->postJson('/api/v1/taxpayer-systems', $payload, ['Idempotency-Key' => $key]);
        $second = $this->actingAs($ctx['owner'])->postJson('/api/v1/taxpayer-systems', $payload, ['Idempotency-Key' => $key]);

        $first->assertStatus(201);
        $second->assertStatus(201)->assertJsonPath('resource.id', $first->json('resource.id'));
        $this->assertDatabaseCount('taxpayer_system_registrations', 1);
    }

    public function test_a_national_supervisor_can_approve_a_draft_registration(): void
    {
        $ctx = $this->makeTradingParty('VAT-TS-0006');
        $registrationId = $this->actingAs($ctx['owner'])->postJson('/api/v1/taxpayer-systems', $this->registrationPayload([
            'vat_registration_number' => $ctx['taxpayer']->vat_number,
        ]), ['Idempotency-Key' => 'ts-'.Str::random(20)])->json('resource.id');
        $supervisor = $this->makeNamraSupervisor();

        $response = $this->actingAs($supervisor)->withFreshStepUp()
            ->postJson("/api/v1/taxpayer-systems/{$registrationId}/approval", [], ['Idempotency-Key' => 'app-'.Str::random(20)]);

        $response->assertStatus(200)->assertJsonPath('resource.registration_status', 'APPROVED');
    }

    public function test_approving_a_registration_without_a_fresh_step_up_is_locked(): void
    {
        $ctx = $this->makeTradingParty('VAT-TS-0007');
        $registrationId = $this->actingAs($ctx['owner'])->postJson('/api/v1/taxpayer-systems', $this->registrationPayload([
            'vat_registration_number' => $ctx['taxpayer']->vat_number,
        ]), ['Idempotency-Key' => 'ts-'.Str::random(20)])->json('resource.id');
        $supervisor = $this->makeNamraSupervisor();

        $response = $this->actingAs($supervisor)->postJson("/api/v1/taxpayer-systems/{$registrationId}/approval", [], ['Idempotency-Key' => 'app-'.Str::random(20)]);

        $response->assertStatus(423);
        $this->assertDatabaseHas('taxpayer_system_registrations', ['id' => $registrationId, 'registration_status' => 'DRAFT']);
    }

    public function test_a_taxpayer_owner_cannot_approve_their_own_registration(): void
    {
        $ctx = $this->makeTradingParty('VAT-TS-0008');
        $registrationId = $this->actingAs($ctx['owner'])->postJson('/api/v1/taxpayer-systems', $this->registrationPayload([
            'vat_registration_number' => $ctx['taxpayer']->vat_number,
        ]), ['Idempotency-Key' => 'ts-'.Str::random(20)])->json('resource.id');

        $response = $this->actingAs($ctx['owner'])->withFreshStepUp()
            ->postJson("/api/v1/taxpayer-systems/{$registrationId}/approval", [], ['Idempotency-Key' => 'app-'.Str::random(20)]);

        $response->assertStatus(403);
    }

    public function test_approving_an_already_approved_registration_is_a_validation_error(): void
    {
        $ctx = $this->makeTradingParty('VAT-TS-0009');
        $registrationId = $this->actingAs($ctx['owner'])->postJson('/api/v1/taxpayer-systems', $this->registrationPayload([
            'vat_registration_number' => $ctx['taxpayer']->vat_number,
        ]), ['Idempotency-Key' => 'ts-'.Str::random(20)])->json('resource.id');
        $supervisor = $this->makeNamraSupervisor();
        $this->actingAs($supervisor)->withFreshStepUp()
            ->postJson("/api/v1/taxpayer-systems/{$registrationId}/approval", [], ['Idempotency-Key' => 'app1-'.Str::random(20)])->assertStatus(200);

        $response = $this->actingAs($supervisor)->withFreshStepUp()
            ->postJson("/api/v1/taxpayer-systems/{$registrationId}/approval", [], ['Idempotency-Key' => 'app2-'.Str::random(20)]);

        $response->assertStatus(422);
    }

    public function test_the_owning_taxpayer_can_suspend_their_own_approved_registration_with_a_reason(): void
    {
        $ctx = $this->makeTradingParty('VAT-TS-0010');
        $registrationId = $this->actingAs($ctx['owner'])->postJson('/api/v1/taxpayer-systems', $this->registrationPayload([
            'vat_registration_number' => $ctx['taxpayer']->vat_number,
        ]), ['Idempotency-Key' => 'ts-'.Str::random(20)])->json('resource.id');
        $supervisor = $this->makeNamraSupervisor();
        $this->actingAs($supervisor)->withFreshStepUp()
            ->postJson("/api/v1/taxpayer-systems/{$registrationId}/approval", [], ['Idempotency-Key' => 'app-'.Str::random(20)])->assertStatus(200);

        $response = $this->actingAs($ctx['owner'])->postJson("/api/v1/taxpayer-systems/{$registrationId}/suspension", [
            'schema_version' => '1.0.0', 'reason' => 'Decommissioning this system.',
        ], ['Idempotency-Key' => 'susp-'.Str::random(20)]);

        $response->assertStatus(200)->assertJsonPath('resource.registration_status', 'SUSPENDED');
    }

    public function test_another_taxpayers_organisation_cannot_manage_a_registration_outside_its_scope(): void
    {
        $ctx = $this->makeTradingParty('VAT-TS-0011');
        $other = $this->makeTradingParty('VAT-TS-0012');
        $registrationId = $this->actingAs($ctx['owner'])->postJson('/api/v1/taxpayer-systems', $this->registrationPayload([
            'vat_registration_number' => $ctx['taxpayer']->vat_number,
        ]), ['Idempotency-Key' => 'ts-'.Str::random(20)])->json('resource.id');

        $response = $this->actingAs($other['owner'])->postJson("/api/v1/taxpayer-systems/{$registrationId}/suspension", [
            'schema_version' => '1.0.0', 'reason' => 'Trying to suspend someone elses system.',
        ], ['Idempotency-Key' => 'susp-'.Str::random(20)]);

        $response->assertStatus(403);
    }

    public function test_recording_synchronization_is_refused_before_the_registration_is_approved(): void
    {
        $ctx = $this->makeTradingParty('VAT-TS-0013');
        $registrationId = $this->actingAs($ctx['owner'])->postJson('/api/v1/taxpayer-systems', $this->registrationPayload([
            'vat_registration_number' => $ctx['taxpayer']->vat_number,
        ]), ['Idempotency-Key' => 'ts-'.Str::random(20)])->json('resource.id');

        $response = $this->actingAs($ctx['owner'])->postJson("/api/v1/taxpayer-systems/{$registrationId}/sync", [
            'schema_version' => '1.0.0', 'api_status' => 'CONNECTED',
        ], ['Idempotency-Key' => 'sync-'.Str::random(20)]);

        $response->assertStatus(409);
    }

    public function test_recording_synchronization_on_an_approved_registration_updates_api_status_and_timestamp(): void
    {
        $ctx = $this->makeTradingParty('VAT-TS-0014');
        $registrationId = $this->actingAs($ctx['owner'])->postJson('/api/v1/taxpayer-systems', $this->registrationPayload([
            'vat_registration_number' => $ctx['taxpayer']->vat_number,
        ]), ['Idempotency-Key' => 'ts-'.Str::random(20)])->json('resource.id');
        $supervisor = $this->makeNamraSupervisor();
        $this->actingAs($supervisor)->withFreshStepUp()
            ->postJson("/api/v1/taxpayer-systems/{$registrationId}/approval", [], ['Idempotency-Key' => 'app-'.Str::random(20)])->assertStatus(200);

        $response = $this->actingAs($ctx['owner'])->postJson("/api/v1/taxpayer-systems/{$registrationId}/sync", [
            'schema_version' => '1.0.0', 'api_status' => 'CONNECTED',
        ], ['Idempotency-Key' => 'sync-'.Str::random(20)]);

        $response->assertStatus(200)->assertJsonPath('resource.api_status', 'CONNECTED');
        $this->assertNotNull(TaxpayerSystemRegistration::find($registrationId)->last_synchronization_at);
    }

    public function test_listing_scopes_a_tenant_actor_to_their_own_organisations_registrations(): void
    {
        $ctx = $this->makeTradingParty('VAT-TS-0015');
        $other = $this->makeTradingParty('VAT-TS-0016');
        $this->actingAs($ctx['owner'])->postJson('/api/v1/taxpayer-systems', $this->registrationPayload([
            'vat_registration_number' => $ctx['taxpayer']->vat_number,
        ]), ['Idempotency-Key' => 'ts-'.Str::random(20)])->assertStatus(201);
        $this->actingAs($other['owner'])->postJson('/api/v1/taxpayer-systems', $this->registrationPayload([
            'vat_registration_number' => $other['taxpayer']->vat_number,
        ]), ['Idempotency-Key' => 'ts-'.Str::random(20)])->assertStatus(201);

        $response = $this->actingAs($ctx['owner'])->getJson('/api/v1/taxpayer-systems');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('resources'));
    }

    public function test_listing_shows_every_registration_to_a_national_scope_actor(): void
    {
        $ctx = $this->makeTradingParty('VAT-TS-0017');
        $other = $this->makeTradingParty('VAT-TS-0018');
        $this->actingAs($ctx['owner'])->postJson('/api/v1/taxpayer-systems', $this->registrationPayload([
            'vat_registration_number' => $ctx['taxpayer']->vat_number,
        ]), ['Idempotency-Key' => 'ts-'.Str::random(20)])->assertStatus(201);
        $this->actingAs($other['owner'])->postJson('/api/v1/taxpayer-systems', $this->registrationPayload([
            'vat_registration_number' => $other['taxpayer']->vat_number,
        ]), ['Idempotency-Key' => 'ts-'.Str::random(20)])->assertStatus(201);

        $response = $this->actingAs($this->makeNamraSupervisor())->getJson('/api/v1/taxpayer-systems');

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(2, count($response->json('resources')));
    }

    public function test_the_blade_view_requires_authentication(): void
    {
        $this->get('/taxpayer-systems')->assertRedirect('/login');
    }

    public function test_the_blade_view_renders_the_register_form_and_a_registered_system(): void
    {
        $ctx = $this->makeTradingParty('VAT-TS-0019');
        $this->actingAs($ctx['owner'])->postJson('/api/v1/taxpayer-systems', $this->registrationPayload([
            'vat_registration_number' => $ctx['taxpayer']->vat_number,
        ]), ['Idempotency-Key' => 'ts-'.Str::random(20)])->assertStatus(201);

        $response = $this->actingAs($ctx['owner'])->get('/taxpayer-systems');

        $response->assertOk()->assertViewIs('taxpayer-systems.index');
        $response->assertSee('Register a system');
        $response->assertSee('Acme ERP');
    }

    public function test_registering_through_the_blade_view_creates_a_draft_registration(): void
    {
        $ctx = $this->makeTradingParty('VAT-TS-0020');

        $response = $this->actingAs($ctx['owner'])->post('/taxpayer-systems', [
            'vat_registration_number' => $ctx['taxpayer']->vat_number, 'system_name' => 'Blade POS',
            'system_vendor' => 'Blade Vendor', 'system_category' => 'POS',
        ]);

        $response->assertRedirect(route('taxpayer-systems.index'));
        $this->assertDatabaseHas('taxpayer_system_registrations', ['system_name' => 'Blade POS', 'registration_status' => 'DRAFT']);
    }

    public function test_approving_through_the_blade_view_without_a_fresh_step_up_redirects_to_password_confirmation(): void
    {
        $ctx = $this->makeTradingParty('VAT-TS-0021');
        $registrationId = $this->actingAs($ctx['owner'])->postJson('/api/v1/taxpayer-systems', $this->registrationPayload([
            'vat_registration_number' => $ctx['taxpayer']->vat_number,
        ]), ['Idempotency-Key' => 'ts-'.Str::random(20)])->json('resource.id');
        $supervisor = $this->makeNamraSupervisor();

        $response = $this->actingAs($supervisor)->post("/taxpayer-systems/{$registrationId}/approval");

        $response->assertRedirect(route('security.mfa', ['redirect_to' => url('/')]));
    }
}
