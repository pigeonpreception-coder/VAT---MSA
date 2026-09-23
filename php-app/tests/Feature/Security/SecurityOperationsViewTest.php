<?php

namespace Tests\Feature\Security;

use App\Models\IdentityLink;
use App\Models\IdentityProvider;
use App\Models\SecurityEvent;
use App\Models\SecurityIncident;
use App\Models\User;
use Database\Seeders\IdentityProviderSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithStepUp;
use Tests\TestCase;

/**
 * Covers the real Blade UI for Module 8 Phase B's Security Operations
 * Centre queue (App\Http\Controllers\Security\SecurityOperationsViewController /
 * resources/views/security/operations.blade.php), ported from
 * lib/data/security-repository.ts's getSOCQueue/createIncident/
 * containIncident/revokeIncidentAccess/closeIncident. Fills
 * NavigationSeeder's own pre-existing nav-security item, which had no
 * route behind it until now.
 */
class SecurityOperationsViewTest extends TestCase
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

    public function test_the_security_operations_page_requires_authentication(): void
    {
        $this->get('/security')->assertRedirect('/login');
    }

    public function test_a_role_without_security_read_is_denied(): void
    {
        $developer = $this->makeUser('no-read@secops.test', 'DEVELOPER_PARTNER');

        $this->actingAs($developer)->get('/security')->assertForbidden();
    }

    public function test_the_page_renders_the_incident_queue(): void
    {
        $this->makeIncident();
        // No stock role in this migration's own STATIC_ROLE_PERMISSIONS
        // holds security:read without also holding security:manage (see
        // app/Support/Access/Permissions.php) -- unlike AdministrationView-
        // Controller/WorkflowAuthoringViewController, whose read-only
        // TAXPAYER_ACCOUNTANT precedent this would otherwise follow, there
        // is no built-in role to exercise the manage-actions-hidden branch
        // with, so this only covers the page rendering the queue itself.
        $viewer = $this->makeUser('viewer@secops.test', 'SECURITY_ANALYST');

        $response = $this->actingAs($viewer)->get('/security');

        $response->assertOk()->assertViewIs('security.operations');
        $response->assertSee('Security operations');
        $response->assertSee('Suspicious activity');
    }

    /**
     * Gap-finding pass (2026-09-23): lib/data/repository.ts's
     * getSecurityOperationsSnapshot -- the dashboard-metrics half of this
     * page app/security/page.tsx actually renders (open incidents,
     * high/critical events, pending outbox, data integrity) -- had no
     * Laravel counterpart at all; the port only ever built the incident
     * queue and recent-events list.
     */
    public function test_the_page_renders_its_four_metric_tiles(): void
    {
        $this->makeIncident('OPEN');
        $this->makeIncident('CLOSED');
        SecurityEvent::create([
            'id' => (string) Str::uuid(), 'event_type' => 'AUTHORISATION_DENIED', 'severity' => 'CRITICAL', 'actor_id' => null,
            'source_token' => 'src:test', 'correlation_id' => (string) Str::uuid(), 'action' => 'invoices:submit',
            'outcome' => 'DENIED', 'details' => '{}', 'occurred_at' => now(),
        ]);
        SecurityEvent::create([
            'id' => (string) Str::uuid(), 'event_type' => 'RATE_LIMIT_EXCEEDED', 'severity' => 'LOW', 'actor_id' => null,
            'source_token' => 'src:test', 'correlation_id' => (string) Str::uuid(), 'action' => 'invoices:submit',
            'outcome' => 'DENIED', 'details' => '{}', 'occurred_at' => now(),
        ]);
        DB::table('outbox_events')->insert([
            'id' => (string) Str::uuid(), 'aggregate_type' => 'SECURITY_INCIDENT', 'aggregate_id' => (string) Str::uuid(),
            'event_type' => 'SecurityIncidentOpened', 'event_version' => 1, 'partition_key' => (string) Str::uuid(),
            'payload' => '{}', 'status' => 'PENDING', 'publish_attempts' => 0, 'occurred_at' => now(), 'available_at' => now(),
        ]);
        $viewer = $this->makeUser('metrics@secops.test', 'SECURITY_ANALYST');

        $response = $this->actingAs($viewer)->get('/security');

        $response->assertOk();
        // Only the OPEN incident counts -- CLOSED does not.
        $response->assertSeeInOrder(['Open incidents', '1']);
        // Only the CRITICAL event counts toward high/critical -- LOW does not.
        $response->assertSeeInOrder(['High / critical events', '1']);
        $response->assertSeeInOrder(['Pending outbox', '1']);
        $response->assertSee('Data integrity');
        $response->assertSee('audit events');
    }

    public function test_opening_an_incident_requires_security_manage(): void
    {
        $analyst = $this->makeUser('viewer-only@secops.test', 'INTERNAL_AUDITOR');

        // withFreshStepUp() clears the route's own 'step-up' middleware so
        // this exercises the controller's $this->authorize() gate
        // specifically, not the step-up redirect the next test covers.
        $this->actingAs($analyst)->withFreshStepUp()->post('/security/incidents', [
            'title' => 'Manual finding', 'severity' => 'MEDIUM', 'details' => 'Observed from the SOC dashboard.',
        ])->assertForbidden();
    }

    public function test_opening_an_incident_without_a_fresh_step_up_redirects_to_password_confirmation(): void
    {
        $manager = $this->makeUser('manager@secops.test', 'SECURITY_ANALYST');

        $response = $this->actingAs($manager)->post('/security/incidents', [
            'title' => 'Manual finding', 'severity' => 'MEDIUM', 'details' => 'Observed from the SOC dashboard.',
        ]);

        $response->assertRedirect(route('security.mfa', ['redirect_to' => url('/')]));
        $this->assertDatabaseCount('security_incidents', 0);
    }

    public function test_opening_an_incident_with_a_fresh_step_up_creates_it_open(): void
    {
        $manager = $this->makeUser('manager2@secops.test', 'SECURITY_ANALYST');

        $response = $this->actingAs($manager)->withFreshStepUp()->post('/security/incidents', [
            'title' => 'Manual finding', 'severity' => 'MEDIUM', 'details' => 'Observed from the SOC dashboard.',
        ]);

        $response->assertRedirect(route('security.operations'));
        $this->assertDatabaseHas('security_incidents', ['title' => 'Manual finding', 'severity' => 'MEDIUM', 'status' => 'OPEN']);
        $incident = SecurityIncident::where('title', 'Manual finding')->firstOrFail();
        $this->assertDatabaseHas('security_playbook_actions', ['incident_id' => $incident->id, 'action_type' => 'OPENED', 'automated' => 0]);
    }

    public function test_containing_an_open_incident_moves_it_to_contained(): void
    {
        $manager = $this->makeUser('manager3@secops.test', 'SECURITY_ANALYST');
        $incident = $this->makeIncident('OPEN');

        $response = $this->actingAs($manager)->withFreshStepUp()
            ->post("/security/incidents/{$incident->id}/containment", ['notes' => 'Triaged and contained.']);

        $response->assertRedirect(route('security.operations'));
        $this->assertDatabaseHas('security_incidents', ['id' => $incident->id, 'status' => 'CONTAINED']);
    }

    public function test_containing_an_already_contained_incident_is_rejected(): void
    {
        $manager = $this->makeUser('manager4@secops.test', 'SECURITY_ANALYST');
        $incident = $this->makeIncident('CONTAINED');

        $response = $this->actingAs($manager)->withFreshStepUp()
            ->post("/security/incidents/{$incident->id}/containment", ['notes' => 'Already handled.']);

        $response->assertRedirect(route('security.operations'))->assertSessionHasErrors('contain');
        $this->assertDatabaseHas('security_incidents', ['id' => $incident->id, 'status' => 'CONTAINED']);
    }

    public function test_revoking_access_revokes_the_subject_users_active_identity_links_and_advances_open_to_contained(): void
    {
        $this->seed(IdentityProviderSeeder::class);
        $manager = $this->makeUser('manager5@secops.test', 'SECURITY_ANALYST');
        $subject = $this->makeUser('compromised@secops.test', 'TAXPAYER_STAFF');
        $provider = IdentityProvider::first();
        $link = IdentityLink::create([
            'id' => (string) Str::uuid(), 'user_id' => $subject->id, 'provider_id' => $provider->id,
            'subject' => 'compromised-subject', 'email_at_link' => $subject->email, 'assurance_level' => 'PLATFORM_AUTHENTICATED',
            'status' => 'ACTIVE', 'linked_at' => now(), 'last_authenticated_at' => now(),
        ]);
        $incident = $this->makeIncident('OPEN', $subject->id);

        $response = $this->actingAs($manager)->withFreshStepUp()
            ->post("/security/incidents/{$incident->id}/access-revocation", ['notes' => 'Revoking compromised session.']);

        $response->assertRedirect(route('security.operations'));
        $this->assertDatabaseHas('identity_links', ['id' => $link->id, 'status' => 'REVOKED']);
        $this->assertDatabaseHas('security_incidents', ['id' => $incident->id, 'status' => 'CONTAINED']);
        $this->assertDatabaseHas('security_playbook_actions', ['incident_id' => $incident->id, 'action_type' => 'REVOKE']);
    }

    public function test_closing_an_incident_records_the_resolution_and_closer(): void
    {
        $manager = $this->makeUser('manager6@secops.test', 'SECURITY_ANALYST');
        $incident = $this->makeIncident('CONTAINED');

        $response = $this->actingAs($manager)->withFreshStepUp()
            ->post("/security/incidents/{$incident->id}/closure", ['resolution_notes' => 'Confirmed false positive after review.']);

        $response->assertRedirect(route('security.operations'));
        $this->assertDatabaseHas('security_incidents', [
            'id' => $incident->id, 'status' => 'CLOSED', 'closed_by' => $manager->id,
            'resolution_notes' => 'Confirmed false positive after review.',
        ]);
    }

    public function test_closing_an_already_closed_incident_is_rejected(): void
    {
        $manager = $this->makeUser('manager7@secops.test', 'SECURITY_ANALYST');
        $incident = $this->makeIncident('CLOSED');

        $response = $this->actingAs($manager)->withFreshStepUp()
            ->post("/security/incidents/{$incident->id}/closure", ['resolution_notes' => 'Already closed, testing conflict.']);

        $response->assertSessionHasErrors('close');
    }

    public function test_recent_security_events_render_on_the_page(): void
    {
        SecurityEvent::create([
            'id' => (string) Str::uuid(), 'event_type' => 'AUTHORISATION_DENIED', 'severity' => 'HIGH', 'actor_id' => null,
            'source_token' => 'src:test', 'correlation_id' => (string) Str::uuid(), 'action' => 'invoices:submit',
            'outcome' => 'DENIED', 'details' => '{}', 'occurred_at' => now(),
        ]);
        $reader = $this->makeUser('reader@secops.test', 'SECURITY_ANALYST');

        $response = $this->actingAs($reader)->get('/security');

        $response->assertOk()->assertSee('AUTHORISATION_DENIED');
    }
}
