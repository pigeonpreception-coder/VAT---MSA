<?php

namespace App\Services\Identity;

use App\Exceptions\RepositoryConflictException;
use App\Models\Branch;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Ported from lib/data/identity-repository.ts's listBranches/createBranch/updateBranch. */
class BranchService
{
    public function __construct(private readonly OrganisationService $organisations) {}

    public function list(User $actor, string $organisationId): Collection
    {
        $this->organisations->requireInScope($actor, $organisationId);
        return Branch::where('organisation_id', $organisationId)->orderByDesc('is_head_office')->orderBy('name')->get();
    }

    public function create(User $actor, string $organisationId, array $input, string $correlationId): Branch
    {
        $organisation = $this->organisations->requireInScope($actor, $organisationId);

        if (Branch::where('organisation_id', $organisationId)->where('code', $input['code'])->exists()) {
            throw new RepositoryConflictException("A branch with code {$input['code']} already exists for this organisation.");
        }

        $now = now();

        return DB::transaction(function () use ($organisation, $organisationId, $input, $actor, $now, $correlationId) {
            $branch = Branch::create([
                'id' => (string) Str::uuid(), 'organisation_id' => $organisationId,
                'code' => $input['code'], 'name' => $input['name'], 'address' => $input['address'],
                'status' => 'ACTIVE', 'is_head_office' => false,
            ]);

            OutboxEvent::create([
                'id' => (string) Str::uuid(), 'aggregate_type' => 'ORGANISATION', 'aggregate_id' => $organisationId,
                'event_type' => 'BranchCreated', 'event_version' => 1, 'partition_key' => $organisation->taxpayer_id,
                'payload' => AuditService::canonicalJson(['organisation_id' => $organisationId, 'branch_id' => $branch->id, 'code' => $input['code'], 'correlation_id' => $correlationId]),
                'status' => 'PENDING', 'occurred_at' => $now, 'available_at' => $now,
            ]);
            AuditService::append($actor, 'BRANCH_CREATED', 'BRANCH', $branch->id, ['organisationId' => $organisationId, 'code' => $input['code']], $now);

            return $branch;
        });
    }

    public function update(User $actor, string $organisationId, string $branchId, array $update, string $correlationId): Branch
    {
        $organisation = $this->organisations->requireInScope($actor, $organisationId);

        $branch = Branch::where('id', $branchId)->where('organisation_id', $organisationId)->first();
        if (! $branch) {
            throw ValidationException::withMessages(['branch_id' => 'The branch is outside this organisation.']);
        }
        if (($update['status'] ?? null) === 'INACTIVE' && $branch->is_head_office) {
            throw ValidationException::withMessages(['status' => 'The head office branch cannot be deactivated.']);
        }

        $now = now();

        return DB::transaction(function () use ($organisation, $organisationId, $branch, $update, $actor, $now, $correlationId) {
            $branch->update([
                'name' => $update['name'] ?? $branch->name,
                'address' => $update['address'] ?? $branch->address,
                'status' => $update['status'] ?? $branch->status,
            ]);

            OutboxEvent::create([
                'id' => (string) Str::uuid(), 'aggregate_type' => 'ORGANISATION', 'aggregate_id' => $organisationId,
                'event_type' => 'BranchUpdated', 'event_version' => 1, 'partition_key' => $organisation->taxpayer_id,
                'payload' => AuditService::canonicalJson(['organisation_id' => $organisationId, 'branch_id' => $branch->id, 'changes' => $update, 'correlation_id' => $correlationId]),
                'status' => 'PENDING', 'occurred_at' => $now, 'available_at' => $now,
            ]);
            AuditService::append($actor, 'BRANCH_UPDATED', 'BRANCH', $branch->id, ['organisationId' => $organisationId, 'changes' => $update], $now);

            return $branch->fresh();
        });
    }
}
