<?php

namespace App\Services\Identity;

use App\Exceptions\RepositoryConflictException;
use App\Models\Branch;
use App\Models\OrganisationMembership;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Ported from lib/data/identity-repository.ts's assignMembership. */
class MembershipService
{
    public function __construct(private readonly OrganisationService $organisations) {}

    public function assign(User $actor, string $organisationId, array $assignment, string $correlationId): OrganisationMembership
    {
        $organisation = $this->organisations->requireInScope($actor, $organisationId);

        $targetUser = User::find($assignment['user_id']);
        if (! $targetUser) {
            throw ValidationException::withMessages(['user_id' => 'The target user does not exist.']);
        }
        if (! $targetUser->isActive()) {
            throw ValidationException::withMessages(['user_id' => 'The target user is not active.']);
        }
        // Red-team finding RT-001 (2026-10-24): source's own assignMembership
        // only ever sets taxpayer_id when it is still NULL ("UPDATE app_users
        // SET taxpayer_id=? WHERE id=? AND taxpayer_id IS NULL") -- correct
        // for its actual intended case, a user who doesn't belong to a
        // taxpayer yet, but silently no-op for a user who already belongs to
        // a DIFFERENT one. Every scope check in this app (TaxpayerScope::
        // requireTaxpayer, matching source's own requireTaxpayerScope) reads
        // only that single taxpayer_id field, never this membership row, so
        // that silent no-op previously left the membership row created, the
        // UI reporting success, and the assignee with zero real access to
        // this organisation -- confirmed live (403 on direct navigation,
        // absent from their own organisations list, role badge unchanged).
        // Reject it explicitly instead, the same way the sibling "not
        // found"/"not active" guards above already do, rather than silently
        // accepting an assignment this app has no mechanism to honour.
        if ($targetUser->taxpayer_id !== null && $targetUser->taxpayer_id !== $organisation->taxpayer_id) {
            throw ValidationException::withMessages(['user_id' => 'This user already belongs to a different taxpayer organisation and cannot be assigned here.']);
        }
        if (! empty($assignment['branch_id']) && ! Branch::where('id', $assignment['branch_id'])->where('organisation_id', $organisationId)->exists()) {
            throw ValidationException::withMessages(['branch_id' => 'The branch is outside this organisation.']);
        }
        if (OrganisationMembership::where('organisation_id', $organisationId)->where('user_id', $assignment['user_id'])->where('status', 'ACTIVE')->exists()) {
            throw new RepositoryConflictException('The user already has an active membership in this organisation.');
        }

        $now = now();

        return DB::transaction(function () use ($organisation, $organisationId, $assignment, $actor, $now, $correlationId) {
            $membership = OrganisationMembership::create([
                'id' => (string) Str::uuid(), 'organisation_id' => $organisationId, 'user_id' => $assignment['user_id'],
                'role_code' => $assignment['role_code'], 'branch_id' => $assignment['branch_id'] ?? null,
                'status' => 'ACTIVE', 'valid_from' => $now, 'assigned_by' => $actor->id, 'created_at' => $now,
            ]);

            User::where('id', $assignment['user_id'])->whereNull('taxpayer_id')->update(['taxpayer_id' => $organisation->taxpayer_id]);

            OutboxEvent::create([
                'id' => (string) Str::uuid(), 'aggregate_type' => 'ORGANISATION', 'aggregate_id' => $organisationId,
                'event_type' => 'OrganisationMembershipAssigned', 'event_version' => 1, 'partition_key' => $organisation->taxpayer_id,
                'payload' => AuditService::canonicalJson(['organisation_id' => $organisationId, 'user_id' => $assignment['user_id'], 'role_code' => $assignment['role_code'], 'correlation_id' => $correlationId]),
                'status' => 'PENDING', 'occurred_at' => $now, 'available_at' => $now,
            ]);
            AuditService::append($actor, 'MEMBERSHIP_ASSIGNED', 'ORGANISATION_MEMBERSHIP', $membership->id, ['organisationId' => $organisationId, 'userId' => $assignment['user_id'], 'roleCode' => $assignment['role_code']], $now);

            return $membership;
        });
    }
}
