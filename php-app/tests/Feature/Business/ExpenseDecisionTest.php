<?php

namespace Tests\Feature\Business;

use App\Models\BusinessParty;
use App\Models\CounterpartyTrustProfile;
use App\Models\Organisation;
use App\Models\PartyRelationship;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers DecideExpense (App\Services\Business\ExpenseService::decide(),
 * ported from lib/data/business-repository.ts's decideExpense/
 * evaluateExpenseDecision) -- a gap-finding pass found this newer,
 * consolidated maker-checker decision (a single receipt-gated decision
 * straight from DRAFT) entirely unported, even though it's the flow the
 * source's own real UI (app/operations/ExpenseDecisionActions.tsx) uses.
 * The older SUBMIT->APPROVE/REJECT flow ExpenseTest.php covers remains
 * unchanged and still valid in source -- this is additive, not a
 * replacement, exactly as lib/data/business-repository.ts's own doc
 * comment on decideExpense states.
 */
class ExpenseDecisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('local');
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, owner: User, accountant: User} */
    private function makeOrganisation(string $vatNumber): array
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => strtolower($vatNumber).'@dectest.test',
        ]);
        $organisation = Organisation::create([
            'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE',
        ]);
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@dectest.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);
        $accountant = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Accountant", 'email' => strtolower($vatNumber).'-accountant@dectest.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_ACCOUNTANT', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner', 'accountant');
    }

    private function systemAdmin(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA System Admin', 'email' => 'sysadmin-'.Str::random(8).'@dectest.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_SYSTEM_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function fakeUpload(string $name = 'receipt.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, "%PDF-1.4\n1 0 obj<< /Type /Catalog >>endobj\ntrailer<< /Root 1 0 R >>\n%%EOF");

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    /** @return string category id */
    private function createCategory(User $owner, bool $requiresReceipt, string $code = 'TRAVEL'): string
    {
        return $this->actingAs($owner)->postJson('/api/v1/expenses/categories', [
            'schema_version' => '1.0.0', 'code' => $code, 'name' => "Category {$code}", 'default_tax_category' => 'STANDARD', 'requires_receipt' => $requiresReceipt,
        ], ['Idempotency-Key' => 'test-idem-cat-'.$code.'-'.Str::random(6)])->json('resource.id');
    }

    /** A tax-bearing expense requires a trusted, active supplier (TAXED_EXPENSE_SUPPLIER_REQUIRED). */
    private function createSupplier(Organisation $organisation, string $displayName = 'Decision Test Supplier'): string
    {
        $party = BusinessParty::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'display_name' => $displayName,
            'source_system' => 'test', 'source_party_id' => Str::random(8), 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        PartyRelationship::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'party_id' => $party->id,
            'relationship' => 'SUPPLIER', 'status' => 'ACTIVE', 'effective_from' => now(), 'created_at' => now(),
        ]);
        $this->trustParty($party, $organisation);

        return $party->id;
    }

    /** See tests/Feature/Business/ExpenseTest.php's own trustParty() doc comment. */
    private function trustParty(BusinessParty $party, Organisation $organisation): void
    {
        CounterpartyTrustProfile::create([
            'id' => (string) Str::uuid(), 'business_party_id' => $party->id, 'provider' => 'SYNTHETIC_AUTHORITY',
            'provider_environment' => 'SYNTHETIC_TEST', 'trust_status' => 'AUTHORITY_VERIFIED', 'tax_registration_status' => 'ACTIVE',
            'vat_verification_status' => 'NOT_PROVIDED', 'tin_verification_status' => 'NOT_PROVIDED', 'company_verification_status' => 'NOT_PROVIDED',
            'confidence_bps' => 10000, 'evidence_hash' => null, 'source_reference' => null,
            'requested_by' => User::where('taxpayer_id', $organisation->taxpayer_id)->value('id'), 'reviewed_by' => null,
            'checked_at' => now(), 'expires_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createExpense(User $owner, string $categoryId, string $supplierId, string $expenseNumber = 'EXP-DEC-0001'): string
    {
        return $this->actingAs($owner)->postJson('/api/v1/expenses', [
            'schema_version' => '1.0.0', 'category_id' => $categoryId, 'supplier_party_id' => $supplierId, 'expense_number' => $expenseNumber,
            'expense_date' => '2026-09-01', 'description' => 'Client travel expense', 'currency' => 'NAD',
            'net_cents' => 100000, 'tax_cents' => 15000, 'total_cents' => 115000,
        ], ['Idempotency-Key' => 'test-idem-exp-create-'.Str::random(6)])->json('resource.id');
    }

    public function test_deciding_an_expense_with_no_receipt_required_approves_it(): void
    {
        $org = $this->makeOrganisation('VAT-DEC-0001');
        $categoryId = $this->createCategory($org['owner'], requiresReceipt: false);
        $supplierId = $this->createSupplier($org['organisation']);
        $expenseId = $this->createExpense($org['owner'], $categoryId, $supplierId);

        $response = $this->actingAs($org['accountant'])->postJson("/api/v1/expenses/{$expenseId}/decision", [
            'schema_version' => '1.0.0', 'decision' => 'APPROVE', 'reason' => 'Totals and evidence check out.',
        ], ['Idempotency-Key' => 'test-idem-dec-approve-0001']);

        $response->assertStatus(200)->assertJsonPath('resource.status', 'APPROVED');
        $this->assertDatabaseHas('expenses', ['id' => $expenseId, 'status' => 'APPROVED', 'approved_by' => $org['accountant']->id]);
        $this->assertDatabaseHas('expense_decisions', ['expense_id' => $expenseId, 'decision' => 'APPROVE', 'reason' => 'Totals and evidence check out.', 'decided_by' => $org['accountant']->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'EXPENSE_APPROVED', 'resource_id' => $expenseId]);
    }

    public function test_rejecting_an_expense_clears_any_approver_fields_and_does_not_touch_the_legacy_rejection_reason_column(): void
    {
        $org = $this->makeOrganisation('VAT-DEC-0002');
        $categoryId = $this->createCategory($org['owner'], requiresReceipt: false);
        $supplierId = $this->createSupplier($org['organisation']);
        $expenseId = $this->createExpense($org['owner'], $categoryId, $supplierId);

        $response = $this->actingAs($org['accountant'])->postJson("/api/v1/expenses/{$expenseId}/decision", [
            'schema_version' => '1.0.0', 'decision' => 'REJECT', 'reason' => 'Amount looks inflated versus the receipt.',
        ], ['Idempotency-Key' => 'test-idem-dec-reject-0001']);

        $response->assertStatus(200)->assertJsonPath('resource.status', 'REJECTED');
        // Mirrors drizzle/0010_curvy_zaran.sql's apply_expense_decision
        // trigger exactly: approved_by/approved_at are cleared on REJECT,
        // and expenses.rejection_reason (the older REJECT_EXPENSE flow's
        // own column) is never touched -- the decision's reason lives only
        // in expense_decisions.reason.
        $this->assertDatabaseHas('expenses', ['id' => $expenseId, 'status' => 'REJECTED', 'approved_by' => null, 'approved_at' => null, 'rejection_reason' => null]);
        $this->assertDatabaseHas('expense_decisions', ['expense_id' => $expenseId, 'decision' => 'REJECT', 'reason' => 'Amount looks inflated versus the receipt.']);
    }

    public function test_the_creator_cannot_decide_their_own_expense(): void
    {
        $org = $this->makeOrganisation('VAT-DEC-0003');
        $categoryId = $this->createCategory($org['owner'], requiresReceipt: false);
        $supplierId = $this->createSupplier($org['organisation']);
        $expenseId = $this->createExpense($org['owner'], $categoryId, $supplierId);

        $response = $this->actingAs($org['owner'])->postJson("/api/v1/expenses/{$expenseId}/decision", [
            'schema_version' => '1.0.0', 'decision' => 'APPROVE', 'reason' => 'Self-approving my own claim.',
        ], ['Idempotency-Key' => 'test-idem-dec-self-0001']);

        $response->assertStatus(403);
        $this->assertDatabaseHas('expenses', ['id' => $expenseId, 'status' => 'DRAFT']);
    }

    public function test_deciding_an_already_decided_expense_is_a_conflict(): void
    {
        $org = $this->makeOrganisation('VAT-DEC-0004');
        $categoryId = $this->createCategory($org['owner'], requiresReceipt: false);
        $supplierId = $this->createSupplier($org['organisation']);
        $expenseId = $this->createExpense($org['owner'], $categoryId, $supplierId);
        $this->actingAs($org['accountant'])->postJson("/api/v1/expenses/{$expenseId}/decision", [
            'schema_version' => '1.0.0', 'decision' => 'APPROVE', 'reason' => 'First decision.',
        ], ['Idempotency-Key' => 'test-idem-dec-first-0001'])->assertStatus(200);

        $response = $this->actingAs($org['accountant'])->postJson("/api/v1/expenses/{$expenseId}/decision", [
            'schema_version' => '1.0.0', 'decision' => 'REJECT', 'reason' => 'Trying to decide it again.',
        ], ['Idempotency-Key' => 'test-idem-dec-second-0001']);

        $response->assertStatus(409);
        $this->assertDatabaseHas('expenses', ['id' => $expenseId, 'status' => 'APPROVED']);
    }

    public function test_approving_a_receipt_required_expense_with_no_clean_receipt_is_blocked(): void
    {
        $org = $this->makeOrganisation('VAT-DEC-0005');
        $categoryId = $this->createCategory($org['owner'], requiresReceipt: true);
        $supplierId = $this->createSupplier($org['organisation']);
        $expenseId = $this->createExpense($org['owner'], $categoryId, $supplierId);

        $response = $this->actingAs($org['accountant'])->postJson("/api/v1/expenses/{$expenseId}/decision", [
            'schema_version' => '1.0.0', 'decision' => 'APPROVE', 'reason' => 'Approving without evidence.',
        ], ['Idempotency-Key' => 'test-idem-dec-noreceipt-0001']);

        $response->assertStatus(409);
        $this->assertDatabaseHas('expenses', ['id' => $expenseId, 'status' => 'DRAFT']);
    }

    public function test_rejecting_a_receipt_required_expense_with_no_receipt_is_not_blocked(): void
    {
        $org = $this->makeOrganisation('VAT-DEC-0006');
        $categoryId = $this->createCategory($org['owner'], requiresReceipt: true);
        $supplierId = $this->createSupplier($org['organisation']);
        $expenseId = $this->createExpense($org['owner'], $categoryId, $supplierId);

        $response = $this->actingAs($org['accountant'])->postJson("/api/v1/expenses/{$expenseId}/decision", [
            'schema_version' => '1.0.0', 'decision' => 'REJECT', 'reason' => 'Rejecting; no receipt evidence provided.',
        ], ['Idempotency-Key' => 'test-idem-dec-rejectnoreceipt-0001']);

        $response->assertStatus(200)->assertJsonPath('resource.status', 'REJECTED');
    }

    public function test_approving_a_receipt_required_expense_with_a_clean_linked_receipt_succeeds(): void
    {
        $org = $this->makeOrganisation('VAT-DEC-0007');
        $categoryId = $this->createCategory($org['owner'], requiresReceipt: true);
        $supplierId = $this->createSupplier($org['organisation']);
        $expenseId = $this->createExpense($org['owner'], $categoryId, $supplierId);
        $documentId = $this->actingAs($org['owner'])->post('/api/v1/documents', [
            'owner_domain' => 'EXPENSE', 'owner_resource_id' => $expenseId, 'classification' => 'TAX_CONFIDENTIAL',
            'file' => $this->fakeUpload(),
        ], ['Idempotency-Key' => 'test-idem-dec-upload-0001'])->json('document.id');
        $this->actingAs($this->systemAdmin())->postJson("/api/v1/documents/{$documentId}/scan-result", [
            'schema_version' => '1.0.0', 'outcome' => 'CLEAN',
        ], ['Idempotency-Key' => 'test-idem-dec-scan-0001']);
        $this->actingAs($org['owner'])->postJson("/api/v1/expenses/{$expenseId}/receipt", [
            'schema_version' => '1.0.0', 'receipt_document_id' => $documentId,
        ], ['Idempotency-Key' => 'test-idem-dec-link-0001'])->assertStatus(200);

        $response = $this->actingAs($org['accountant'])->postJson("/api/v1/expenses/{$expenseId}/decision", [
            'schema_version' => '1.0.0', 'decision' => 'APPROVE', 'reason' => 'Receipt is clean and available.',
        ], ['Idempotency-Key' => 'test-idem-dec-approve-with-receipt-0001']);

        $response->assertStatus(200)->assertJsonPath('resource.status', 'APPROVED');
    }

    public function test_an_invalid_decision_value_is_rejected(): void
    {
        $org = $this->makeOrganisation('VAT-DEC-0008');
        $categoryId = $this->createCategory($org['owner'], requiresReceipt: false);
        $supplierId = $this->createSupplier($org['organisation']);
        $expenseId = $this->createExpense($org['owner'], $categoryId, $supplierId);

        $response = $this->actingAs($org['accountant'])->postJson("/api/v1/expenses/{$expenseId}/decision", [
            'schema_version' => '1.0.0', 'decision' => 'MAYBE', 'reason' => 'Not sure about this one.',
        ], ['Idempotency-Key' => 'test-idem-dec-invalid-0001']);

        $response->assertStatus(422)->assertJsonPath('errors.0.code', 'DECISION_INVALID');
    }

    public function test_an_emergency_override_field_is_rejected_not_silently_ignored(): void
    {
        $org = $this->makeOrganisation('VAT-DEC-0009');
        $categoryId = $this->createCategory($org['owner'], requiresReceipt: true);
        $supplierId = $this->createSupplier($org['organisation']);
        $expenseId = $this->createExpense($org['owner'], $categoryId, $supplierId);

        $response = $this->actingAs($org['accountant'])->postJson("/api/v1/expenses/{$expenseId}/decision", [
            'schema_version' => '1.0.0', 'decision' => 'APPROVE', 'reason' => 'Bypassing the receipt gate.', 'emergency_override' => true,
        ], ['Idempotency-Key' => 'test-idem-dec-override-0001']);

        $response->assertStatus(422)->assertJsonPath('errors.0.code', 'EMERGENCY_OVERRIDE_UNSUPPORTED');
        $this->assertDatabaseHas('expenses', ['id' => $expenseId, 'status' => 'DRAFT']);
    }
}
