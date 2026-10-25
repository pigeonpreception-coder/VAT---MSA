<?php

namespace Tests\Feature\Compliance;

use App\Models\CommunicationThread;
use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Covers the real Blade UI for CommunicationService -- RT-007 (the
 * self-directed red-team assessment, see docs/MIGRATION_MATRIX.md):
 * App\Http\Controllers\Compliance\CommunicationViewController /
 * resources/views/communications/{index,show}.blade.php. Fixture helpers
 * mirror tests/Feature/Compliance/CommunicationAndNotificationTest.php's
 * own conventions exactly (the JSON-API coverage for the same service).
 */
class CommunicationViewTest extends TestCase
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

    // dashboard:read only -- no compliance:read at all (Permissions::ROLE_PERMISSIONS).
    private function staffWithoutComplianceRead(Taxpayer $taxpayer): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Warehouse Staff', 'email' => 'staff-'.Str::random(8).'@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_STAFF', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
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

    private function sendNotice(User $officer, string $caseId, string $subject = 'Request for supporting documentation'): TestResponse
    {
        return $this->actingAs($officer)->post(route('communications.notices.store'), [
            'related_resource_type' => 'AUDIT_CASE', 'related_resource_id' => $caseId, 'channel' => 'PORTAL',
            'subject' => $subject, 'content_summary' => 'Please submit the invoices supporting the disputed period within 14 days.',
            'classification' => 'TAX_CONFIDENTIAL',
        ]);
    }

    private function threadIdFor(string $caseId): string
    {
        return CommunicationThread::where('related_resource_id', $caseId)->value('id');
    }

    public function test_the_index_page_requires_authentication(): void
    {
        $this->get('/communications')->assertRedirect('/login');
    }

    public function test_a_role_without_compliance_read_is_forbidden(): void
    {
        $tp = $this->makeTaxpayer('VAT-COMMVIEW-0001');
        $staff = $this->staffWithoutComplianceRead($tp['taxpayer']);

        $this->actingAs($staff)->get('/communications')->assertForbidden();
    }

    public function test_sending_a_notice_through_the_form_opens_a_thread_and_redirects_to_it(): void
    {
        $tp = $this->makeTaxpayer('VAT-COMMVIEW-0002');
        $officer = $this->complianceOfficer('officer-0002@namra.test');
        $caseId = $this->openCase($officer, $tp['taxpayer']->id);

        $response = $this->sendNotice($officer, $caseId);

        $threadId = $this->threadIdFor($caseId);
        $response->assertRedirect(route('communications.show', $threadId));
        $this->assertDatabaseHas('communication_threads', ['id' => $threadId, 'status' => 'OPEN', 'taxpayer_id' => $tp['taxpayer']->id]);
        $this->assertDatabaseHas('communications', ['thread_id' => $threadId, 'direction' => 'OUTBOUND']);
    }

    public function test_a_duplicate_notice_for_the_same_case_is_rejected_with_a_form_error(): void
    {
        $tp = $this->makeTaxpayer('VAT-COMMVIEW-0003');
        $officer = $this->complianceOfficer('officer-0003@namra.test');
        $caseId = $this->openCase($officer, $tp['taxpayer']->id);
        $this->sendNotice($officer, $caseId);

        $response = $this->sendNotice($officer, $caseId, 'Second notice attempt');

        $response->assertRedirect();
        $response->assertSessionHasErrors('form');
        $this->assertSame(1, CommunicationThread::where('related_resource_id', $caseId)->count());
    }

    public function test_a_role_without_communications_manage_cannot_send_a_notice(): void
    {
        $tp = $this->makeTaxpayer('VAT-COMMVIEW-0004');
        $officer = $this->complianceOfficer('officer-0004@namra.test');
        $caseId = $this->openCase($officer, $tp['taxpayer']->id);

        $this->actingAs($tp['owner'])->post(route('communications.notices.store'), [
            'related_resource_type' => 'AUDIT_CASE', 'related_resource_id' => $caseId, 'channel' => 'PORTAL',
            'subject' => 'A taxpayer attempting to send a notice', 'content_summary' => 'This should never be permitted by this role.',
            'classification' => 'TAX_CONFIDENTIAL',
        ])->assertForbidden();
    }

    public function test_the_threads_own_taxpayer_can_respond_while_open(): void
    {
        $tp = $this->makeTaxpayer('VAT-COMMVIEW-0005');
        $officer = $this->complianceOfficer('officer-0005@namra.test');
        $caseId = $this->openCase($officer, $tp['taxpayer']->id);
        $this->sendNotice($officer, $caseId);
        $threadId = $this->threadIdFor($caseId);

        $response = $this->actingAs($tp['owner'])->post(route('communications.responses.store', $threadId), [
            'channel' => 'PORTAL', 'content_summary' => 'Here is the supporting documentation you requested.',
        ]);

        $response->assertRedirect(route('communications.show', $threadId));
        $this->assertDatabaseHas('communications', ['thread_id' => $threadId, 'direction' => 'INBOUND', 'content_summary' => 'Here is the supporting documentation you requested.']);
    }

    public function test_responding_after_closure_is_rejected_with_a_form_error(): void
    {
        $tp = $this->makeTaxpayer('VAT-COMMVIEW-0006');
        $officer = $this->complianceOfficer('officer-0006@namra.test');
        $caseId = $this->openCase($officer, $tp['taxpayer']->id);
        $this->sendNotice($officer, $caseId);
        $threadId = $this->threadIdFor($caseId);
        $this->actingAs($officer)->post(route('communications.closure.store', $threadId), ['reason' => 'Matter resolved after documentation review.']);

        $response = $this->actingAs($tp['owner'])->post(route('communications.responses.store', $threadId), [
            'channel' => 'PORTAL', 'content_summary' => 'This reply should be rejected since the thread is closed.',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('form');
        $this->assertDatabaseMissing('communications', ['thread_id' => $threadId, 'content_summary' => 'This reply should be rejected since the thread is closed.']);
    }

    public function test_a_different_taxpayer_cannot_view_or_respond_to_anothers_thread(): void
    {
        $tp = $this->makeTaxpayer('VAT-COMMVIEW-0007');
        $other = $this->makeTaxpayer('VAT-COMMVIEW-0007B');
        $officer = $this->complianceOfficer('officer-0007@namra.test');
        $caseId = $this->openCase($officer, $tp['taxpayer']->id);
        $this->sendNotice($officer, $caseId);
        $threadId = $this->threadIdFor($caseId);

        $this->actingAs($other['owner'])->get(route('communications.show', $threadId))->assertForbidden();
        $this->actingAs($other['owner'])->post(route('communications.responses.store', $threadId), [
            'channel' => 'PORTAL', 'content_summary' => 'An out-of-scope taxpayer attempting to reply.',
        ])->assertForbidden();
    }

    public function test_closing_a_thread_through_the_form(): void
    {
        $tp = $this->makeTaxpayer('VAT-COMMVIEW-0008');
        $officer = $this->complianceOfficer('officer-0008@namra.test');
        $caseId = $this->openCase($officer, $tp['taxpayer']->id);
        $this->sendNotice($officer, $caseId);
        $threadId = $this->threadIdFor($caseId);

        $response = $this->actingAs($officer)->post(route('communications.closure.store', $threadId), [
            'reason' => 'Taxpayer provided satisfactory documentation; no further action required.',
        ]);

        $response->assertRedirect(route('communications.show', $threadId));
        $this->assertDatabaseHas('communication_threads', ['id' => $threadId, 'status' => 'CLOSED', 'closed_by' => $officer->id]);
    }

    public function test_a_taxpayer_cannot_close_a_thread(): void
    {
        $tp = $this->makeTaxpayer('VAT-COMMVIEW-0009');
        $officer = $this->complianceOfficer('officer-0009@namra.test');
        $caseId = $this->openCase($officer, $tp['taxpayer']->id);
        $this->sendNotice($officer, $caseId);
        $threadId = $this->threadIdFor($caseId);

        $this->actingAs($tp['owner'])->post(route('communications.closure.store', $threadId), [
            'reason' => 'A taxpayer attempting to close their own thread.',
        ])->assertForbidden();
    }

    public function test_the_show_page_hides_manage_gated_controls_from_a_respond_only_holder(): void
    {
        $tp = $this->makeTaxpayer('VAT-COMMVIEW-0010');
        $officer = $this->complianceOfficer('officer-0010@namra.test');
        $caseId = $this->openCase($officer, $tp['taxpayer']->id);
        $this->sendNotice($officer, $caseId);
        $threadId = $this->threadIdFor($caseId);

        $response = $this->actingAs($tp['owner'])->get(route('communications.show', $threadId));

        $response->assertOk()->assertViewIs('communications.show');
        $response->assertSee('Respond');
        $response->assertDontSee('Close thread');
    }

    public function test_the_index_page_hides_the_send_notice_card_from_a_respond_only_holder(): void
    {
        $tp = $this->makeTaxpayer('VAT-COMMVIEW-0011');

        $response = $this->actingAs($tp['owner'])->get('/communications');

        $response->assertOk()->assertViewIs('communications.index');
        $response->assertDontSee('Send a notice');
    }

    public function test_the_overview_page_links_to_communications_instead_of_rendering_them_inline(): void
    {
        $tp = $this->makeTaxpayer('VAT-COMMVIEW-0012');
        $officer = $this->complianceOfficer('officer-0012@namra.test');
        $caseId = $this->openCase($officer, $tp['taxpayer']->id);
        $this->sendNotice($officer, $caseId);

        $response = $this->actingAs($officer)->get('/compliance-overview');

        $response->assertOk();
        // 'comm-item' was the removed inline-preview card's own per-thread
        // CSS hook (resources/views/compliance/overview.blade.php, pre
        // RT-007) -- its absence is the precise regression check. The
        // notice's own subject is NOT a safe thing to assert absent here:
        // CommunicationService::sendNotice() also raises a NOTICE_RECEIVED
        // notification carrying that same subject as its title, and the
        // Notifications card (untouched by this fix) still renders that
        // inline, legitimately.
        $response->assertDontSee('comm-item');
        $response->assertSee(route('communications.index'), false);
    }
}
