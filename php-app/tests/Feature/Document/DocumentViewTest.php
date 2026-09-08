<?php

namespace Tests\Feature\Document;

use App\Models\DocumentMetadata;
use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for the evidence documents register
 * (App\Http\Controllers\Document\DocumentViewController /
 * resources/views/documents/index.blade.php) -- ported from the source's
 * own app/documents/page.tsx + DocumentUploadForm.tsx. Reuses
 * App\Services\Document\DocumentService::upload directly (already covered
 * end to end, including its MIME/size/magic-byte checks, by
 * tests/Feature/Document/DocumentTest.php), so this file's own job is the
 * access gate, the view's own rendering, and the real multipart upload
 * form reached through this UI.
 */
class DocumentViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('local');
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, owner: User} */
    private function makeOrganisation(string $vatNumber): array
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
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@docview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    private function fakeUpload(string $content, string $declaredMime, string $name = 'evidence.pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, $declaredMime, null, true);
    }

    private function minimalPdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj<< /Type /Catalog >>endobj\ntrailer<< /Root 1 0 R >>\n%%EOF";
    }

    public function test_the_documents_page_requires_authentication(): void
    {
        $this->get('/documents')->assertRedirect('/login');
    }

    public function test_a_role_without_documents_read_is_denied(): void
    {
        $org = $this->makeOrganisation('VAT-DENY-0001');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Seller Viewer', 'email' => 'viewer@docview.test',
            'password' => bcrypt('password'), 'role' => 'SELLER_VIEWER', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($viewer)->get('/documents')->assertForbidden();
    }

    public function test_the_documents_page_renders_the_register_and_upload_form(): void
    {
        $org = $this->makeOrganisation('VAT-SELLER-0001');
        DocumentMetadata::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $org['organisation']->id, 'owner_domain' => 'EXPENSE',
            'owner_resource_id' => 'exp-0001', 'object_key' => 'quarantine/test/key', 'file_name' => 'receipt.pdf',
            'content_type' => 'application/pdf', 'size_bytes' => 128, 'checksum_sha256' => str_repeat('a', 64),
            'classification' => 'TAX_CONFIDENTIAL', 'scan_status' => 'CLEAN', 'status' => 'ACTIVE',
            'uploaded_by' => $org['owner']->id, 'uploaded_at' => now(), 'legal_hold' => false,
        ]);

        $response = $this->actingAs($org['owner'])->get('/documents');

        $response->assertOk()->assertViewIs('documents.index');
        $response->assertSee('Evidence custody');
        $response->assertSee('receipt.pdf');
        $response->assertSee('Add evidence');
        $response->assertSee('Upload to quarantine');
        $response->assertSee('<caption class="visually-hidden">', false);
        $response->assertSee('scope="col"', false);
    }

    public function test_a_valid_pdf_can_be_uploaded_to_quarantine(): void
    {
        $org = $this->makeOrganisation('VAT-SELLER-0002');

        $response = $this->actingAs($org['owner'])->post('/documents', [
            'owner_domain' => 'EXPENSE', 'owner_resource_id' => 'exp-0002', 'classification' => 'TAX_CONFIDENTIAL',
            'file' => $this->fakeUpload($this->minimalPdfBytes(), 'application/pdf'),
        ]);

        $response->assertRedirect('/documents');
        $response->assertSessionHas('status');
        $this->assertDatabaseHas('document_metadata', [
            'organisation_id' => $org['organisation']->id, 'owner_domain' => 'EXPENSE', 'owner_resource_id' => 'exp-0002',
            'status' => 'QUARANTINED', 'scan_status' => 'PENDING_EXTERNAL_SCANNER',
        ]);
    }

    public function test_a_role_without_documents_upload_cannot_upload(): void
    {
        $org = $this->makeOrganisation('VAT-SELLER-0003');
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Viewer', 'email' => 'tpviewer@docview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_VIEWER', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($viewer)->post('/documents', [
            'owner_domain' => 'EXPENSE', 'owner_resource_id' => 'exp-0003', 'classification' => 'TAX_CONFIDENTIAL',
            'file' => $this->fakeUpload($this->minimalPdfBytes(), 'application/pdf'),
        ])->assertForbidden();
    }

    public function test_a_file_whose_content_does_not_match_its_declared_type_is_rejected(): void
    {
        $org = $this->makeOrganisation('VAT-SELLER-0004');

        $response = $this->actingAs($org['owner'])->post('/documents', [
            'owner_domain' => 'EXPENSE', 'owner_resource_id' => 'exp-0004', 'classification' => 'TAX_CONFIDENTIAL',
            'file' => $this->fakeUpload('not actually a pdf', 'application/pdf'),
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('file');
        $this->assertDatabaseMissing('document_metadata', ['owner_resource_id' => 'exp-0004']);
    }

    public function test_the_upload_form_is_prefilled_from_the_expense_receipt_query_string(): void
    {
        $org = $this->makeOrganisation('VAT-SELLER-0005');

        $response = $this->actingAs($org['owner'])->get('/documents?owner_domain=EXPENSE&owner_resource_id=exp-0005');

        $response->assertOk();
        $response->assertViewHas('defaultOwnerDomain', 'EXPENSE');
        $response->assertViewHas('defaultOwnerResourceId', 'exp-0005');
    }
}
