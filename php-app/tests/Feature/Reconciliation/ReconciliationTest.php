<?php

namespace Tests\Feature\Reconciliation;

use App\Models\Invoice;
use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\ReconciliationException;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\VatRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithStepUp;
use Tests\TestCase;

/**
 * Covers Module 3 Phase A/B: the reconciliation matching engine and its
 * NamRA-officer work queue (App\Services\Reconciliation\
 * ReconciliationService, ported from lib/data/reconciliation-repository.ts)
 * -- RunMatch/AssignException/ResolveException/GetWorkQueue, over both the
 * JSON API and the Blade view built alongside it. Real HTTP, real MySQL,
 * no mocks.
 */
class ReconciliationTest extends TestCase
{
    use InteractsWithStepUp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(VatRuleSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, owner: User} */
    private function makeTradingParty(string $vatNumber, array $capabilities = ['BUYER', 'SELLER']): array
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => strtolower($vatNumber).'@rtest.test',
        ]);
        $organisation = Organisation::create([
            'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE',
        ]);
        foreach ($capabilities as $capability) {
            OrganisationCapability::create([
                'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'capability' => $capability,
                'status' => 'ACTIVE', 'effective_from' => now()->subDay(), 'created_at' => now(),
            ]);
        }
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@rtest.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    private function makeNamraOfficer(string $role = 'NAMRA_VAT_AUDITOR'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'NamRA Officer '.Str::random(6), 'email' => 'officer-'.Str::random(8).'@rtest.test',
            'password' => bcrypt('password'), 'role' => $role, 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function invoicePayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'schema_version' => '1.0.0', 'invoice_number' => 'INV-REC-0001', 'document_type' => 'TAX_INVOICE',
            'source' => ['system_id' => 'erp-test', 'document_id' => 'doc-rec-0001', 'submitted_at' => '2026-09-01T09:00:00Z'],
            'supplier' => ['name' => 'Supplier Co', 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => 'VAT-REC-SUP']]],
            'customer' => ['name' => 'Customer Co', 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => 'VAT-REC-CUS']]],
            'issue_date' => '2026-09-01', 'currency' => 'NAD',
            'lines' => [['line_number' => 1, 'description' => 'Consulting services', 'quantity' => '1', 'unit_code' => 'EA', 'unit_price' => '1000.00', 'net_amount' => '1000.00', 'tax' => ['category' => 'STANDARD', 'rate' => '15.00', 'taxable_amount' => '1000.00', 'tax_amount' => '150.00']]],
            'totals' => ['line_net_amount' => '1000.00', 'tax_exclusive_amount' => '1000.00', 'tax_amount' => '150.00', 'tax_inclusive_amount' => '1150.00', 'payable_amount' => '1150.00'],
        ], $overrides);
    }

    /** @return array{supplier: array, invoiceId: string} */
    private function certifyInvoice(?string $customerVat = 'VAT-REC-CUS'): array
    {
        $supplier = $this->makeTradingParty('VAT-REC-SUP');
        if ($customerVat) {
            $this->makeTradingParty($customerVat);
        }
        $payload = $this->invoicePayload($customerVat ? [] : ['customer' => ['identifiers' => [['value' => 'VAT-REC-UNREG']]]]);
        $response = $this->actingAs($supplier['owner'])->postJson('/api/v1/invoices', $payload, ['Idempotency-Key' => 'rec-cert-'.Str::random(20)]);
        $response->assertStatus(201);

        return ['supplier' => $supplier, 'invoiceId' => $response->json('invoice_id')];
    }

    public function test_running_a_match_on_a_consistent_invoice_returns_matched(): void
    {
        $ctx = $this->certifyInvoice();
        $officer = $this->makeNamraOfficer();

        $response = $this->actingAs($officer)->withFreshStepUp()
            ->postJson("/api/v1/invoices/{$ctx['invoiceId']}/match", [], ['Idempotency-Key' => 'rec-match-'.Str::random(20)]);

        $response->assertStatus(201)->assertJsonPath('match.status', 'MATCHED')->assertJsonPath('match.mismatches', []);
        $this->assertDatabaseHas('reconciliation_matches', ['invoice_id' => $ctx['invoiceId'], 'status' => 'MATCHED']);
        $this->assertDatabaseCount('reconciliation_exceptions', 0);
    }

    public function test_running_a_match_without_step_up_is_locked(): void
    {
        $ctx = $this->certifyInvoice();
        $officer = $this->makeNamraOfficer();

        $response = $this->actingAs($officer)->postJson("/api/v1/invoices/{$ctx['invoiceId']}/match", [], ['Idempotency-Key' => 'rec-match-'.Str::random(20)]);

        $response->assertStatus(423);
    }

    public function test_a_tampered_ledger_entry_produces_an_exception_and_a_queue_item(): void
    {
        $ctx = $this->certifyInvoice();
        $invoice = Invoice::find($ctx['invoiceId']);
        DB::table('ledger_entries')->where('transaction_id', $invoice->transaction_id)->where('entry_type', 'OUTPUT_VAT')->update(['amount_cents' => 99_999]);
        $officer = $this->makeNamraOfficer();

        $response = $this->actingAs($officer)->withFreshStepUp()
            ->postJson("/api/v1/invoices/{$ctx['invoiceId']}/match", [], ['Idempotency-Key' => 'rec-match-'.Str::random(20)]);

        $response->assertStatus(201)->assertJsonPath('match.status', 'EXCEPTION');
        $this->assertNotEmpty($response->json('match.mismatches'));
        $this->assertDatabaseHas('reconciliation_exceptions', ['invoice_id' => $ctx['invoiceId'], 'status' => 'OPEN', 'severity' => 'HIGH']);
    }

    public function test_a_replayed_idempotency_key_returns_the_same_match(): void
    {
        $ctx = $this->certifyInvoice();
        $officer = $this->makeNamraOfficer();
        $key = 'rec-match-replay-'.Str::random(20);

        $first = $this->actingAs($officer)->withFreshStepUp()->postJson("/api/v1/invoices/{$ctx['invoiceId']}/match", [], ['Idempotency-Key' => $key]);
        $second = $this->actingAs($officer)->withFreshStepUp()->postJson("/api/v1/invoices/{$ctx['invoiceId']}/match", [], ['Idempotency-Key' => $key]);

        $first->assertStatus(201);
        $second->assertStatus(201)->assertJsonPath('match.id', $first->json('match.id'));
        $this->assertDatabaseCount('reconciliation_matches', 1);
    }

    public function test_matching_the_same_invoice_twice_with_different_keys_returns_the_existing_match(): void
    {
        $ctx = $this->certifyInvoice();
        $officer = $this->makeNamraOfficer();

        $first = $this->actingAs($officer)->withFreshStepUp()->postJson("/api/v1/invoices/{$ctx['invoiceId']}/match", [], ['Idempotency-Key' => 'rec-match-a-'.Str::random(20)]);
        $second = $this->actingAs($officer)->withFreshStepUp()->postJson("/api/v1/invoices/{$ctx['invoiceId']}/match", [], ['Idempotency-Key' => 'rec-match-b-'.Str::random(20)]);

        $first->assertStatus(201);
        $second->assertStatus(201)->assertJsonPath('match.id', $first->json('match.id'));
        $this->assertDatabaseCount('reconciliation_matches', 1);
    }

    public function test_a_taxpayer_cannot_run_a_match_for_another_taxpayers_invoice(): void
    {
        $ctx = $this->certifyInvoice();
        $other = $this->makeTradingParty('VAT-REC-OTHER');
        // Grant the other taxpayer's owner enough to attempt this, matching a compromised-actor scenario.
        $other['owner']->update(['role' => 'NAMRA_VAT_AUDITOR']);

        $response = $this->actingAs($other['owner'])->withFreshStepUp()
            ->postJson("/api/v1/invoices/{$ctx['invoiceId']}/match", [], ['Idempotency-Key' => 'rec-match-'.Str::random(20)]);

        $response->assertStatus(403);
    }

    public function test_running_a_match_for_an_unknown_invoice_is_a_validation_error(): void
    {
        $officer = $this->makeNamraOfficer();

        $response = $this->actingAs($officer)->withFreshStepUp()
            ->postJson('/api/v1/invoices/'.Str::uuid().'/match', [], ['Idempotency-Key' => 'rec-match-'.Str::random(20)]);

        $response->assertStatus(422)->assertJsonPath('code', 'INVOICE_NOT_FOUND');
    }

    public function test_the_work_queue_lists_an_open_exception(): void
    {
        $ctx = $this->certifyInvoice();
        $invoice = Invoice::find($ctx['invoiceId']);
        DB::table('ledger_entries')->where('transaction_id', $invoice->transaction_id)->where('entry_type', 'OUTPUT_VAT')->update(['amount_cents' => 1]);
        $officer = $this->makeNamraOfficer();
        $this->actingAs($officer)->withFreshStepUp()->postJson("/api/v1/invoices/{$ctx['invoiceId']}/match", [], ['Idempotency-Key' => 'rec-match-'.Str::random(20)])->assertStatus(201);

        $response = $this->actingAs($officer)->getJson('/api/v1/exceptions');

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, $response->json('work_queue.total_count'));
        $this->assertSame('OPEN', collect($response->json('work_queue.items'))->firstWhere('invoice_id', $ctx['invoiceId'])['status']);
    }

    public function test_the_work_queue_filters_by_severity(): void
    {
        $officer = $this->makeNamraOfficer();

        $response = $this->actingAs($officer)->getJson('/api/v1/exceptions?severity=CRITICAL');

        $response->assertStatus(200)->assertJsonPath('work_queue.total_count', 0);
    }

    public function test_the_work_queue_rejects_conflicting_assignment_filters(): void
    {
        $officer = $this->makeNamraOfficer();

        $response = $this->actingAs($officer)->getJson('/api/v1/exceptions?assigned_officer_id='.$officer->id.'&unassigned_only=true');

        $response->assertStatus(422)->assertJsonPath('code', 'ASSIGNMENT_FILTER_CONFLICT');
    }

    public function test_assigning_an_exception_to_an_active_officer(): void
    {
        $exception = ReconciliationException::create([
            'id' => (string) Str::uuid(), 'invoice_id' => $this->certifyInvoice()['invoiceId'], 'taxpayer_id' => null,
            'exception_type' => 'LEDGER_MISMATCH', 'severity' => 'HIGH', 'status' => 'OPEN', 'summary' => 'Test mismatch.', 'created_at' => now(),
        ]);
        $supervisor = $this->makeNamraOfficer('NAMRA_VAT_SUPERVISOR');
        $assignee = $this->makeNamraOfficer();

        $response = $this->actingAs($supervisor)->withFreshStepUp()
            ->postJson("/api/v1/exceptions/{$exception->id}/assignment", ['officer_id' => $assignee->id], ['Idempotency-Key' => 'rec-assign-'.Str::random(20)]);

        $response->assertStatus(200)->assertJsonPath('assignment.status', 'ASSIGNED');
        $this->assertDatabaseHas('reconciliation_exceptions', ['id' => $exception->id, 'status' => 'ASSIGNED', 'assigned_officer_id' => $assignee->id]);
    }

    public function test_assigning_a_resolved_exception_is_a_conflict(): void
    {
        $exception = ReconciliationException::create([
            'id' => (string) Str::uuid(), 'invoice_id' => $this->certifyInvoice()['invoiceId'], 'taxpayer_id' => null,
            'exception_type' => 'LEDGER_MISMATCH', 'severity' => 'HIGH', 'status' => 'RESOLVED', 'summary' => 'Already resolved.', 'created_at' => now(),
        ]);
        $supervisor = $this->makeNamraOfficer('NAMRA_VAT_SUPERVISOR');
        $assignee = $this->makeNamraOfficer();

        $response = $this->actingAs($supervisor)->withFreshStepUp()
            ->postJson("/api/v1/exceptions/{$exception->id}/assignment", ['officer_id' => $assignee->id], ['Idempotency-Key' => 'rec-assign-'.Str::random(20)]);

        $response->assertStatus(409);
    }

    public function test_assigning_to_an_inactive_officer_is_rejected(): void
    {
        $exception = ReconciliationException::create([
            'id' => (string) Str::uuid(), 'invoice_id' => $this->certifyInvoice()['invoiceId'], 'taxpayer_id' => null,
            'exception_type' => 'LEDGER_MISMATCH', 'severity' => 'HIGH', 'status' => 'OPEN', 'summary' => 'Test.', 'created_at' => now(),
        ]);
        $supervisor = $this->makeNamraOfficer('NAMRA_VAT_SUPERVISOR');
        $inactive = $this->makeNamraOfficer();
        $inactive->update(['status' => 'SUSPENDED']);

        $response = $this->actingAs($supervisor)->withFreshStepUp()
            ->postJson("/api/v1/exceptions/{$exception->id}/assignment", ['officer_id' => $inactive->id], ['Idempotency-Key' => 'rec-assign-'.Str::random(20)]);

        $response->assertStatus(422)->assertJsonPath('code', 'OFFICER_NOT_ACTIVE');
    }

    public function test_resolving_an_exception_with_notes(): void
    {
        $exception = ReconciliationException::create([
            'id' => (string) Str::uuid(), 'invoice_id' => $this->certifyInvoice()['invoiceId'], 'taxpayer_id' => null,
            'exception_type' => 'LEDGER_MISMATCH', 'severity' => 'HIGH', 'status' => 'OPEN', 'summary' => 'Test.', 'created_at' => now(),
        ]);
        $officer = $this->makeNamraOfficer();

        $response = $this->actingAs($officer)->withFreshStepUp()
            ->postJson("/api/v1/exceptions/{$exception->id}/resolution", ['notes' => 'Investigated and corrected the ledger entry manually.'], ['Idempotency-Key' => 'rec-resolve-'.Str::random(20)]);

        $response->assertStatus(200)->assertJsonPath('resolution.status', 'RESOLVED');
        $this->assertDatabaseHas('reconciliation_exceptions', ['id' => $exception->id, 'status' => 'RESOLVED', 'resolved_by' => $officer->id]);
    }

    public function test_resolving_an_already_resolved_exception_is_idempotent(): void
    {
        $exception = ReconciliationException::create([
            'id' => (string) Str::uuid(), 'invoice_id' => $this->certifyInvoice()['invoiceId'], 'taxpayer_id' => null,
            'exception_type' => 'LEDGER_MISMATCH', 'severity' => 'HIGH', 'status' => 'RESOLVED', 'summary' => 'Test.', 'created_at' => now(), 'resolved_at' => now(),
        ]);
        $officer = $this->makeNamraOfficer();

        $response = $this->actingAs($officer)->withFreshStepUp()
            ->postJson("/api/v1/exceptions/{$exception->id}/resolution", ['notes' => 'Second resolution attempt with new notes.'], ['Idempotency-Key' => 'rec-resolve-'.Str::random(20)]);

        $response->assertStatus(200)->assertJsonPath('resolution.status', 'RESOLVED');
    }

    public function test_resolving_with_too_short_notes_is_a_validation_error(): void
    {
        $exception = ReconciliationException::create([
            'id' => (string) Str::uuid(), 'invoice_id' => $this->certifyInvoice()['invoiceId'], 'taxpayer_id' => null,
            'exception_type' => 'LEDGER_MISMATCH', 'severity' => 'HIGH', 'status' => 'OPEN', 'summary' => 'Test.', 'created_at' => now(),
        ]);
        $officer = $this->makeNamraOfficer();

        $response = $this->actingAs($officer)->withFreshStepUp()
            ->postJson("/api/v1/exceptions/{$exception->id}/resolution", ['notes' => 'too short'], ['Idempotency-Key' => 'rec-resolve-'.Str::random(20)]);

        $response->assertStatus(422)->assertJsonPath('code', 'NOTES_INVALID');
    }

    public function test_the_blade_view_requires_authentication(): void
    {
        $this->get('/exceptions')->assertRedirect('/login');
    }

    public function test_the_blade_view_renders_the_work_queue(): void
    {
        $exception = ReconciliationException::create([
            'id' => (string) Str::uuid(), 'invoice_id' => $this->certifyInvoice()['invoiceId'], 'taxpayer_id' => null,
            'exception_type' => 'LEDGER_MISMATCH', 'severity' => 'HIGH', 'status' => 'OPEN', 'summary' => 'Blade-visible mismatch.', 'created_at' => now(),
        ]);
        $officer = $this->makeNamraOfficer();

        $response = $this->actingAs($officer)->get('/exceptions');

        $response->assertOk()->assertViewIs('exceptions.index');
        $response->assertSee('Reconciliation exceptions');
    }

    public function test_resolving_through_the_blade_view_without_a_fresh_step_up_redirects_to_password_confirmation(): void
    {
        $exception = ReconciliationException::create([
            'id' => (string) Str::uuid(), 'invoice_id' => $this->certifyInvoice()['invoiceId'], 'taxpayer_id' => null,
            'exception_type' => 'LEDGER_MISMATCH', 'severity' => 'HIGH', 'status' => 'OPEN', 'summary' => 'Test.', 'created_at' => now(),
        ]);
        $officer = $this->makeNamraOfficer();

        $response = $this->actingAs($officer)->post("/exceptions/{$exception->id}/resolution", ['notes' => 'Resolved through the Blade form.']);

        $response->assertRedirect(route('security.mfa', ['redirect_to' => url('/')]));
    }

    public function test_resolving_through_the_blade_view_with_a_fresh_step_up_succeeds(): void
    {
        $exception = ReconciliationException::create([
            'id' => (string) Str::uuid(), 'invoice_id' => $this->certifyInvoice()['invoiceId'], 'taxpayer_id' => null,
            'exception_type' => 'LEDGER_MISMATCH', 'severity' => 'HIGH', 'status' => 'OPEN', 'summary' => 'Test.', 'created_at' => now(),
        ]);
        $officer = $this->makeNamraOfficer();

        $response = $this->actingAs($officer)->withFreshStepUp()
            ->post("/exceptions/{$exception->id}/resolution", ['notes' => 'Resolved through the Blade form with a fresh step-up.']);

        $response->assertRedirect(route('exceptions.index'));
        $this->assertDatabaseHas('reconciliation_exceptions', ['id' => $exception->id, 'status' => 'RESOLVED']);
    }
}
