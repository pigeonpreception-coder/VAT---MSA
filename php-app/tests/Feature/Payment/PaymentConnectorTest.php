<?php

namespace Tests\Feature\Payment;

use App\Models\Organisation;
use App\Models\OrganisationCapability;
use App\Models\ServiceComponent;
use App\Models\TaxObligation;
use App\Models\Taxpayer;
use App\Models\User;
use App\Models\VatPeriod;
use App\Models\VatReturnVersion;
use Database\Seeders\RoleSeeder;
use Database\Seeders\ServiceComponentSeeder;
use Database\Seeders\TaxRuleSetSeeder;
use Database\Seeders\VatRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithStepUp;
use Tests\TestCase;

/**
 * Covers Module 9 Phase D's guarded RecordPayment/AllocatePayment/
 * GetOutstanding (App\Services\Payment\PaymentService, ported from
 * lib/data/payment-repository.ts), reusing tests/routes/module-9-payment-
 * connector.test.ts's own exact scenarios -- both halves of its Definition
 * of Done, proven separately and deliberately:
 *
 * 1. The REAL command path (every test except the last) -- under this
 *    codebase's real, unmodified state, App\Integrations\Payment\
 *    SandboxPaymentConnector's own service_components guard refuses every
 *    single attempt, and payment_instructions never receives a single row.
 * 2. A single, clearly-labelled SIMULATION at the end, which manually
 *    flips component-payment's row directly in the database -- something
 *    no command anywhere in this codebase can do -- purely to prove the
 *    sandbox connector's own mock logic is sound if it were ever
 *    hypothetically authorised.
 *
 * Reuses RefundClaimTest's own makeTradingParty/makeRefundOfficer/
 * makePilotAdmin/invoicePayload/makeRefundableReturn helper shapes to
 * anchor a real claim to a genuine negative-net-position return, driven
 * all the way to PAYMENT_PENDING through the real maker-checker
 * transition sequence.
 */
