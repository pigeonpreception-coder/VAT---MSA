<?php

namespace Tests\Feature\Platform;

use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers the real Blade UI for the Reports & Analytics console
 * (App\Http\Controllers\Platform\ReportViewController /
 * resources/views/reports/index.blade.php) -- reuses
 * App\Services\Platform\ReportExportService and
 * App\Services\Platform\DataProductService directly, already covered end
 * to end (including every audience-tier guardrail and the publish
 * reconciliation gate) by tests/Feature/Platform/ReportExportTest.php and
 * DataProductTest.php. This file's own job is the access gate, the view's
 * rendering, the real form submissions reached through this UI, and the
 * data-conditional step-up redirect that is unique to this Blade
 * controller (the JSON API instead returns a plain 401/423).
 */
class ReportViewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation} */
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

        return compact('taxpayer', 'organisation');
    }

    private function pilotAdmin(string $email = 'pilot@reportview.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Pilot Admin', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'PILOT_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function namraSupervisor(string $email = 'supervisor@reportview.test'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Supervisor', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'NAMRA_SUPERVISOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    /** Holds no reports:read/reports:run permission -- the negative-permission fixture. */
    private function sellerAdmin(string $taxpayerId, string $email): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Seller Admin', 'email' => $email,
            'password' => bcrypt('password'), 'role' => 'SELLER_ADMIN', 'taxpayer_id' => $taxpayerId, 'status' => 'ACTIVE',
        ]);
    }

    private function seedDefinition(string $code, string $audience, string $classification): string
    {
        $id = (string) Str::uuid();
        DB::table('report_definitions')->insert([
            'id' => $id, 'code' => $code, 'name' => "{$code} definition", 'audience' => $audience,
            'description' => 'Test report definition.', 'classification' => $classification, 'query_version' => '1.0.0',
            'status' => 'ACTIVE', 'created_at' => now(), 'freshness_tier' => 'DAILY', 'guardrail' => 'test guardrail',
        ]);

        return $id;
    }

    public function test_the_reports_page_requires_authentication(): void
    {
        $this->get('/reports')->assertRedirect('/login');
    }

    public function test_a_role_without_reports_read_is_denied(): void
    {
        $tp = $this->makeTaxpayer('VAT-RV-0001');
        $denied = $this->sellerAdmin($tp['taxpayer']->id, 'denied@reportview.test');

        $this->actingAs($denied)->get('/reports')->assertForbidden();
    }

    public function test_the_catalogue_renders_for_a_permitted_actor(): void
    {
        $this->makeTaxpayer('VAT-RV-0002');
        $this->seedDefinition('SALES_VAT_SUMMARY', 'TAXPAYER', 'TAX_CONFIDENTIAL');

        $response = $this->actingAs($this->pilotAdmin())->get('/reports');

        $response->assertOk()->assertViewIs('reports.index');
        $response->assertSee('Report catalogue');
        $response->assertSee('SALES_VAT_SUMMARY');
        $response->assertSee('<caption class="visually-hidden">', false);
    }

    public function test_running_a_report_requires_the_reports_run_permission(): void
    {
        $this->makeTaxpayer('VAT-RV-0003');
        $this->seedDefinition('SALES_VAT_SUMMARY', 'TAXPAYER', 'TAX_CONFIDENTIAL');
        $viewerOnly = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Viewer', 'email' => 'viewer@reportview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_VIEWER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($viewerOnly)->post('/reports/SALES_VAT_SUMMARY/run')->assertForbidden();
    }

    public function test_running_a_taxpayer_report_creates_a_completed_inline_run(): void
    {
        $this->makeTaxpayer('VAT-RV-0004');
        $this->seedDefinition('SALES_VAT_SUMMARY', 'TAXPAYER', 'TAX_CONFIDENTIAL');
        $admin = $this->pilotAdmin();

        $response = $this->actingAs($admin)->post('/reports/SALES_VAT_SUMMARY/run');

        $response->assertRedirect('/reports');
        $response->assertSessionHas('status');
        $this->assertDatabaseHas('report_runs', ['status' => 'COMPLETED_INLINE', 'requested_by' => $admin->id]);
    }

    public function test_case_evidence_summary_without_a_case_id_is_refused(): void
    {
        $this->makeTaxpayer('VAT-RV-0005');
        $this->seedDefinition('CASE_EVIDENCE_SUMMARY', 'AUDITOR_LEGAL', 'RESTRICTED');
        $admin = $this->pilotAdmin();

        $response = $this->actingAs($admin)->post('/reports/CASE_EVIDENCE_SUMMARY/run');

        $response->assertRedirect('/reports');
        $response->assertSessionHasErrors('run');
        $this->assertDatabaseMissing('report_runs', ['requested_by' => $admin->id]);
    }

    public function test_a_completed_run_can_be_published(): void
    {
        $this->makeTaxpayer('VAT-RV-0006');
        $this->seedDefinition('SALES_VAT_SUMMARY', 'TAXPAYER', 'TAX_CONFIDENTIAL');
        $admin = $this->pilotAdmin();
        $this->actingAs($admin)->post('/reports/SALES_VAT_SUMMARY/run');
        $runId = DB::table('report_runs')->where('requested_by', $admin->id)->value('id');

        $response = $this->actingAs($admin)->post("/reports/runs/{$runId}/publish");

        $response->assertRedirect('/reports');
        $this->assertDatabaseHas('report_runs', ['id' => $runId, 'status' => 'PUBLISHED']);
    }

    public function test_a_non_sensitive_export_is_auto_approved_and_downloadable(): void
    {
        $this->makeTaxpayer('VAT-RV-0007');
        $this->seedDefinition('NATIONAL_VAT_AGGREGATE', 'OPEN_DATA', 'PUBLIC');
        $admin = $this->pilotAdmin();
        $this->actingAs($admin)->post('/reports/NATIONAL_VAT_AGGREGATE/run');
        $runId = DB::table('report_runs')->where('requested_by', $admin->id)->value('id');

        $response = $this->actingAs($admin)->post("/reports/runs/{$runId}/export");
        $response->assertRedirect('/reports');
        $exportId = DB::table('report_exports')->where('report_run_id', $runId)->value('id');
        $this->assertDatabaseHas('report_exports', ['id' => $exportId, 'status' => 'APPROVED']);

        $download = $this->actingAs($admin)->get("/reports/exports/{$exportId}/file");
        $download->assertOk();
        $this->assertStringStartsWith('text/csv', $download->headers->get('Content-Type'));
    }

    public function test_a_sensitive_export_without_a_fresh_step_up_redirects_to_password_confirmation(): void
    {
        $this->makeTaxpayer('VAT-RV-0008');
        $this->seedDefinition('SALES_VAT_SUMMARY', 'TAXPAYER', 'TAX_CONFIDENTIAL');
        $admin = $this->pilotAdmin();
        $this->actingAs($admin)->post('/reports/SALES_VAT_SUMMARY/run');
        $runId = DB::table('report_runs')->where('requested_by', $admin->id)->value('id');

        $response = $this->actingAs($admin)->post("/reports/runs/{$runId}/export");

        $response->assertRedirect(route('password.confirm'));
        $this->assertDatabaseMissing('report_exports', ['report_run_id' => $runId]);
    }

    public function test_a_sensitive_export_with_a_fresh_step_up_is_quarantined_pending_approval(): void
    {
        $this->makeTaxpayer('VAT-RV-0009');
        $this->seedDefinition('SALES_VAT_SUMMARY', 'TAXPAYER', 'TAX_CONFIDENTIAL');
        $admin = $this->pilotAdmin();
        $this->actingAs($admin)->post('/reports/SALES_VAT_SUMMARY/run');
        $runId = DB::table('report_runs')->where('requested_by', $admin->id)->value('id');

        $response = $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->post("/reports/runs/{$runId}/export");

        $response->assertRedirect('/reports');
        $this->assertDatabaseHas('report_exports', ['report_run_id' => $runId, 'status' => 'PENDING_APPROVAL']);
    }

    public function test_the_requester_can_cancel_their_own_pending_export(): void
    {
        $this->makeTaxpayer('VAT-RV-0010');
        $this->seedDefinition('SALES_VAT_SUMMARY', 'TAXPAYER', 'TAX_CONFIDENTIAL');
        $admin = $this->pilotAdmin();
        $this->actingAs($admin)->post('/reports/SALES_VAT_SUMMARY/run');
        $runId = DB::table('report_runs')->where('requested_by', $admin->id)->value('id');
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])->post("/reports/runs/{$runId}/export");
        $exportId = DB::table('report_exports')->where('report_run_id', $runId)->value('id');

        $response = $this->actingAs($admin)->post("/reports/exports/{$exportId}/cancel", ['reason' => 'No longer needed for this review.']);

        $response->assertRedirect('/reports');
        $this->assertDatabaseHas('report_exports', ['id' => $exportId, 'status' => 'CANCELLED']);
    }

    public function test_a_national_reviewer_can_approve_a_colleagues_pending_export_but_not_their_own(): void
    {
        $this->makeTaxpayer('VAT-RV-0011');
        $this->seedDefinition('SALES_VAT_SUMMARY', 'TAXPAYER', 'TAX_CONFIDENTIAL');
        $requester = $this->pilotAdmin('requester@reportview.test');
        $approver = $this->namraSupervisor('approver@reportview.test');
        $this->actingAs($requester)->post('/reports/SALES_VAT_SUMMARY/run');
        $runId = DB::table('report_runs')->where('requested_by', $requester->id)->value('id');
        $this->actingAs($requester)->withSession(['auth.password_confirmed_at' => time()])->post("/reports/runs/{$runId}/export");
        $exportId = DB::table('report_exports')->where('report_run_id', $runId)->value('id');

        // Self-approval refused, surfaced as a flashed error rather than a
        // state change -- matches every other maker-checker command in
        // this codebase's own Blade-controller error-handling shape.
        $selfAttempt = $this->actingAs($requester)->withSession(['auth.password_confirmed_at' => time()])->post("/reports/exports/{$exportId}/approve");
        $selfAttempt->assertRedirect('/reports');
        $selfAttempt->assertSessionHasErrors('approve');
        $this->assertDatabaseHas('report_exports', ['id' => $exportId, 'status' => 'PENDING_APPROVAL']);

        $approved = $this->actingAs($approver)->withSession(['auth.password_confirmed_at' => time()])->post("/reports/exports/{$exportId}/approve");
        $approved->assertRedirect('/reports');
        $this->assertDatabaseHas('report_exports', ['id' => $exportId, 'status' => 'APPROVED']);
    }

    public function test_running_and_publishing_an_analytics_model_snapshot(): void
    {
        $this->makeTaxpayer('VAT-RV-0012');
        $definitionId = $this->seedDefinition('SALES_VAT_SUMMARY', 'TAXPAYER', 'TAX_CONFIDENTIAL');
        $productId = (string) Str::uuid();
        DB::table('data_products')->insert([
            'id' => $productId, 'code' => 'RV_TRENDS', 'name' => 'RV trends', 'description' => 'Test product.',
            'source_report_definition_id' => $definitionId, 'status' => 'ACTIVE', 'created_at' => now(),
        ]);
        $admin = $this->pilotAdmin();
        $this->actingAs($admin)->post('/reports/SALES_VAT_SUMMARY/run');
        $runId = DB::table('report_runs')->where('requested_by', $admin->id)->value('id');
        $this->actingAs($admin)->post("/reports/runs/{$runId}/publish");

        $ranModel = $this->actingAs($admin)->post("/analytics/data-products/{$productId}/run-model", ['report_run_id' => $runId]);
        $ranModel->assertRedirect('/reports');
        $modelRunId = DB::table('analytics_model_runs')->where('data_product_id', $productId)->value('id');
        $this->assertNotNull($modelRunId);

        $published = $this->actingAs($admin)->post("/analytics/data-products/{$productId}/publish", ['model_run_id' => $modelRunId]);
        $published->assertRedirect('/reports');
        $this->assertDatabaseHas('data_product_snapshots', ['data_product_id' => $productId, 'model_run_id' => $modelRunId]);

        $page = $this->actingAs($admin)->get('/reports');
        $page->assertSee('RV_TRENDS');
    }

    public function test_running_an_analytics_model_is_restricted_to_national_roles(): void
    {
        $tp = $this->makeTaxpayer('VAT-RV-0013');
        $definitionId = $this->seedDefinition('SALES_VAT_SUMMARY', 'TAXPAYER', 'TAX_CONFIDENTIAL');
        $productId = (string) Str::uuid();
        DB::table('data_products')->insert([
            'id' => $productId, 'code' => 'RV_TRENDS2', 'name' => 'RV trends 2', 'description' => 'Test product.',
            'source_report_definition_id' => $definitionId, 'status' => 'ACTIVE', 'created_at' => now(),
        ]);
        $taxpayerUser = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Taxpayer Owner', 'email' => 'owner@reportview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $tp['taxpayer']->id, 'status' => 'ACTIVE',
        ]);
        $this->actingAs($taxpayerUser)->post('/reports/SALES_VAT_SUMMARY/run');
        $runId = DB::table('report_runs')->where('requested_by', $taxpayerUser->id)->value('id');

        $response = $this->actingAs($taxpayerUser)->post("/analytics/data-products/{$productId}/run-model", ['report_run_id' => $runId]);

        $response->assertRedirect('/reports');
        $response->assertSessionHasErrors('model');
        $this->assertDatabaseMissing('analytics_model_runs', ['data_product_id' => $productId]);
    }
}
