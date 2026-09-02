<?php

namespace App\Services\Compliance;

use App\Domain\Compliance\ComplianceValidator;
use App\Exceptions\ComplianceResourceException;
use App\Models\AuditCase;
use App\Models\Dispute;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Access\TenantScope;
use App\Support\Business\CommandLedger;
use App\Support\Compliance\NotificationRecorder;
use App\Support\Compliance\TaxpayerResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/compliance-repository.ts's fileDispute. Unlike
 * obligations (NamRA-imposed, national-scope-only), a taxpayer may
 * self-file a dispute against their own case/finding/return/decision --
 * taxpayer_id is optional in the payload (defaults to the actor's own
 * scope) and no national-scope restriction applies. `disputed_resource_id`
 * is never validated against any actual table (matching the source
 * exactly): it can reference an audit finding, VAT return, refund
 * decision, or obligation, and the source itself never cross-checks
 * which.
 */
class DisputeService
{
    public function __construct(private readonly TaxpayerResolver $taxpayers) {}

    /** @return array<string, mixed> */
    public function file(array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        $input = ComplianceValidator::dispute($payload);
        $scope = $this->taxpayers->resolve($actor, $input['taxpayer_id']);
        if ($input['audit_case_id']) {
            $auditCase = AuditCase::where('id', $input['audit_case_id'])->where('taxpayer_id', $scope['taxpayer_id'])->first();
            if (! $auditCase) {
                throw new ComplianceResourceException('Audit case is not in the authorised taxpayer scope.');
            }
        }
        $requestHash = CommandLedger::requestHash(['taxpayer_id' => $scope['taxpayer_id'], 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'FILE_DISPUTE', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->present($this->findOrFail($prior));
        }

        $id = (string) Str::uuid();
        $disputeNumber = 'DSP-'.now()->format('Y').'-'.mb_strtoupper(mb_substr(str_replace('-', '', $id), 0, 8));
        $now = now();
        DB::transaction(function () use ($input, $scope, $actor, $id, $disputeNumber, $now, $idempotencyKey, $requestHash, $correlationId) {
            Dispute::create([
                'id' => $id, 'dispute_number' => $disputeNumber, 'organisation_id' => $scope['organisation_id'], 'taxpayer_id' => $scope['taxpayer_id'],
                'audit_case_id' => $input['audit_case_id'], 'disputed_resource_type' => $input['disputed_resource_type'], 'disputed_resource_id' => $input['disputed_resource_id'],
                'grounds' => $input['grounds'], 'disputed_amount_cents' => $input['disputed_amount_cents'], 'currency' => $input['currency'],
                'status' => 'FILED', 'filed_by' => $actor->id, 'assigned_officer_id' => null, 'filed_at' => $now, 'decided_at' => null, 'decision_summary' => null,
            ]);
            NotificationRecorder::record(null, $scope['taxpayer_id'], 'DISPUTE_FILED', "Dispute {$disputeNumber} filed", 'The dispute is awaiting independent assignment and review.', 'MEDIUM', '/compliance', $now);
            CommandLedger::record($actor->id, 'FILE_DISPUTE', $idempotencyKey, $requestHash, 'DISPUTE', $id, $now);
            CommandLedger::outbox('DISPUTE', $id, 'DisputeFiled', $scope['taxpayer_id'], ['dispute_id' => $id, 'dispute_number' => $disputeNumber, 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'DISPUTE_FILED', 'DISPUTE', $id, ['disputeNumber' => $disputeNumber, 'taxpayerId' => $scope['taxpayer_id'], 'correlationId' => $correlationId], $now);
        });

        return $this->present($this->findOrFail($id));
    }

    /** @return array<string, mixed> */
    public function search(User $actor, array $params): array
    {
        $query = Dispute::query();
        if (! TenantScope::isNational($actor)) {
            $query->where('taxpayer_id', $actor->taxpayer_id ?? '__none__');
        }
        if (! empty($params['status'])) {
            $query->where('status', $params['status']);
        }
        $disputes = $query->orderByDesc('filed_at')->limit(100)->get();

        return ['disputes' => $disputes->map(fn (Dispute $d) => $this->present($d))->values()->all()];
    }

    private function findOrFail(string $id): Dispute
    {
        $dispute = Dispute::find($id);
        if (! $dispute) {
            throw new ComplianceResourceException('Dispute was not found.', 404);
        }

        return $dispute;
    }

    /** @return array<string, mixed> */
    private function present(Dispute $dispute): array
    {
        return [
            'id' => $dispute->id, 'dispute_number' => $dispute->dispute_number, 'organisation_id' => $dispute->organisation_id,
            'taxpayer_id' => $dispute->taxpayer_id, 'audit_case_id' => $dispute->audit_case_id, 'disputed_resource_type' => $dispute->disputed_resource_type,
            'disputed_resource_id' => $dispute->disputed_resource_id, 'grounds' => $dispute->grounds, 'disputed_amount_cents' => (int) $dispute->disputed_amount_cents,
            'currency' => $dispute->currency, 'status' => $dispute->status, 'filed_by' => $dispute->filed_by, 'assigned_officer_id' => $dispute->assigned_officer_id,
            'filed_at' => optional($dispute->filed_at)->toISOString(), 'decided_at' => optional($dispute->decided_at)->toISOString(), 'decision_summary' => $dispute->decision_summary,
        ];
    }
}
