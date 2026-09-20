<?php

namespace App\Services\Payment;

use App\Domain\Payment\PaymentValidator;
use App\Exceptions\PaymentResourceException;
use App\Exceptions\RepositoryConflictException;
use App\Integrations\Payment\PaymentConnectorPort;
use App\Integrations\Payment\PaymentIntegrationUnavailableException;
use App\Models\PaymentInstruction;
use App\Models\RefundClaim;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Access\TenantScope;
use App\Support\Business\CommandLedger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/payment-repository.ts (Module 9 Phase D). Officer-
 * only, national-scope, operating on a refund claim that has already
 * reached PAYMENT_PENDING -- App\Domain\Compliance\ComplianceValidator's
 * own deliberate terminal boundary for the claim state machine itself (see
 * REFUND_CLAIM_TRANSITIONS's own doc comment: "Payment itself stays
 * DISABLED PENDING AUTHORITY, so nothing beyond PAYMENT_PENDING is
 * modeled"). recordPayment/allocatePayment never touch refund_claims.status;
 * they only read the claim to authorise a payment_instructions row against
 * it, and every call goes through PaymentConnectorPort first -- today that
 * always throws PaymentIntegrationUnavailableException, because
 * component-payment (database/seeders/ServiceComponentSeeder) is DISABLED
 * by design and nothing anywhere in this codebase ever writes to that row,
 * so the payment_instructions INSERT below, gated inside the try block, is
 * provably unreachable in this deployment (see
 * tests/Feature/Payment/PaymentConnectorTest.php). On the unavailable path
 * this still records an honest audit-trail entry (AWAITING_AUTHORITY)
 * rather than silently failing or throwing a bare 5xx.
 */
class PaymentService
{
    public function __construct(private readonly PaymentConnectorPort $connector) {}

    /** @return array<string, mixed> */
    public function recordPayment(string $claimId, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        if (! TenantScope::isNational($actor)) {
            throw new AuthorizationException('Only an authorised national refund role may record a refund payment.');
        }
        $input = PaymentValidator::recordPaymentInput($payload);

        $claim = RefundClaim::find($claimId);
        if (! $claim) {
            throw new PaymentResourceException('Refund claim was not found.', 404);
        }
        if ($claim->status !== 'PAYMENT_PENDING') {
            throw new RepositoryConflictException('A refund payment can only be recorded once the claim reaches PAYMENT_PENDING.');
        }
        if ($claim->payment_instruction_id) {
            throw new RepositoryConflictException('A payment has already been recorded for this refund claim.');
        }

        $requestHash = CommandLedger::requestHash(['claim_id' => $claim->id, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'RECORD_REFUND_PAYMENT', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->presentInstruction(PaymentInstruction::find($prior));
        }

        $amountCents = $claim->net_payable_cents ?? $claim->amount_cents;
        $beneficiaryMasked = PaymentValidator::maskBeneficiaryReference($input['beneficiary_reference']);
        $now = now();
        $requestReference = (string) Str::uuid();

        try {
            $result = $this->connector->recordPayment([
                'request_reference' => $requestReference, 'refund_claim_id' => $claim->id, 'taxpayer_id' => $claim->taxpayer_id,
                'amount_cents' => $amountCents, 'currency' => $claim->currency, 'beneficiary_reference_masked' => $beneficiaryMasked,
                'provider' => $input['provider'], 'correlation_id' => $correlationId,
            ]);
            $instructionId = (string) Str::uuid();
            DB::transaction(function () use ($instructionId, $claim, $amountCents, $beneficiaryMasked, $input, $result, $requestReference, $actor, $now, $idempotencyKey, $requestHash, $correlationId) {
                PaymentInstruction::create([
                    'id' => $instructionId, 'refund_claim_id' => $claim->id, 'taxpayer_id' => $claim->taxpayer_id,
                    'amount_cents' => $amountCents, 'currency' => $claim->currency, 'beneficiary_reference_masked' => $beneficiaryMasked,
                    'provider' => $input['provider'], 'status' => $result['status'], 'provider_reference' => $result['provider_reference'],
                    'idempotency_key' => $requestReference, 'approved_by' => $actor->id, 'approved_at' => $now,
                    'submitted_at' => $now, 'settled_at' => null, 'last_error' => null,
                ]);
                RefundClaim::where('id', $claim->id)->update(['payment_instruction_id' => $instructionId]);
                CommandLedger::record($actor->id, 'RECORD_REFUND_PAYMENT', $idempotencyKey, $requestHash, 'PAYMENT_INSTRUCTION', $instructionId, $now);
                CommandLedger::outbox('PAYMENT_INSTRUCTION', $instructionId, 'PaymentRecorded', $claim->taxpayer_id, [
                    'refund_claim_id' => $claim->id, 'amount_cents' => $amountCents, 'provider' => $input['provider'], 'correlation_id' => $correlationId,
                ], $now);
                AuditService::append($actor, 'REFUND_PAYMENT_RECORDED', 'PAYMENT_INSTRUCTION', $instructionId, [
                    'refundClaimId' => $claim->id, 'amountCents' => $amountCents, 'provider' => $input['provider'],
                    'providerReference' => $result['provider_reference'], 'correlationId' => $correlationId,
                ], $now);
            });

            return $this->presentInstruction(PaymentInstruction::find($instructionId));
        } catch (PaymentIntegrationUnavailableException) {
            AuditService::append($actor, 'REFUND_PAYMENT_ATTEMPTED', 'REFUND_CLAIM', $claim->id, [
                'amountCents' => $amountCents, 'provider' => $input['provider'], 'outcome' => 'AWAITING_AUTHORITY', 'correlationId' => $correlationId,
            ], $now);

            return [
                'refund_claim_id' => $claim->id, 'taxpayer_id' => $claim->taxpayer_id, 'amount_cents' => $amountCents,
                'currency' => $claim->currency, 'status' => 'AWAITING_AUTHORITY', 'provider' => $input['provider'],
                'provider_reference' => null, 'recorded_at' => $now->toISOString(),
            ];
        }
    }

    /** @return array<string, mixed> */
    public function allocatePayment(string $claimId, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        if (! TenantScope::isNational($actor)) {
            throw new AuthorizationException('Only an authorised national refund role may allocate a refund payment.');
        }
        $input = PaymentValidator::allocatePaymentInput($payload);

        $claim = RefundClaim::find($claimId);
        if (! $claim) {
            throw new PaymentResourceException('Refund claim was not found.', 404);
        }
        if (! $claim->payment_instruction_id) {
            throw new RepositoryConflictException('This refund claim has no recorded payment instruction to allocate against.');
        }
        $instruction = PaymentInstruction::find($claim->payment_instruction_id);
        if (! $instruction) {
            throw new PaymentResourceException('Payment instruction was not found.', 404);
        }
        if ($instruction->status === 'SETTLED') {
            throw new RepositoryConflictException('This payment instruction has already been settled.');
        }

        $requestHash = CommandLedger::requestHash(['instruction_id' => $instruction->id, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'ALLOCATE_REFUND_PAYMENT', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->presentInstruction(PaymentInstruction::find($prior));
        }

        $now = now();
        $requestReference = (string) Str::uuid();

        try {
            $result = $this->connector->allocatePayment([
                'request_reference' => $requestReference, 'payment_instruction_id' => $instruction->id,
                'settlement_reference' => $input['settlement_reference'], 'settled_amount_cents' => $input['settled_amount_cents'],
                'correlation_id' => $correlationId,
            ]);
            DB::transaction(function () use ($instruction, $result, $input, $actor, $now, $idempotencyKey, $requestHash, $correlationId) {
                PaymentInstruction::where('id', $instruction->id)->update([
                    'status' => $result['status'], 'provider_reference' => $result['settlement_reference'] ?? $instruction->provider_reference, 'settled_at' => $now,
                ]);
                CommandLedger::record($actor->id, 'ALLOCATE_REFUND_PAYMENT', $idempotencyKey, $requestHash, 'PAYMENT_INSTRUCTION', $instruction->id, $now);
                CommandLedger::outbox('PAYMENT_INSTRUCTION', $instruction->id, 'PaymentAllocated', $instruction->taxpayer_id, [
                    'payment_instruction_id' => $instruction->id, 'settlement_reference' => $input['settlement_reference'], 'correlation_id' => $correlationId,
                ], $now);
                AuditService::append($actor, 'REFUND_PAYMENT_ALLOCATED', 'PAYMENT_INSTRUCTION', $instruction->id, [
                    'settlementReference' => $input['settlement_reference'], 'settledAmountCents' => $input['settled_amount_cents'], 'correlationId' => $correlationId,
                ], $now);
            });

            return $this->presentInstruction(PaymentInstruction::find($instruction->id));
        } catch (PaymentIntegrationUnavailableException) {
            AuditService::append($actor, 'REFUND_PAYMENT_ALLOCATION_ATTEMPTED', 'PAYMENT_INSTRUCTION', $instruction->id, [
                'settlementReference' => $input['settlement_reference'], 'outcome' => 'AWAITING_AUTHORITY', 'correlationId' => $correlationId,
            ], $now);

            return [
                'payment_instruction_id' => $instruction->id, 'refund_claim_id' => $instruction->refund_claim_id,
                'status' => 'AWAITING_AUTHORITY', 'settlement_reference' => null, 'allocated_at' => $now->toISOString(),
            ];
        }
    }

    /**
     * GetOutstanding: claims that have cleared every review stage
     * (PAYMENT_PENDING) but have no payment_instructions row yet -- the
     * honest queue of what NamRA owes once Payment is authorised. Pure
     * read, restricted to national-scope actors -- no taxpayer-facing "my
     * outstanding refund" view exists yet, so this doesn't invent one.
     *
     * @return array{claims: array<int, array<string, mixed>>, total_outstanding_cents: int, connector: array<string, mixed>}
     */
    public function getOutstanding(User $actor): array
    {
        if (! TenantScope::isNational($actor)) {
            throw new AuthorizationException('The outstanding refund payment queue is restricted to national-scope refund roles.');
        }

        $claims = RefundClaim::with('taxpayer')->where('status', 'PAYMENT_PENDING')->whereNull('payment_instruction_id')
            ->orderBy('approved_at')->get();
        $totalOutstandingCents = $claims->sum(fn (RefundClaim $claim) => $claim->net_payable_cents ?? $claim->amount_cents);

        return [
            'claims' => $claims->map(fn (RefundClaim $claim) => [
                'id' => $claim->id, 'claim_number' => $claim->claim_number, 'legal_name' => $claim->taxpayer?->legal_name,
                'vat_number' => $claim->taxpayer?->vat_number, 'taxpayer_id' => $claim->taxpayer_id,
                'amount_cents' => (int) $claim->amount_cents, 'net_payable_cents' => $claim->net_payable_cents !== null ? (int) $claim->net_payable_cents : null,
                'risk_tier' => $claim->risk_tier, 'requested_at' => optional($claim->requested_at)->toISOString(),
                'approved_at' => optional($claim->approved_at)->toISOString(),
            ])->all(),
            'total_outstanding_cents' => (int) $totalOutstandingCents,
            'connector' => $this->connector->status(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentInstruction(?PaymentInstruction $instruction): array
    {
        if (! $instruction) {
            throw new RepositoryConflictException('The idempotent payment resource is no longer available.');
        }

        return [
            'id' => $instruction->id, 'refund_claim_id' => $instruction->refund_claim_id, 'taxpayer_id' => $instruction->taxpayer_id,
            'amount_cents' => (int) $instruction->amount_cents, 'currency' => $instruction->currency,
            'beneficiary_reference_masked' => $instruction->beneficiary_reference_masked, 'provider' => $instruction->provider,
            'status' => $instruction->status, 'provider_reference' => $instruction->provider_reference,
            'approved_at' => optional($instruction->approved_at)->toISOString(), 'submitted_at' => optional($instruction->submitted_at)->toISOString(),
            'settled_at' => optional($instruction->settled_at)->toISOString(),
        ];
    }
}
