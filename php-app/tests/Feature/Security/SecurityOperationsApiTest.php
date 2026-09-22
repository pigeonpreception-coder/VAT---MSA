<?php

namespace Tests\Feature\Security;

use App\Models\IdentityLink;
use App\Models\IdentityProvider;
use App\Models\SecurityIncident;
use App\Models\User;
use Database\Seeders\IdentityProviderSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithStepUp;
use Tests\TestCase;

/**
 * Covers the JSON API half of Module 8 Phase B's Security Operations
 * Centre (App\Http\Controllers\Security\SecurityOperationsController),
 * ported from app/api/v1/security/incidents/{route,[id]/{route,
 * containment,revocation,closure}}/route.ts. Found via the same route-
 * level source sweep as FixedAssetApiTest/LogisticsApiTest -- see
 * SecurityOperationsController's own doc comment for why the previously-
 * documented decision to skip this surface no longer held.
 */
class SecurityOperationsApiTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithStepUp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    private function makeUser(string $email, string $role): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => $email, 'email' => $email,
            'password' => bcrypt('password'), 'role' => $role, 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function makeIncident(string $status = 'OPEN', ?string $subjectUserId = null): SecurityIncident
    {
        return SecurityIncident::create([
            'id' => (string) Str::uuid(), 'title' => 'Suspicious activity', 'severity' => 'HIGH', 'status' => $status,
            'source_event_id' => null, 'automated_action' => null, 'owner' => null, 'detection_rule_id' => null,
            'group_key' => null, 'subject_user_id' => $subjectUserId, 'opened_at' => now(), 'updated_at' => now(),
            'closed_at' => null, 'closed_by' => null, 'resolution_notes' => null,
        ]);
    }

    private function incidentPayload(array $overrides = []): array
    {
        return array_replace([
            'schema_version' => '1.0.0', 'title' => 'Manual finding', 'severity' => 'MEDIUM', 'details' => 'Observed from the SOC dashboard.',
        ], $overrides);
    }

    public function test_the_index_route_requires_authentication(): void
    {
        $this->getJson('/api/v1/security/incidents')->assertStatus(401);
    }

    public function test_a_role_without_security_read_is_denied(): void
    {
        $developer = $this->makeUser('no-read@secops-api.test', 'DEVELOPER_PARTNER');

        $this->actingAs($developer)->getJson('/api/v1/security/incidents')->assertStatus(403);
    }

    public function test_the_queue_lists_the_open_incident_and_a_single_incident_can_be_read_back(): void
    {
        $incident = $this->makeIncident();
        $viewer = $this->makeUser('viewer@secops-api.test', 'SECURITY_ANALYST');

        $index = $this->actingAs($viewer)->getJson('/api/v1/security/incidents');
        $index->assertStatus(200)->assertJsonPath('incidents.0.title', 'Suspicious activity');

        $show = $this->actingAs($viewer)->getJson("/api/v1/security/incidents/{$incident->id}");
        $show->assertStatus(200)->assertJsonPath('incident.title', 'Suspicious activity')->assertJsonPath('incident.status', 'OPEN');
    }

    public function test_opening_an_incident_requires_security_manage(): void
    {
        $analyst = $this->makeUser('viewer-only@secops-api.test', 'INTERNAL_AUDITOR');

        // withFreshStepUp() clears the route's own 'step-up' middleware so
        // this exercises the controller's authorize() gate specifically,
        // not the step-up lock the next test covers.
        $response = $this->actingAs($analyst)->withFreshStepUp()->postJson('/api/v1/security/incidents', $this->incidentPayload(), ['Idempotency-Key' => 'test-idem-sec-api-forbidden']);

        $response->assertStatus(403);
    }

    public function test_opening_an_incident_without_a_fresh_step_up_is_locked(): void
    {
        $manager = $this->makeUser('manager@secops-api.test', 'SECURITY_ANALYST');

        $response = $this->actingAs($manager)->postJson('/api/v1/security/incidents', $this->incidentPayload(), ['Idempotency-Key' => 'test-idem-sec-api-nostepup']);

        $response->assertStatus(423);
        $this->assertDatabaseCount('security_incidents', 0);
    }

    public function test_opening_an_incident_with_a_fresh_step_up_creates_it_open(): void
    {
        $manager = $this->makeUser('manager2@secops-api.test', 'SECURITY_ANALYST');

        $response = $this->actingAs($manager)->withFreshStepUp()->postJson('/api/v1/security/incidents', $this->incidentPayload(), ['Idempotency-Key' => 'test-idem-sec-api-open']);

        $response->assertStatus(201)->assertJsonPath('incident.title', 'Manual finding')->assertJsonPath('incident.status', 'OPEN');
        $this->assertDatabaseHas('security_playbook_actions', ['action_type' => 'OPENED']);
    }

    public function test_containing_an_open_incident_moves_it_to_contained(): void
    {
        $manager = $this->makeUser('manager3@secops-api.test', 'SECURITY_ANALYST');
        $incident = $this->makeIncident('OPEN');

        $response = $this->actingAs($manager)->withFreshStepUp()->postJson("/api/v1/security/incidents/{$incident->id}/containment", [
            'schema_version' => '1.0.0', 'notes' => 'Triaged and contained.',
        ], ['Idempotency-Key' => 'test-idem-sec-api-contain']);

        $response->assertStatus(200)->assertJsonPath('incident.status', 'CONTAINED');
    }

    public function test_containing_an_already_contained_incident_is_a_conflict(): void
    {
        $manager = $this->makeUser('manager4@secops-api.test', 'SECURITY_ANALYST');
        $incident = $this->makeIncident('CONTAINED');

        $response = $this->actingAs($manager)->withFreshStepUp()->postJson("/api/v1/security/incidents/{$incident->id}/containment", [
            'schema_version' => '1.0.0', 'notes' => 'Already handled.',
        ], ['Idempotency-Key' => 'test-idem-sec-api-recontain']);

        $response->assertStatus(409);
    }

    public function test_revoking_access_revokes_the_subject_users_active_identity_links_and_advances_open_to_contained(): void
    {
        $this->seed(IdentityProviderSeeder::class);
        $manager = $this->makeUser('manager5@secops-api.test', 'SECURITY_ANALYST');
        $subject = $this->makeUser('compromised@secops-api.test', 'TAXPAYER_STAFF');
        $provider = IdentityProvider::first();
        $link = IdentityLink::create([
            'id' => (string) Str::uuid(), 'user_id' => $subject->id, 'provider_id' => $provider->id,
            'subject' => 'compromised-subject', 'email_at_link' => $subject->email, 'assurance_level' => 'PLATFORM_AUTHENTICATED',
            'status' => 'ACTIVE', 'linked_at' => now(), 'last_authenticated_at' => now(),
        ]);
        $incident = $this->makeIncident('OPEN', $subject->id);

        $response = $this->actingAs($manager)->withFreshStepUp()->postJson("/api/v1/security/incidents/{$incident->id}/revocation", [
            'schema_version' => '1.0.0', 'notes' => 'Revoking compromised session.',
        ], ['Idempotency-Key' => 'test-idem-sec-api-revoke']);

        $response->assertStatus(200)->assertJsonPath('incident.status', 'CONTAINED');
        $this->assertDatabaseHas('identity_links', ['id' => $link->id, 'status' => 'REVOKED']);
        $this->assertDatabaseHas('security_playbook_actions', ['incident_id' => $incident->id, 'action_type' => 'REVOKE']);
    }

    public function test_revocation_without_a_fresh_step_up_is_locked(): void
    {
        $manager = $this->makeUser('manager5b@secops-api.test', 'SECURITY_ANALYST');
        $incident = $this->makeIncident('OPEN');

        $response = $this->actingAs($manager)->postJson("/api/v1/security/incidents/{$incident->id}/revocation", [
            'schema_version' => '1.0.0', 'notes' => 'Should be locked.',
        ], ['Idempotency-Key' => 'test-idem-sec-api-revoke-nostepup']);

        $response->assertStatus(423);
        $this->assertDatabaseHas('security_incidents', ['id' => $incident->id, 'status' => 'OPEN']);
    }

    public function test_closing_an_incident_records_the_resolution_and_closer(): void
    {
        $manager = $this->makeUser('manager6@secops-api.test', 'SECURITY_ANALYST');
        $incident = $this->makeIncident('CONTAINED');

        $response = $this->actingAs($manager)->withFreshStepUp()->postJson("/api/v1/security/incidents/{$incident->id}/closure", [
            'schema_version' => '1.0.0', 'resolution_notes' => 'Confirmed false positive after review.',
        ], ['Idempotency-Key' => 'test-idem-sec-api-close']);

        $response->assertStatus(200)->assertJsonPath('incident.status', 'CLOSED');
        $this->assertDatabaseHas('security_incidents', ['id' => $incident->id, 'closed_by' => $manager->id, 'resolution_notes' => 'Confirmed false positive after review.']);
    }

    public function test_closing_an_already_closed_incident_is_a_conflict(): void
    {
        $manager = $this->makeUser('manager7@secops-api.test', 'SECURITY_ANALYST');
        $incident = $this->makeIncident('CLOSED');

        $response = $this->actingAs($manager)->withFreshStepUp()->postJson("/api/v1/security/incidents/{$incident->id}/closure", [
            'schema_version' => '1.0.0', 'resolution_notes' => 'Already closed, testing conflict.',
        ], ['Idempotency-Key' => 'test-idem-sec-api-reclose']);

        $response->assertStatus(409);
    }

    public function test_an_unknown_incident_returns_not_found(): void
    {
        $manager = $this->makeUser('manager8@secops-api.test', 'SECURITY_ANALYST');

        $this->actingAs($manager)->getJson('/api/v1/security/incidents/'.Str::uuid())->assertStatus(404);
    }
}