class PaymentConnectorTest extends TestCase
{
    use InteractsWithStepUp;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(VatRuleSeeder::class);
        $this->seed(TaxRuleSetSeeder::class);
        $this->seed(ServiceComponentSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, owner: User} */
    private function makeTradingParty(string $vatNumber, array $capabilities = ['BUYER', 'SELLER']): array
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => strtolower($vatNumber).'@test.test',
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
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@paytest.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    private function makeRefundOfficer(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Refund Officer', 'email' => 'refund-officer-'.Str::random(8).'@paytest.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_VAT_SENIOR_AUDITOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function makePilotAdmin(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Pilot Admin', 'email' => 'pilot-admin-'.Str::random(8).'@paytest.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_SYSTEM_SUPPORT', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function invoicePayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'schema_version' => '1.0.0', 'invoice_number' => 'INV-'.Str::random(8), 'document_type' => 'TAX_INVOICE',
            'source' => ['system_id' => 'erp-test', 'document_id' => 'doc-'.Str::random(8), 'submitted_at' => '2026-09-01T09:00:00Z'],
            'supplier' => ['name' => 'Supplier Co', 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => 'VAT-SUP-0001']]],
            'customer' => ['name' => 'Customer Co', 'identifiers' => [['type' => 'VAT_NUMBER', 'value' => 'VAT-CUS-0001']]],
            'issue_date' => '2026-09-01', 'currency' => 'NAD',
            'lines' => [['line_number' => 1, 'description' => 'Consulting services', 'quantity' => '1', 'unit_code' => 'EA', 'unit_price' => '1000.00', 'net_amount' => '1000.00', 'tax' => ['category' => 'STANDARD', 'rate' => '15.00', 'taxable_amount' => '1000.00', 'tax_amount' => '150.00']]],
            'totals' => ['line_net_amount' => '1000.00', 'tax_exclusive_amount' => '1000.00', 'tax_amount' => '150.00', 'tax_inclusive_amount' => '1150.00', 'payable_amount' => '1150.00'],
        ], $overrides);
    }

    private function makeRefundableReturn(array $supplier, array $customer): VatReturnVersion
    {
        $this->actingAs($supplier['owner'])->postJson('/api/v1/invoices', $this->invoicePayload([
            'supplier' => ['identifiers' => [['value' => $supplier['taxpayer']->vat_number]]],
            'customer' => ['identifiers' => [['value' => $customer['taxpayer']->vat_number]]],
        ]), ['Idempotency-Key' => 'inv-'.Str::random(20)])->assertStatus(201);

        $period = VatPeriod::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $customer['organisation']->id, 'taxpayer_id' => $customer['taxpayer']->id,
            'period_code' => '2026-09', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'due_date' => '2026-10-25',
            'status' => 'OPEN', 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $generate = $this->actingAs($customer['owner'])->postJson("/api/v1/vat-periods/{$period->id}/returns", [], ['Idempotency-Key' => 'gen-'.Str::random(20)]);
        $generate->assertStatus(201)->assertJsonPath('resource.net_payable_cents', -15000);
        $versionId = $generate->json('resource.id');

        $approvalRequest = $this->actingAs($customer['owner'])->postJson("/api/v1/vat-returns/{$versionId}/approval-requests", [], ['Idempotency-Key' => 'ar-'.Str::random(20)]);
        $taskId = $approvalRequest->json('resource.id');
        $this->actingAs($this->makePilotAdmin())->postJson("/api/v1/approval-tasks/{$taskId}/decision", [
            'decision' => 'APPROVE', 'comment' => 'Verified against ledger evidence.',
        ], ['Idempotency-Key' => 'decide-'.Str::random(20)])->assertStatus(200);

        return VatReturnVersion::findOrFail($versionId);
    }

    /** Drives a freshly-requested refund claim all the way to PAYMENT_PENDING through the real maker-checker transition sequence. */
    private function driveClaimToPaymentPending(array $customer, string $claimId): void
    {
        $officer1 = $this->makeRefundOfficer();
        $officer2 = $this->makeRefundOfficer();

        $this->actingAs($officer1)->withFreshStepUp()->postJson("/api/v1/refunds/{$claimId}/transition", [
            'schema_version' => '1.0.0', 'action' => 'APPROVE', 'findings' => 'Risk screen clean.',
        ], ['Idempotency-Key' => 't1-'.Str::random(20)])->assertStatus(200)->assertJsonPath('resource.status', 'RISK_REVIEW');
        $this->actingAs($officer1)->withFreshStepUp()->postJson("/api/v1/refunds/{$claimId}/transition", [
            'schema_version' => '1.0.0', 'action' => 'APPROVE', 'findings' => 'No open risk indicators.',
        ], ['Idempotency-Key' => 't2-'.Str::random(20)])->assertStatus(200)->assertJsonPath('resource.status', 'OFFICER_REVIEW');
        $this->actingAs($officer1)->withFreshStepUp()->postJson("/api/v1/refunds/{$claimId}/transition", [
            'schema_version' => '1.0.0', 'action' => 'APPROVE', 'findings' => 'Evidence in order, ready for payment authorisation.',
        ], ['Idempotency-Key' => 't3-'.Str::random(20)])->assertStatus(200)->assertJsonPath('resource.status', 'PAYMENT_AUTHORISATION');
        $this->actingAs($officer2)->withFreshStepUp()->postJson("/api/v1/refunds/{$claimId}/transition", [
            'schema_version' => '1.0.0', 'action' => 'APPROVE', 'findings' => 'Independently verified; authorising payment.',
        ], ['Idempotency-Key' => 't5-'.Str::random(20)])->assertStatus(200)->assertJsonPath('resource.status', 'PAYMENT_PENDING');
    }

    /** @return array{claimId: string, customer: array} */
    private function makePaymentPendingClaim(string $suffix): array
    {
        $supplier = $this->makeTradingParty("VAT-SUP-PAY{$suffix}");
        $customer = $this->makeTradingParty("VAT-CUS-PAY{$suffix}");
        $version = $this->makeRefundableReturn($supplier, $customer);
        // Simulate the still-unbuilt "file the return" step by marking the
        // version FILED directly -- matching RefundClaimTest's own
        // established precedent for this exact gap.
        VatReturnVersion::where('id', $version->id)->update(['status' => 'FILED']);

        TaxObligation::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $customer['organisation']->id, 'taxpayer_id' => $customer['taxpayer']->id,
            'obligation_type' => 'INCOME_TAX', 'period_code' => '2026-09', 'due_date' => '2026-10-25',
            'amount_cents' => 3000, 'currency' => 'NAD', 'status' => 'PENDING', 'source_system' => 'MANUAL',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $refundResponse = $this->actingAs($customer['owner'])->postJson('/api/v1/refunds', [
            'schema_version' => '1.0.0', 'vat_return_version_id' => $version->id,
        ], ['Idempotency-Key' => 'refund-'.Str::random(20)]);
        $refundResponse->assertStatus(201)->assertJsonPath('resource.status', 'RECEIVED');
        $claimId = $refundResponse->json('resource.id');

        $this->driveClaimToPaymentPending($customer, $claimId);

        return ['claimId' => $claimId, 'customer' => $customer];
    }

    public function test_component_payment_is_seeded_disabled_requires_authority_contract_the_guards_starting_state(): void
    {
        $row = ServiceComponent::where('component_key', 'PAYMENT_CONNECTOR')->firstOrFail();
        $this->assertSame('REQUIRES_AUTHORITY_CONTRACT', $row->configuration_status);
        $this->assertSame('DISABLED', $row->operational_status);
    }

    public function test_refuses_record_payment_for_a_claim_that_has_not_reached_payment_pending(): void
    {
        $supplier = $this->makeTradingParty('VAT-SUP-PAY01');
        $customer = $this->makeTradingParty('VAT-CUS-PAY01');
        $version = $this->makeRefundableReturn($supplier, $customer);
        VatReturnVersion::where('id', $version->id)->update(['status' => 'FILED']);
        $claimId = $this->actingAs($customer['owner'])->postJson('/api/v1/refunds', [
            'schema_version' => '1.0.0', 'vat_return_version_id' => $version->id,
        ], ['Idempotency-Key' => 'refund-'.Str::random(20)])->json('resource.id');

        $response = $this->actingAs($this->makeRefundOfficer())->withFreshStepUp()->postJson("/api/v1/refunds/{$claimId}/payment", [
            'schema_version' => '1.0.0', 'beneficiary_reference' => 'NA-BANK-ACC-000111222', 'provider' => 'Bank of Namibia',
        ], ['Idempotency-Key' => 'pay-'.Str::random(20)]);

        $response->assertStatus(409);
        $this->assertDatabaseCount('payment_instructions', 0);
    }

    public function test_rejects_record_payment_with_a_validation_error_for_a_missing_beneficiary_reference(): void
    {
        $ctx = $this->makePaymentPendingClaim('02');

        $response = $this->actingAs($this->makeRefundOfficer())->withFreshStepUp()->postJson("/api/v1/refunds/{$ctx['claimId']}/payment", [
            'schema_version' => '1.0.0', 'beneficiary_reference' => '', 'provider' => 'Bank of Namibia',
        ], ['Idempotency-Key' => 'pay-'.Str::random(20)]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('payment_instructions', 0);
    }

    public function test_denies_record_payment_to_a_role_without_payments_record(): void
    {
        $ctx = $this->makePaymentPendingClaim('03');
        $auditor = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Auditor', 'email' => 'auditor-'.Str::random(8).'@paytest.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_VAT_AUDITOR', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($auditor)->withFreshStepUp()->postJson("/api/v1/refunds/{$ctx['claimId']}/payment", [
            'schema_version' => '1.0.0', 'beneficiary_reference' => 'NA-BANK-ACC-000111222', 'provider' => 'Bank of Namibia',
        ], ['Idempotency-Key' => 'pay-'.Str::random(20)]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('payment_instructions', 0);
    }

    public function test_real_command_path_record_payment_on_a_genuinely_payment_pending_claim_is_refused_and_reports_awaiting_authority(): void
    {
        $ctx = $this->makePaymentPendingClaim('04');
        $officer = $this->makeRefundOfficer();

        $response = $this->actingAs($officer)->withFreshStepUp()->postJson("/api/v1/refunds/{$ctx['claimId']}/payment", [
            'schema_version' => '1.0.0', 'beneficiary_reference' => 'NA-BANK-ACC-000111222', 'provider' => 'Bank of Namibia',
        ], ['Idempotency-Key' => 'pay-'.Str::random(20)]);

        $response->assertStatus(201)
            ->assertJsonPath('resource.status', 'AWAITING_AUTHORITY')
            ->assertJsonPath('resource.provider_reference', null);
        $this->assertDatabaseCount('payment_instructions', 0);
        $this->assertDatabaseHas('refund_claims', ['id' => $ctx['claimId'], 'payment_instruction_id' => null]);
    }

    public function test_real_command_path_repeated_record_payment_attempts_stay_refused(): void
    {
        $ctx = $this->makePaymentPendingClaim('05');
        $officer = $this->makeRefundOfficer();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $response = $this->actingAs($officer)->withFreshStepUp()->postJson("/api/v1/refunds/{$ctx['claimId']}/payment", [
                'schema_version' => '1.0.0', 'beneficiary_reference' => 'NA-BANK-ACC-000111222', 'provider' => 'Bank of Namibia',
            ], ['Idempotency-Key' => 'pay-attempt-'.$attempt.'-'.Str::random(10)]);
            $response->assertStatus(201)->assertJsonPath('resource.status', 'AWAITING_AUTHORITY');
        }
        $this->assertDatabaseCount('payment_instructions', 0);
    }

    public function test_real_command_path_allocate_payment_is_refused_with_a_conflict_since_no_instruction_exists(): void
    {
        $ctx = $this->makePaymentPendingClaim('06');
        $officer = $this->makeRefundOfficer();

        $response = $this->actingAs($officer)->withFreshStepUp()->postJson("/api/v1/refunds/{$ctx['claimId']}/payment/allocation", [
            'schema_version' => '1.0.0', 'settlement_reference' => 'STL-0001', 'settled_amount_cents' => 100_000,
        ], ['Idempotency-Key' => 'alloc-'.Str::random(20)]);

        $response->assertStatus(409);
    }

    public function test_real_command_path_get_outstanding_lists_the_claim_honestly_with_the_real_connector_state(): void
    {
        $ctx = $this->makePaymentPendingClaim('07');
        $officer = $this->makeRefundOfficer();

        $response = $this->actingAs($officer)->getJson('/api/v1/payments/outstanding');

        $response->assertStatus(200);
        $body = $response->json();
        $listed = collect($body['claims'])->firstWhere('id', $ctx['claimId']);
        $this->assertNotNull($listed);
        $this->assertSame(12000, $listed['net_payable_cents']);
        $this->assertGreaterThanOrEqual(12000, $body['total_outstanding_cents']);
        $this->assertSame('REQUIRES_AUTHORITY_CONTRACT', $body['connector']['state']);
        $this->assertFalse($body['connector']['configured']);
    }

    public function test_get_outstanding_is_restricted_to_national_scope_refund_roles(): void
    {
        $ctx = $this->makePaymentPendingClaim('08');

        $response = $this->actingAs($ctx['customer']['owner'])->getJson('/api/v1/payments/outstanding');

        $response->assertStatus(403);
    }

    public function test_simulation_only_flipping_the_connector_to_sandbox_active_lets_record_and_allocate_payment_genuinely_succeed(): void
    {
        $ctx = $this->makePaymentPendingClaim('09');
        $officer = $this->makeRefundOfficer();

        ServiceComponent::where('component_key', 'PAYMENT_CONNECTOR')->update([
            'configuration_status' => 'SANDBOX_CONFIGURED', 'operational_status' => 'SANDBOX_ACTIVE',
        ]);

        $recordResponse = $this->actingAs($officer)->withFreshStepUp()->postJson("/api/v1/refunds/{$ctx['claimId']}/payment", [
            'schema_version' => '1.0.0', 'beneficiary_reference' => 'NA-BANK-ACC-000111222', 'provider' => 'Bank of Namibia',
        ], ['Idempotency-Key' => 'sim-pay-'.Str::random(20)]);
        $recordResponse->assertStatus(201)->assertJsonPath('resource.status', 'INITIATED');
        $this->assertStringStartsWith('SANDBOX-', $recordResponse->json('resource.provider_reference'));
        $this->assertDatabaseCount('payment_instructions', 1);
        $this->assertDatabaseMissing('refund_claims', ['id' => $ctx['claimId'], 'payment_instruction_id' => null]);

        $allocateResponse = $this->actingAs($officer)->withFreshStepUp()->postJson("/api/v1/refunds/{$ctx['claimId']}/payment/allocation", [
            'schema_version' => '1.0.0', 'settlement_reference' => 'STL-SANDBOX-0001', 'settled_amount_cents' => 100_000,
        ], ['Idempotency-Key' => 'sim-alloc-'.Str::random(20)]);
        $allocateResponse->assertStatus(200)
            ->assertJsonPath('resource.status', 'SETTLED')
            ->assertJsonPath('resource.provider_reference', 'STL-SANDBOX-0001');

        // A second AllocatePayment against an already-settled instruction is refused.
        $secondAllocate = $this->actingAs($officer)->withFreshStepUp()->postJson("/api/v1/refunds/{$ctx['claimId']}/payment/allocation", [
            'schema_version' => '1.0.0', 'settlement_reference' => 'STL-SANDBOX-0002', 'settled_amount_cents' => 100_000,
        ], ['Idempotency-Key' => 'sim-alloc2-'.Str::random(20)]);
        $secondAllocate->assertStatus(409);

        // Restore the guard to its real, DISABLED default.
        ServiceComponent::where('component_key', 'PAYMENT_CONNECTOR')->update([
            'configuration_status' => 'REQUIRES_AUTHORITY_CONTRACT', 'operational_status' => 'DISABLED',
        ]);
    }

    public function test_recording_a_payment_without_a_fresh_step_up_redirects_to_password_confirmation_on_the_blade_route(): void
    {
        $ctx = $this->makePaymentPendingClaim('10');
        $officer = $this->makeRefundOfficer();

        $response = $this->actingAs($officer)->post("/refunds/{$ctx['claimId']}/payment", [
            'beneficiary_reference' => 'NA-BANK-ACC-000111222', 'provider' => 'Bank of Namibia',
        ]);

        $response->assertRedirect(route('security.mfa', ['redirect_to' => url('/')]));
    }

    public function test_recording_a_payment_through_the_blade_view_with_a_fresh_step_up_shows_the_awaiting_authority_outcome(): void
    {
        $ctx = $this->makePaymentPendingClaim('11');
        $officer = $this->makeRefundOfficer();

        $response = $this->actingAs($officer)->withFreshStepUp()->post("/refunds/{$ctx['claimId']}/payment", [
            'beneficiary_reference' => 'NA-BANK-ACC-000111222', 'provider' => 'Bank of Namibia',
        ]);

        $response->assertRedirect(route('refunds.show', $ctx['claimId']));
        $response->assertSessionHas('status', 'Payment recorded.');
    }

    public function test_the_outstanding_payments_panel_renders_on_the_refunds_index_for_a_national_officer(): void
    {
        $ctx = $this->makePaymentPendingClaim('12');
        $officer = $this->makeRefundOfficer();

        $response = $this->actingAs($officer)->get('/refunds');

        $response->assertOk();
        $response->assertSee('Outstanding refund payments');
        $response->assertSee('Requires authority contract');
    }
}
