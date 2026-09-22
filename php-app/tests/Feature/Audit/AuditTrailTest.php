<?php

namespace Tests\Feature\Audit;

use App\Models\AuditChainVerification;
use App\Models\AuditEvent;
use App\Models\SecurityIncident;
use App\Models\User;
use App\Services\Audit\AuditService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SecurityDetectionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers Module 8 Phase D's GetAuditTrail/VerifyAuditChain
 * (App\Services\Audit\AuditService::searchTrail/verifyChain/
 * runChainVerification/listChainVerifications), both its Blade surface
 * (App\Http\Controllers\Audit\AuditTrailViewController) and its JSON API
 * mirror (App\Http\Controllers\Audit\AuditTrailController).
 *
 * Also regression-covers a real defect this same change fixed:
 * audit_events.occurred_at was a whole-second TIMESTAMP while the hash
 * formula embeds microsecond precision, so re-deriving any row's hash
 * from what was actually persisted could never match -- see the
 * 2026-09-22 migration's own doc comment. A clean-chain PASSED result
 * here is only possible because that fix is in place.
 */
class AuditTrailTest extends TestCase
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

    private function auditor(): User
    {
        return $this->makeUser('auditor-'.Str::random(8).'@audittrail.test', 'NAMRA_VAT_AUDITOR');
    }

    public function test_the_audit_trail_page_and_json_routes_require_authentication(): void
    {
        $this->get('/audit-trail')->assertRedirect('/login');
        $this->getJson('/api/v1/audit/trail')->assertUnauthorized();
    }

    public function test_a_role_without_audit_read_is_denied(): void
    {
        $partner = $this->makeUser('partner@audittrail.test', 'DEVELOPER_PARTNER');

        $this->actingAs($partner)->get('/audit-trail')->assertForbidden();
        $this->actingAs($partner)->getJson('/api/v1/audit/trail')->assertForbidden();
        $this->actingAs($partner)->postJson('/api/v1/audit/chain-verifications')->assertForbidden();
    }

    public function test_the_page_renders_and_filters_the_trail_by_resource_type(): void
    {
        $auditor = $this->auditor();
        AuditService::append($auditor, 'INVOICE_CERTIFIED', 'INVOICE', 'inv-0001', ['note' => 'first']);
        AuditService::append($auditor, 'QUOTATION_ISSUED', 'QUOTATION', 'quo-0001', ['note' => 'second']);

        $response = $this->actingAs($auditor)->get('/audit-trail?resource_type=INVOICE');

        $response->assertOk()->assertViewIs('audit-trail.index');
        $response->assertSee('INVOICE_CERTIFIED');
        $response->assertDontSee('QUOTATION_ISSUED');
        $this->assertSame(1, $response->viewData('totalCount'));
    }

    public function test_the_json_search_endpoint_filters_by_action_and_actor(): void
    {
        $auditor = $this->auditor();
        $other = $this->auditor();
        AuditService::append($auditor, 'REFUND_APPROVED', 'REFUND', 'ref-0001', []);
        AuditService::append($other, 'REFUND_APPROVED', 'REFUND', 'ref-0002', []);
        AuditService::append($auditor, 'REFUND_REJECTED', 'REFUND', 'ref-0003', []);

        $response = $this->actingAs($auditor)->getJson('/api/v1/audit/trail?action=REFUND_APPROVED&actor_id='.$auditor->id);

        $response->assertOk();
        $response->assertJsonCount(1, 'items');
        $response->assertJsonPath('items.0.resource_id', 'ref-0001');
    }

    public function test_chain_verification_passes_for_a_clean_chain(): void
    {
        $auditor = $this->auditor();
        AuditService::append($auditor, 'INVOICE_CERTIFIED', 'INVOICE', 'inv-clean-1', ['a' => 1]);
        AuditService::append($auditor, 'INVOICE_CERTIFIED', 'INVOICE', 'inv-clean-2', ['a' => 2]);
        AuditService::append($auditor, 'INVOICE_CERTIFIED', 'INVOICE', 'inv-clean-3', ['a' => 3]);

        $response = $this->actingAs($auditor)->post('/audit-trail/verification');

        $response->assertRedirect('/audit-trail');
        $response->assertSessionHas('status');
        $verification = AuditChainVerification::firstOrFail();
        $this->assertSame('PASSED', $verification->status);
        $this->assertSame(3, $verification->verified_count);
        $this->assertNull($verification->first_break_id);
        $this->assertDatabaseCount('security_incidents', 0);
    }

    public function test_chain_verification_detects_a_tampered_event_and_opens_a_critical_incident(): void
    {
        $this->seed(SecurityDetectionRuleSeeder::class);
        $auditor = $this->auditor();
        AuditService::append($auditor, 'INVOICE_CERTIFIED', 'INVOICE', 'inv-tamper-1', ['a' => 1]);
        $tampered = AuditService::append($auditor, 'INVOICE_CERTIFIED', 'INVOICE', 'inv-tamper-2', ['a' => 2]);
        AuditService::append($auditor, 'INVOICE_CERTIFIED', 'INVOICE', 'inv-tamper-3', ['a' => 3]);

        // Simulate genuine tampering -- a direct row edit bypassing
        // AuditService entirely, exactly the scenario this feature exists
        // to catch (mirrors ReconciliationTest's own direct ledger-entry
        // corruption precedent).
        DB::table('audit_events')->where('id', $tampered->id)->update(['details' => '{"a":999}']);

        $response = $this->actingAs($auditor)->post('/audit-trail/verification');

        $response->assertRedirect('/audit-trail');
        $response->assertSessionHas('chainBreak');
        $verification = AuditChainVerification::firstOrFail();
        $this->assertSame('FAILED', $verification->status);
        $this->assertSame($tampered->id, $verification->first_break_id);
        $this->assertSame('EVENT_HASH_MISMATCH', $verification->first_break_reason);
        $this->assertSame(1, $verification->verified_count);

        $this->assertDatabaseCount('security_incidents', 1);
        $incident = SecurityIncident::firstOrFail();
        $this->assertSame('secrule-audit-chain-breach', $incident->detection_rule_id);
        $this->assertSame('CRITICAL', $incident->severity);
        $this->assertSame('OPEN', $incident->status);
    }

    public function test_a_previous_hash_mismatch_is_also_detected(): void
    {
        $auditor = $this->auditor();
        AuditService::append($auditor, 'INVOICE_CERTIFIED', 'INVOICE', 'inv-link-1', ['a' => 1]);
        $second = AuditService::append($auditor, 'INVOICE_CERTIFIED', 'INVOICE', 'inv-link-2', ['a' => 2]);

        DB::table('audit_events')->where('id', $second->id)->update(['previous_hash' => 'not-the-real-prior-hash']);

        $response = $this->actingAs($auditor)->post('/audit-trail/verification');

        $verification = AuditChainVerification::firstOrFail();
        $this->assertSame('FAILED', $verification->status);
        $this->assertSame($second->id, $verification->first_break_id);
        $this->assertSame('PREVIOUS_HASH_MISMATCH', $verification->first_break_reason);
    }

    public function test_a_second_verification_after_the_first_does_not_reopen_a_duplicate_incident(): void
    {
        $this->seed(SecurityDetectionRuleSeeder::class);
        $auditor = $this->auditor();
        $tampered = AuditService::append($auditor, 'INVOICE_CERTIFIED', 'INVOICE', 'inv-dedup-1', ['a' => 1]);
        DB::table('audit_events')->where('id', $tampered->id)->update(['details' => '{"a":999}']);

        $this->actingAs($auditor)->post('/audit-trail/verification');
        $this->actingAs($auditor)->post('/audit-trail/verification');

        $this->assertDatabaseCount('audit_chain_verifications', 2);
        $this->assertDatabaseCount('security_incidents', 1);
    }

    public function test_the_page_shows_verification_history(): void
    {
        $auditor = $this->auditor();
        AuditService::append($auditor, 'INVOICE_CERTIFIED', 'INVOICE', 'inv-hist-1', []);
        $this->actingAs($auditor)->post('/audit-trail/verification');

        $response = $this->actingAs($auditor)->get('/audit-trail');

        $response->assertOk();
        $response->assertSee('Passed');
    }

    public function test_the_json_api_can_list_and_trigger_chain_verifications(): void
    {
        $auditor = $this->auditor();
        AuditService::append($auditor, 'INVOICE_CERTIFIED', 'INVOICE', 'inv-json-1', []);

        $trigger = $this->actingAs($auditor)->postJson('/api/v1/audit/chain-verifications');
        $trigger->assertStatus(201)->assertJsonPath('verification.status', 'PASSED');

        $list = $this->actingAs($auditor)->getJson('/api/v1/audit/chain-verifications');
        $list->assertOk()->assertJsonCount(1, 'verifications');
    }
}
