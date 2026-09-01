<?php

namespace Tests\Feature\Compliance;

use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers Phase 11 (slice 2): communications/conversations
 * (App\Services\Compliance\CommunicationService, ported from sendNotice/
 * respondToConversation/closeConversation/getInbox/getConversation) and
 * the standalone notification commands (App\Services\Compliance\
 * NotificationService, ported from queueNotification/cancelNotification/
 * markNotificationRead/updateNotificationPreference/getNotifications) --
 * Module 6 Phases C-D.
 */
class CommunicationAndNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, owner: User} */
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
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    // communications:manage AND cases:manage -- can open a case, send notices, and close threads.
    private function complianceOfficer(string $email = 'officer@namra.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Compliance Officer', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'NAMRA_COMPLIANCE_OFFICER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function openCase(User $officer, string $taxpayerId): string
    {
        $response = $this->actingAs($officer)->postJson('/api/v1/audit-cases', [
            'schema_version' => '1.0.0', 'taxpayer_id' => $taxpayerId, 'case_type' => 'VAT_AUDIT',
            'title' => 'Suspected under-declaration of output VAT', 'opening_reason' => 'Recurring high-value invoice risk pattern flagged by the risk engine.',
            'risk_tier' => 'HIGH',
        ], ['Idempotency-Key' => 'test-idem-comm-case-'.Str::random(8)]);

        return $response->json('resource.id');
    }

    public function test_a_notice_opens_a_thread_and_a_second_notice_for_the_same_case_is_rejected(): void
    {
        $tp = $this->makeTaxpayer('VAT-COMM-0001');
        $officer = $this->complianceOfficer();
        $caseId = $this->openCase($officer, $tp['taxpayer']->id);

        $notice = $this->actingAs($officer)->postJson('/api/v1/communications/notices', [
            'schema_version' => '1.0.0', 'related_resource_type' => 'AUDIT_CASE', 'related_resource_id' => $caseId,
            'channel' => 'PORTAL', 'subject' => 'Request for supporting documentation', 'content_summary' => 'Please submit the invoices supporting the disputed period within 14 days.',
            'classification' => 'TAX_CONFIDENTIAL',
        ], ['Idempotency-Key' => 'test-idem-notice-0001']);
        $notice->assertStatus(201)->assertJsonPath('resource.status', 'OPEN');
        $threadId = $notice->json('resource.id');
        $this->assertDatabaseHas('communications', ['thread_id' => $threadId, 'direction' => 'OUTBOUND']);
        $this->assertDatabaseHas('notifications', ['notification_type' => 'NOTICE_RECEIVED', 'taxpayer_id' => $tp['taxpayer']->id]);

        $duplicate = $this->actingAs($officer)->postJson('/api/v1/communications/notices', [
            'schema_version' => '1.0.0', 'related_resource_type' => 'AUDIT_CASE', 'related_resource_id' => $caseId,
            'channel' => 'PORTAL', 'subject' => 'Second notice attempt', 'content_summary' => 'This should be rejected since a thread already exists for this case.',
            'classification' => 'TAX_CONFIDENTIAL',
        ], ['Idempotency-Key' => 'test-idem-notice-0002']);
        $duplicate->assertStatus(409);
    }

    public function test_a_taxpayer_can_respond_within_their_own_thread_but_not_after_it_is_closed(): void
    {
        $tp = $this->makeTaxpayer('VAT-COMM-0002');
        $officer = $this->complianceOfficer();
        $caseId = $this->openCase($officer, $tp['taxpayer']->id);
        $notice = $this->actingAs($officer)->postJson('/api/v1/communications/notices', [
            'schema_version' => '1.0.0', 'related_resource_type' => 'AUDIT_CASE', 'related_resource_id' => $caseId,
            'channel' => 'EMAIL', 'subject' => 'Request for supporting documentation', 'content_summary' => 'Please respond with the requested records.',
            'classification' => 'TAX_CONFIDENTIAL',
        ], ['Idempotency-Key' => 'test-idem-notice-respond-0001']);
        $threadId = $notice->json('resource.id');

        $response = $this->actingAs($tp['owner'])->postJson("/api/v1/communications/{$threadId}/responses", [
            'schema_version' => '1.0.0', 'channel' => 'EMAIL', 'content_summary' => 'Attached are the invoices covering the disputed period.',
        ], ['Idempotency-Key' => 'test-idem-respond-0001']);
        $response->assertStatus(201)->assertJsonPath('resource.direction', 'INBOUND');
        $this->assertDatabaseHas('communications', ['thread_id' => $threadId, 'direction' => 'INBOUND', 'actor_id' => $tp['owner']->id]);

        $close = $this->actingAs($officer)->postJson("/api/v1/communications/{$threadId}/closure", ['schema_version' => '1.0.0', 'reason' => 'All requested documentation received and reviewed.'], ['Idempotency-Key' => 'test-idem-close-thread-0001']);
        $close->assertStatus(200)->assertJsonPath('resource.status', 'CLOSED');

        $afterClose = $this->actingAs($tp['owner'])->postJson("/api/v1/communications/{$threadId}/responses", [
            'schema_version' => '1.0.0', 'channel' => 'EMAIL', 'content_summary' => 'Attempting to reply after the thread was closed.',
        ], ['Idempotency-Key' => 'test-idem-respond-afterclose-0001']);
        $afterClose->assertStatus(409);
    }

    public function test_a_different_taxpayer_cannot_read_or_respond_to_another_taxpayers_thread(): void
    {
        $tp = $this->makeTaxpayer('VAT-COMM-0003');
        $other = $this->makeTaxpayer('VAT-COMM-0004');
        $officer = $this->complianceOfficer();
        $caseId = $this->openCase($officer, $tp['taxpayer']->id);
        $notice = $this->actingAs($officer)->postJson('/api/v1/communications/notices', [
            'schema_version' => '1.0.0', 'related_resource_type' => 'AUDIT_CASE', 'related_resource_id' => $caseId,
            'channel' => 'PORTAL', 'subject' => 'Request for supporting documentation', 'content_summary' => 'Please submit supporting records.',
            'classification' => 'TAX_CONFIDENTIAL',
        ], ['Idempotency-Key' => 'test-idem-notice-scope-0001']);
        $threadId = $notice->json('resource.id');

        $read = $this->actingAs($other['owner'])->getJson("/api/v1/communications/{$threadId}");
        $read->assertStatus(403);

        $respond = $this->actingAs($other['owner'])->postJson("/api/v1/communications/{$threadId}/responses", [
            'schema_version' => '1.0.0', 'channel' => 'PORTAL', 'content_summary' => 'Attempting to respond to a thread that is not mine.',
        ], ['Idempotency-Key' => 'test-idem-respond-scope-0001']);
        $respond->assertStatus(403);
    }

    public function test_the_inbox_lists_threads_with_a_latest_message_preview_and_count(): void
    {
        $tp = $this->makeTaxpayer('VAT-COMM-0005');
        $officer = $this->complianceOfficer();
        $caseId = $this->openCase($officer, $tp['taxpayer']->id);
        $notice = $this->actingAs($officer)->postJson('/api/v1/communications/notices', [
            'schema_version' => '1.0.0', 'related_resource_type' => 'AUDIT_CASE', 'related_resource_id' => $caseId,
            'channel' => 'PORTAL', 'subject' => 'Request for supporting documentation', 'content_summary' => 'Please submit supporting records.',
            'classification' => 'TAX_CONFIDENTIAL',
        ], ['Idempotency-Key' => 'test-idem-notice-inbox-0001']);
        $threadId = $notice->json('resource.id');
        $this->actingAs($tp['owner'])->postJson("/api/v1/communications/{$threadId}/responses", [
            'schema_version' => '1.0.0', 'channel' => 'PORTAL', 'content_summary' => 'The supporting records are attached.',
        ], ['Idempotency-Key' => 'test-idem-inbox-respond-0001'])->assertStatus(201);

        $inbox = $this->actingAs($officer)->getJson('/api/v1/communications?status=OPEN');

        $inbox->assertStatus(200)->assertJsonPath('total_count', 1)
            ->assertJsonPath('threads.0.message_count', 2)
            ->assertJsonPath('threads.0.latest_message', 'The supporting records are attached.');
    }

    public function test_a_notice_referencing_a_reconciliation_exception_resolves_its_taxpayer_correctly(): void
    {
        $tp = $this->makeTaxpayer('VAT-COMM-0006');
        $officer = $this->complianceOfficer();
        $invoiceId = (string) Str::uuid();
        \App\Models\Invoice::create([
            'id' => $invoiceId, 'invoice_number' => 'INV-COMM-0001', 'document_type' => 'TAX_INVOICE', 'source_system' => 'test',
            'source_document_id' => 'doc-comm-0001', 'supplier_taxpayer_id' => $tp['taxpayer']->id, 'supplier_name' => $tp['taxpayer']->legal_name,
            'supplier_vat_number' => $tp['taxpayer']->vat_number, 'customer_name' => 'Some Customer', 'issue_date' => '2026-09-01',
            'currency' => 'NAD', 'line_net_cents' => 100000, 'tax_cents' => 15000, 'total_cents' => 115000, 'status' => 'CERTIFIED',
            'risk_level' => 'LOW', 'payload_hash' => str_repeat('b', 64), 'transaction_id' => (string) Str::uuid(),
            'certificate_id' => (string) Str::uuid(), 'verification_token' => 'vfy_'.Str::random(32),
        ]);
        $exceptionId = (string) Str::uuid();
        \App\Models\ReconciliationException::create([
            'id' => $exceptionId, 'invoice_id' => $invoiceId, 'taxpayer_id' => $tp['taxpayer']->id, 'exception_type' => 'RISK_REVIEW',
            'severity' => 'MEDIUM', 'status' => 'OPEN', 'summary' => 'Flagged for manual review.', 'created_at' => now(),
        ]);

        $notice = $this->actingAs($officer)->postJson('/api/v1/communications/notices', [
            'schema_version' => '1.0.0', 'related_resource_type' => 'RECONCILIATION_EXCEPTION', 'related_resource_id' => $exceptionId,
            'channel' => 'PORTAL', 'subject' => 'Reconciliation exception under review', 'content_summary' => 'This exception is under manual review; a response may be required.',
            'classification' => 'INTERNAL',
        ], ['Idempotency-Key' => 'test-idem-notice-reconexc-0001']);

        $notice->assertStatus(201)->assertJsonPath('resource.taxpayer_id', $tp['taxpayer']->id);
    }

    public function test_a_notification_can_be_queued_cancelled_and_marked_read(): void
    {
        $tp = $this->makeTaxpayer('VAT-COMM-0007');
        $officer = $this->complianceOfficer();

        $queue = $this->actingAs($officer)->postJson('/api/v1/notifications', [
            'schema_version' => '1.0.0', 'taxpayer_id' => $tp['taxpayer']->id, 'notification_type' => 'FILING_REMINDER',
            'title' => 'VAT return due soon', 'message' => 'Your VAT return for the current period is due in 5 days.',
            'severity' => 'MEDIUM', 'channels' => ['IN_APP', 'EMAIL'],
        ], ['Idempotency-Key' => 'test-idem-queue-0001']);
        $queue->assertStatus(201)->assertJsonPath('resource.status', 'UNREAD');
        $notificationId = $queue->json('resource.id');
        $this->assertDatabaseHas('notification_deliveries', ['notification_id' => $notificationId, 'channel' => 'IN_APP']);
        $this->assertDatabaseHas('notification_deliveries', ['notification_id' => $notificationId, 'channel' => 'EMAIL']);

        // The taxpayer's own owner can see and mark it read (scoped via taxpayer_id).
        $read = $this->actingAs($tp['owner'])->postJson("/api/v1/notifications/{$notificationId}/read", [], ['Idempotency-Key' => 'test-idem-read-0001']);
        $read->assertStatus(200)->assertJsonPath('resource.status', 'READ');

        // Re-marking an already-read notification is a harmless no-op.
        $rereadResponse = $this->actingAs($tp['owner'])->postJson("/api/v1/notifications/{$notificationId}/read", [], ['Idempotency-Key' => 'test-idem-read-0002']);
        $rereadResponse->assertStatus(200)->assertJsonPath('resource.status', 'READ');

        // A read notification can no longer be cancelled (only UNREAD can).
        $cancelAfterRead = $this->actingAs($officer)->postJson("/api/v1/notifications/{$notificationId}/cancellation", ['schema_version' => '1.0.0', 'reason' => 'No longer relevant.'], ['Idempotency-Key' => 'test-idem-cancel-afterread-0001']);
        $cancelAfterRead->assertStatus(409);
    }

    public function test_a_disabled_channel_preference_is_honoured_except_for_in_app(): void
    {
        $tp = $this->makeTaxpayer('VAT-COMM-0008');
        $officer = $this->complianceOfficer();
        $recipient = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Recipient', 'email' => 'recipient@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $tp['taxpayer']->id, 'status' => 'ACTIVE',
        ]);
        $this->actingAs($recipient)->postJson('/api/v1/notifications/preferences', ['schema_version' => '1.0.0', 'channel' => 'EMAIL', 'enabled' => false], ['Idempotency-Key' => 'test-idem-pref-0001'])
            ->assertStatus(200)->assertJsonPath('resource.enabled', false);

        $queue = $this->actingAs($officer)->postJson('/api/v1/notifications', [
            'schema_version' => '1.0.0', 'user_id' => $recipient->id, 'notification_type' => 'FILING_REMINDER',
            'title' => 'VAT return due soon', 'message' => 'Your VAT return is due soon.', 'severity' => 'MEDIUM',
            'channels' => ['IN_APP', 'EMAIL', 'SMS'],
        ], ['Idempotency-Key' => 'test-idem-queue-pref-0001']);
        $queue->assertStatus(201);
        $notificationId = $queue->json('resource.id');

        $this->assertDatabaseHas('notification_deliveries', ['notification_id' => $notificationId, 'channel' => 'IN_APP']);
        $this->assertDatabaseHas('notification_deliveries', ['notification_id' => $notificationId, 'channel' => 'SMS']);
        $this->assertDatabaseMissing('notification_deliveries', ['notification_id' => $notificationId, 'channel' => 'EMAIL']);
    }

    public function test_a_taxpayer_cannot_cancel_another_taxpayers_notification(): void
    {
        $tp = $this->makeTaxpayer('VAT-COMM-0009');
        $other = $this->makeTaxpayer('VAT-COMM-0010');
        $officer = $this->complianceOfficer();
        $queue = $this->actingAs($officer)->postJson('/api/v1/notifications', [
            'schema_version' => '1.0.0', 'taxpayer_id' => $tp['taxpayer']->id, 'notification_type' => 'FILING_REMINDER',
            'title' => 'VAT return due soon', 'message' => 'Your VAT return is due soon.', 'severity' => 'MEDIUM', 'channels' => ['IN_APP'],
        ], ['Idempotency-Key' => 'test-idem-queue-scope-0001']);
        $notificationId = $queue->json('resource.id');

        $response = $this->actingAs($other['owner'])->postJson("/api/v1/notifications/{$notificationId}/cancellation", ['schema_version' => '1.0.0', 'reason' => 'Attempting to cancel a notification that is not mine.'], ['Idempotency-Key' => 'test-idem-cancel-scope-0001']);

        $response->assertStatus(403);
    }
}
