<?php

namespace Tests\Feature\Security;

use App\Exceptions\RateLimitExceededException;
use App\Models\SecurityIncident;
use App\Models\User;
use App\Support\Security\SecurityEventRecorder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SecurityDetectionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers App\Support\Security\SecurityEventRecorder -- ported from
 * lib/security/request.ts's recordSecurityEvent/evaluateDetectionRules/
 * recordAuthorizationDenial/recordRateLimitBreach -- and the central
 * bootstrap/app.php AccessDeniedHttpException handler that now calls
 * recordAuthorizationDenial on every 403 in the app. Real MySQL, no mocks.
 */
class SecurityEventRecorderTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_recording_an_authorisation_denial_writes_a_security_event(): void
    {
        SecurityEventRecorder::recordAuthorizationDenial('actor-0001', 'src:test', (string) Str::uuid(), 'invoices:submit', 403);

        $this->assertDatabaseHas('security_events', [
            'event_type' => 'AUTHORISATION_DENIED', 'severity' => 'HIGH', 'actor_id' => 'actor-0001',
            'action' => 'invoices:submit', 'outcome' => 'DENIED',
        ]);
    }

    public function test_recording_a_rate_limit_breach_writes_a_security_event_with_the_exceptions_own_code(): void
    {
        $exception = new RateLimitExceededException('Too many requests.', 'RATE_LIMIT_EXCEEDED', 60);
        SecurityEventRecorder::recordRateLimitBreach('actor-0002', 'src:test', (string) Str::uuid(), $exception, 'INVOICE_RATE_LIMIT');

        $this->assertDatabaseHas('security_events', [
            'event_type' => 'RATE_LIMIT_EXCEEDED', 'severity' => 'MEDIUM', 'actor_id' => 'actor-0002',
            'action' => 'INVOICE_RATE_LIMIT', 'outcome' => 'REJECTED',
        ]);
    }

    public function test_five_repeated_authorisation_denials_from_the_same_actor_auto_open_an_incident(): void
    {
        $this->seed(SecurityDetectionRuleSeeder::class);
        // security_incidents.subject_user_id carries a real FK to users
        // (matching db/runtime.ts's own `subject_user_id TEXT REFERENCES
        // app_users(id)`), and evaluateDetectionRules sets it from the
        // REPEATED_AUTHORISATION_DENIALS rule's own actor-id group key --
        // exactly like production, where that group key always comes from
        // a real authenticated UserContext.userId, this needs a genuine
        // users row, not an arbitrary string.
        $actor = $this->makeUser('repeated-denials-actor@security.test', 'TAXPAYER_STAFF');

        for ($i = 0; $i < 5; $i++) {
            SecurityEventRecorder::recordAuthorizationDenial($actor->id, 'src:test', (string) Str::uuid(), 'invoices:submit', 403);
        }

        $this->assertDatabaseCount('security_incidents', 1);
        $incident = SecurityIncident::first();
        $this->assertSame('OPEN', $incident->status);
        $this->assertSame('secrule-repeated-denials', $incident->detection_rule_id);
        $this->assertSame($actor->id, $incident->group_key);
        $this->assertDatabaseHas('security_playbook_actions', [
            'incident_id' => $incident->id, 'action_type' => 'DETECTED', 'automated' => 1,
        ]);
        $this->assertDatabaseHas('outbox_events', ['aggregate_id' => $incident->id, 'event_type' => 'SecurityIncidentDetected']);
    }

    public function test_a_sixth_denial_does_not_open_a_second_incident_while_one_is_already_open(): void
    {
        $this->seed(SecurityDetectionRuleSeeder::class);
        $actor = $this->makeUser('dedup-actor@security.test', 'TAXPAYER_STAFF');

        for ($i = 0; $i < 6; $i++) {
            SecurityEventRecorder::recordAuthorizationDenial($actor->id, 'src:test', (string) Str::uuid(), 'invoices:submit', 403);
        }

        $this->assertDatabaseCount('security_incidents', 1);
    }

    public function test_a_403_response_anywhere_in_the_app_records_an_authorisation_denial_event(): void
    {
        // Central bootstrap/app.php AccessDeniedHttpException handler --
        // reuses the same choke point RT-002 already caught every
        // AuthorizationException at, so a denial anywhere in the app (not
        // just the Security Operations view) is covered without touching
        // every individual controller's own $this->authorize() call site.
        $developer = $this->makeUser('no-security-read@security.test', 'DEVELOPER_PARTNER');

        $this->actingAs($developer)->get('/security')->assertForbidden();

        $this->assertDatabaseHas('security_events', [
            'event_type' => 'AUTHORISATION_DENIED', 'actor_id' => $developer->id, 'action' => 'security:read',
        ]);
    }
}
