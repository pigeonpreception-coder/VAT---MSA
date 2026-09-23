<?php

namespace Tests\Feature\Business;

use App\Models\BusinessParty;
use App\Models\Organisation;
use App\Models\PartyRelationship;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers Module 5 Phase E's LinkExpenseReceipt
 * (App\Services\Business\ExpenseService::linkReceipt(), ported from
 * lib/data/business-repository.ts's linkExpenseReceipt) -- the gap
 * App\Http\Controllers\Business\OperationsViewController's own doc
 * comment named ("receipt handling stays read-only") until now.
 */
class ExpenseReceiptLinkTest extends TestCase
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
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => strtolower($vatNumber).'@rcpttest.test',
        ]);
        $organisation = Organisation::create([
            'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE',
        ]);
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@rcpttest.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);
        $accountant = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Accountant", 'email' => strtolower($vatNumber).'-accountant@rcpttest.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_ACCOUNTANT', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner', 'accountant');
    }

    private function systemAdmin(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA System Admin', 'email' => 'sysadmin-'.Str::random(8).'@rcpttest.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_SYSTEM_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function minimalPdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj<< /Type /Catalog >>endobj\ntrailer<< /Root 1 0 R >>\n%%EOF";
    }

    private function fakeUpload(string $name = 'receipt.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, $this->minimalPdfBytes());

        return new UploadedFile($path, $name, 'application/pdf', null, true);
    }

    private function createCategory(User $owner, string $code = 'TRAVEL'): string
    {
        return $this->actingAs($owner)->postJson('/api/v1/expenses/categories', [
            'schema_version' => '1.0.0', 'code' => $code, 'name' => "Category {$code}", 'default_tax_category' => 'STANDARD', 'requires_receipt' => true,
        ], ['Idempotency-Key' => 'test-idem-cat-'.$code.'-'.Str::random(6)])->json('resource.id');
    }

    /** A tax-bearing expense requires a trusted, active supplier (TAXED_EXPENSE_SUPPLIER_REQUIRED). */
    private function createSupplier(Organisation $organisation, string $displayName = 'Receipt Test Supplier'): string
    {
        $party = BusinessParty::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'display_name' => $displayName,
            'source_system' => 'test', 'source_party_id' => Str::random(8), 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        PartyRelationship::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'party_id' => $party->id,
            'relationship' => 'SUPPLIER', 'status' => 'ACTIVE', 'effective_from' => now(), 'created_at' => now(),
        ]);

        return $party->id;
    }

    private function createExpense(User $owner, Organisation $organisation, string $categoryId, string $expenseNumber = 'EXP-RCPT-0001'): string
    {
        return $this->actingAs($owner)->postJson('/api/v1/expenses', [
            'schema_version' => '1.0.0', 'category_id' => $categoryId, 'supplier_party_id' => $this->createSupplier($organisation), 'expense_number' => $expenseNumber,
            'expense_date' => '2026-09-01', 'description' => 'Client travel expense', 'currency' => 'NAD',
            'net_cents' => 100000, 'tax_cents' => 15000, 'total_cents' => 115000,
        ], ['Idempotency-Key' => 'test-idem-exp-create-'.Str::random(6)])->json('resource.id');
    }

    /** Uploads a document owned by the given expense and, by default, scans it clean. @return string document id */
    private function uploadReceipt(User $owner, string $expenseId, bool $scanClean = true): string
    {
        $documentId = $this->actingAs($owner)->post('/api/v1/documents', [
            'owner_domain' => 'EXPENSE', 'owner_resource_id' => $expenseId, 'classification' => 'TAX_CONFIDENTIAL',
            'file' => $this->fakeUpload(),
        ], ['Idempotency-Key' => 'test-idem-upload-'.Str::random(8)])->json('document.id');

        if ($scanClean) {
            $this->actingAs($this->systemAdmin())->postJson("/api/v1/documents/{$documentId}/scan-result", [
                'schema_version' => '1.0.0', 'outcome' => 'CLEAN',
            ], ['Idempotency-Key' => 'test-idem-scan-'.Str::random(8)]);
        }

        return $documentId;
    }

    public function test_a_clean_receipt_can_be_linked_to_a_draft_expense(): void
    {
        $org = $this->makeOrganisation('VAT-RCPT-0001');
        $categoryId = $this->createCategory($org['owner']);
        $expenseId = $this->createExpense($org['owner'], $org['organisation'], $categoryId);
        $documentId = $this->uploadReceipt($org['owner'], $expenseId);

        $response = $this->actingAs($org['owner'])->postJson("/api/v1/expenses/{$expenseId}/receipt", [
            'schema_version' => '1.0.0', 'receipt_document_id' => $documentId,
        ], ['Idempotency-Key' => 'test-idem-link-0001']);

        $response->assertStatus(200)->assertJsonPath('resource.receipt_document_id', $documentId);
        $this->assertDatabaseHas('expenses', ['id' => $expenseId, 'receipt_document_id' => $documentId]);
        $this->assertDatabaseHas('expense_receipt_links', ['expense_id' => $expenseId, 'document_id' => $documentId]);
        $this->assertDatabaseHas('audit_events', ['action' => 'EXPENSE_RECEIPT_LINKED', 'resource_id' => $expenseId]);
    }

    public function test_linking_a_receipt_still_pending_scan_is_rejected(): void
    {
        $org = $this->makeOrganisation('VAT-RCPT-0002');
        $categoryId = $this->createCategory($org['owner']);
        $expenseId = $this->createExpense($org['owner'], $org['organisation'], $categoryId);
        $documentId = $this->uploadReceipt($org['owner'], $expenseId, scanClean: false);

        $response = $this->actingAs($org['owner'])->postJson("/api/v1/expenses/{$expenseId}/receipt", [
            'schema_version' => '1.0.0', 'receipt_document_id' => $documentId,
        ], ['Idempotency-Key' => 'test-idem-link-0002']);

        $response->assertStatus(409);
        $this->assertDatabaseHas('expenses', ['id' => $expenseId, 'receipt_document_id' => null]);
    }

    public function test_linking_a_receipt_scanned_infected_is_rejected(): void
    {
        $org = $this->makeOrganisation('VAT-RCPT-0003');
        $categoryId = $this->createCategory($org['owner']);
        $expenseId = $this->createExpense($org['owner'], $org['organisation'], $categoryId);
        $documentId = $this->uploadReceipt($org['owner'], $expenseId, scanClean: false);
        $this->actingAs($this->systemAdmin())->postJson("/api/v1/documents/{$documentId}/scan-result", [
            'schema_version' => '1.0.0', 'outcome' => 'INFECTED',
        ], ['Idempotency-Key' => 'test-idem-scan-infected-0001']);

        $response = $this->actingAs($org['owner'])->postJson("/api/v1/expenses/{$expenseId}/receipt", [
            'schema_version' => '1.0.0', 'receipt_document_id' => $documentId,
        ], ['Idempotency-Key' => 'test-idem-link-0003']);

        $response->assertStatus(409);
    }

    public function test_linking_to_a_non_draft_expense_is_rejected(): void
    {
        $org = $this->makeOrganisation('VAT-RCPT-0004');
        $categoryId = $this->createCategory($org['owner']);
        $expenseId = $this->createExpense($org['owner'], $org['organisation'], $categoryId);
        $documentId = $this->uploadReceipt($org['owner'], $expenseId);
        $this->actingAs($org['owner'])->postJson("/api/v1/expenses/{$expenseId}/submission", [], ['Idempotency-Key' => 'test-idem-submit-0001']);

        $response = $this->actingAs($org['owner'])->postJson("/api/v1/expenses/{$expenseId}/receipt", [
            'schema_version' => '1.0.0', 'receipt_document_id' => $documentId,
        ], ['Idempotency-Key' => 'test-idem-link-0004']);

        $response->assertStatus(409);
    }

    public function test_linking_a_second_receipt_once_one_is_already_linked_is_rejected(): void
    {
        $org = $this->makeOrganisation('VAT-RCPT-0005');
        $categoryId = $this->createCategory($org['owner']);
        $expenseId = $this->createExpense($org['owner'], $org['organisation'], $categoryId);
        $firstDocumentId = $this->uploadReceipt($org['owner'], $expenseId);
        $this->actingAs($org['owner'])->postJson("/api/v1/expenses/{$expenseId}/receipt", [
            'schema_version' => '1.0.0', 'receipt_document_id' => $firstDocumentId,
        ], ['Idempotency-Key' => 'test-idem-link-0005a'])->assertStatus(200);
        $secondDocumentId = $this->uploadReceipt($org['owner'], $expenseId);

        $response = $this->actingAs($org['owner'])->postJson("/api/v1/expenses/{$expenseId}/receipt", [
            'schema_version' => '1.0.0', 'receipt_document_id' => $secondDocumentId,
        ], ['Idempotency-Key' => 'test-idem-link-0005b']);

        $response->assertStatus(409);
        $this->assertSame(1, DB::table('expense_receipt_links')->where('expense_id', $expenseId)->count());
    }

    public function test_linking_a_document_belonging_to_a_different_expense_is_not_found(): void
    {
        $org = $this->makeOrganisation('VAT-RCPT-0006');
        $categoryId = $this->createCategory($org['owner']);
        $expenseA = $this->createExpense($org['owner'], $org['organisation'], $categoryId, 'EXP-RCPT-A');
        $expenseB = $this->createExpense($org['owner'], $org['organisation'], $categoryId, 'EXP-RCPT-B');
        $documentForA = $this->uploadReceipt($org['owner'], $expenseA);

        $response = $this->actingAs($org['owner'])->postJson("/api/v1/expenses/{$expenseB}/receipt", [
            'schema_version' => '1.0.0', 'receipt_document_id' => $documentForA,
        ], ['Idempotency-Key' => 'test-idem-link-0006']);

        $response->assertStatus(404);
    }

    public function test_a_viewer_without_expenses_manage_is_denied(): void
    {
        $org = $this->makeOrganisation('VAT-RCPT-0007');
        $categoryId = $this->createCategory($org['owner']);
        $expenseId = $this->createExpense($org['owner'], $org['organisation'], $categoryId);
        $documentId = $this->uploadReceipt($org['owner'], $expenseId);
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Viewer', 'email' => 'viewer-rcpt@rcpttest.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_VIEWER', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($viewer)->postJson("/api/v1/expenses/{$expenseId}/receipt", [
            'schema_version' => '1.0.0', 'receipt_document_id' => $documentId,
        ], ['Idempotency-Key' => 'test-idem-link-0007']);

        $response->assertStatus(403);
    }

    public function test_double_submitting_the_same_link_request_is_idempotent(): void
    {
        $org = $this->makeOrganisation('VAT-RCPT-0008');
        $categoryId = $this->createCategory($org['owner']);
        $expenseId = $this->createExpense($org['owner'], $org['organisation'], $categoryId);
        $documentId = $this->uploadReceipt($org['owner'], $expenseId);
        $key = 'test-idem-link-replay-0001';

        $this->actingAs($org['owner'])->postJson("/api/v1/expenses/{$expenseId}/receipt", [
            'schema_version' => '1.0.0', 'receipt_document_id' => $documentId,
        ], ['Idempotency-Key' => $key])->assertStatus(200);
        $replay = $this->actingAs($org['owner'])->postJson("/api/v1/expenses/{$expenseId}/receipt", [
            'schema_version' => '1.0.0', 'receipt_document_id' => $documentId,
        ], ['Idempotency-Key' => $key]);

        $replay->assertStatus(200)->assertJsonPath('resource.id', $expenseId);
        $this->assertSame(1, DB::table('expense_receipt_links')->where('expense_id', $expenseId)->count());
    }

    public function test_the_operations_view_can_link_a_receipt(): void
    {
        $org = $this->makeOrganisation('VAT-RCPT-0009');
        $categoryId = $this->createCategory($org['owner']);
        $expenseId = $this->createExpense($org['owner'], $org['organisation'], $categoryId);
        $documentId = $this->uploadReceipt($org['owner'], $expenseId);

        $response = $this->actingAs($org['owner'])->post("/operations/expenses/{$expenseId}/receipt", ['receipt_document_id' => $documentId]);

        $response->assertRedirect(route('operations.index'));
        $response->assertSessionHas('status', 'Receipt linked.');
        $this->assertDatabaseHas('expenses', ['id' => $expenseId, 'receipt_document_id' => $documentId]);
    }
}
