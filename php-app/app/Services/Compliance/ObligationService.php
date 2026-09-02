<?php

namespace App\Services\Compliance;

use App\Domain\Compliance\ComplianceValidator;
use App\Exceptions\ComplianceResourceException;
use App\Exceptions\RepositoryConflictException;
use App\Models\TaxObligation;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Access\TenantScope;
use App\Support\Business\CommandLedger;
use App\Support\Compliance\NotificationRecorder;
use App\Support\Compliance\TaxpayerResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Ported from lib/data/compliance-repository.ts's createObligation/markObligationSatisfied -- Module 3 Phase D. */
class ObligationService
{
    public function __construct(private readonly TaxpayerResolver $taxpayers) {}

    /** @return array<string, mixed> */
    public function create(array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        if (! TenantScope::isNational($actor)) {
            throw new AuthorizationException('Only an authorised national compliance role may create a tax obligation.');
        }
        $input = ComplianceValidator::obligationCreation($payload);
        $scope = $this->taxpayers->resolve($actor, $input['taxpayer_id']);
        $requestHash = CommandLedger::requestHash($input);
        $prior = CommandLedger::prior($actor->id, 'CREATE_OBLIGATION', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->present($this->findOrFail($prior));
        }
        $existing = TaxObligation::where('taxpayer_id', $scope['taxpayer_id'])->where('obligation_type', $input['obligation_type'])->where('period_code', $input['period_code'])->first();
        if ($existing) {
            throw new RepositoryConflictException("An obligation for this taxpayer, type and period already exists as {$existing->id}.");
        }

        $id = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($input, $scope, $actor, $id, $now, $idempotencyKey, $requestHash, $correlationId) {
            TaxObligation::create([
                'id' => $id, 'organisation_id' => $scope['organisation_id'], 'taxpayer_id' => $scope['taxpayer_id'],
                'obligation_type' => $input['obligation_type'], 'period_code' => $input['period_code'], 'due_date' => $input['due_date'],
                'amount_cents' => $input['amount_cents'], 'currency' => $input['currency'], 'status' => 'PENDING',
                'source_system' => 'VAT_MSA', 'source_reference' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            NotificationRecorder::record(null, $scope['taxpayer_id'], 'OBLIGATION_CREATED', "New {$input['obligation_type']} obligation for {$input['period_code']}", "Due {$input['due_date']}.", 'MEDIUM', '/compliance', $now);
            CommandLedger::record($actor->id, 'CREATE_OBLIGATION', $idempotencyKey, $requestHash, 'TAX_OBLIGATION', $id, $now);
            CommandLedger::outbox('TAX_OBLIGATION', $id, 'ObligationCreated', $scope['taxpayer_id'], ['obligation_id' => $id, 'obligation_type' => $input['obligation_type'], 'period_code' => $input['period_code'], 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'OBLIGATION_CREATED', 'TAX_OBLIGATION', $id, ['taxpayerId' => $scope['taxpayer_id'], 'obligationType' => $input['obligation_type'], 'periodCode' => $input['period_code'], 'correlationId' => $correlationId], $now);
        });

        return $this->present($this->findOrFail($id));
    }

    /** Idempotent on an already-satisfied obligation. @return array<string, mixed> */
    public function markSatisfied(string $obligationId, array $payload, User $actor, string $idempotencyKey, string $correlationId): array
    {
        CommandLedger::validateIdempotencyKey($idempotencyKey);
        if (! TenantScope::isNational($actor)) {
            throw new AuthorizationException('Only an authorised national compliance role may mark a tax obligation satisfied.');
        }
        $input = ComplianceValidator::obligationSatisfaction($payload);
        $obligation = TaxObligation::find($obligationId);
        if (! $obligation) {
            throw new ComplianceResourceException('Tax obligation was not found.', 404);
        }
        $requestHash = CommandLedger::requestHash(['obligation_id' => $obligation->id, 'input' => $input]);
        $prior = CommandLedger::prior($actor->id, 'MARK_OBLIGATION_SATISFIED', $idempotencyKey, $requestHash);
        if ($prior) {
            return $this->present($this->findOrFail($prior));
        }
        if ($obligation->status === 'SATISFIED') {
            return $this->present($obligation);
        }

        $now = now();
        DB::transaction(function () use ($obligation, $actor, $now, $idempotencyKey, $requestHash, $correlationId, $input) {
            TaxObligation::where('id', $obligation->id)->update(['status' => 'SATISFIED', 'updated_at' => $now]);
            CommandLedger::record($actor->id, 'MARK_OBLIGATION_SATISFIED', $idempotencyKey, $requestHash, 'TAX_OBLIGATION', $obligation->id, $now);
            CommandLedger::outbox('TAX_OBLIGATION', $obligation->id, 'ObligationSatisfied', $obligation->taxpayer_id, ['obligation_id' => $obligation->id, 'notes' => $input['notes'], 'correlation_id' => $correlationId], $now);
            AuditService::append($actor, 'OBLIGATION_SATISFIED', 'TAX_OBLIGATION', $obligation->id, ['notes' => $input['notes'], 'correlationId' => $correlationId], $now);
        });

        return $this->present($this->findOrFail($obligation->id));
    }

    /** @return array<string, mixed> */
    public function search(User $actor, array $params): array
    {
        $query = TaxObligation::query();
        if (! TenantScope::isNational($actor)) {
            $query->where('taxpayer_id', $actor->taxpayer_id ?? '__none__');
        }
        if (! empty($params['status'])) {
            $query->where('status', $params['status']);
        }
        $obligations = $query->orderByDesc('due_date')->limit(100)->get();

        return ['obligations' => $obligations->map(fn (TaxObligation $o) => $this->present($o))->values()->all()];
    }

    private function findOrFail(string $id): TaxObligation
    {
        $obligation = TaxObligation::find($id);
        if (! $obligation) {
            throw new ComplianceResourceException('Tax obligation was not found.', 404);
        }

        return $obligation;
    }

    /** @return array<string, mixed> */
    private function present(TaxObligation $obligation): array
    {
        return [
            'id' => $obligation->id, 'organisation_id' => $obligation->organisation_id, 'taxpayer_id' => $obligation->taxpayer_id,
            'obligation_type' => $obligation->obligation_type, 'period_code' => $obligation->period_code, 'due_date' => $obligation->due_date->toDateString(),
            'amount_cents' => (int) $obligation->amount_cents, 'currency' => $obligation->currency, 'status' => $obligation->status,
            'source_system' => $obligation->source_system, 'source_reference' => $obligation->source_reference,
            'created_at' => optional($obligation->created_at)->toISOString(), 'updated_at' => optional($obligation->updated_at)->toISOString(),
        ];
    }
}
