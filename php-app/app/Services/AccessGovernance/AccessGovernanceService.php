<?php

namespace App\Services\AccessGovernance;

use App\Domain\AccessGovernance\AccessGovernanceValidator;
use App\Exceptions\LicensingValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Models\AccessCertification;
use App\Models\AccessRequest;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Licensing\EntitlementGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/control-plane-repository.ts's requestRoleAccess/
 * decideAccessRequest/certifyQuarterlyAccess/revokeAccessGrant/
 * offboardUser -- Phase 12 slice 4, the rest of Access governance
 * (openQuarterlyAccessReview itself was pulled forward into Phase 12
 * slice 2 already, as assertEntitledOperation's own ADMIN_WRITE
 * prerequisite -- see App\Services\OrganisationAdmin\
 * OrganisationAdminService::openQuarterlyAccessReview()).
 * `getAdministrationSnapshot` (the fixed-list dashboard aggregate every
 * GET-list route in this whole file bundles into, this one included) and
 * `searchWorkspace` (a small, genuinely separate `/api/v1/search` route,
 * not part of Access governance) remain deferred -- see
 * docs/MIGRATION_MATRIX.md.
 */
class AccessGovernanceService
{
    /**
     * Maker-checker request for one existing organisation-defined custom
     * role -- `subject_user_id` defaults to the requester's own id (a
     * self-request), matching the source exactly.
     *
     * @return array<string, mixed>
     */
    public function requestRoleAccess(array $payload, User $actor, ?string $requestedOrganisationId): array
    {
        ['organisation' => $organisation] = EntitlementGate::assert($actor, 'ADVANCED_WORKFLOW', 'BUSINESS_WRITE', 0, $requestedOrganisationId);

        $subjectUserId = trim((string) ($payload['subject_user_id'] ?? $actor->id));
        $roleId = trim((string) ($payload['role_id'] ?? ''));
        $justification = trim((string) ($payload['justification'] ?? ''));
        if (mb_strlen($justification) < 10 || mb_strlen($justification) > 400) {
            throw new LicensingValidationException('JUSTIFICATION_REQUIRED', 'Provide a 10 to 400 character access justification.');
        }

        $subjectIsMember = DB::table('organisation_memberships')->where('organisation_id', $organisation->id)
            ->where('user_id', $subjectUserId)->where('status', 'ACTIVE')->exists();
        $roleIsActive = DB::table('organisation_roles')->where('organisation_id', $organisation->id)
            ->where('id', $roleId)->where('status', 'ACTIVE')->exists();
        if (! $subjectIsMember || ! $roleIsActive) {
            throw new LicensingValidationException('ACCESS_REFERENCE_INVALID', 'The subject or role is outside the active organisation.');
        }

        $id = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($id, $organisation, $actor, $subjectUserId, $roleId, $justification, $now) {
            AccessRequest::create([
                'id' => $id, 'organisation_id' => $organisation->id, 'requested_by' => $actor->id, 'subject_user_id' => $subjectUserId,
                'organisation_role_id' => $roleId, 'justification' => $justification, 'status' => 'PENDING_MANAGER',
                'requested_at' => $now, 'completed_at' => null,
            ]);
            AuditService::append($actor, 'ACCESS_REQUESTED', 'ACCESS_REQUEST', $id, ['organisationId' => $organisation->id, 'subjectUserId' => $subjectUserId, 'roleId' => $roleId], $now);
        });

        return ['id' => $id, 'status' => 'PENDING_MANAGER', 'requestedAt' => $now->toISOString()];
    }

    /** @return array<string, mixed> */
    public function decideAccessRequest(string $requestId, array $payload, User $actor, ?string $requestedOrganisationId): array
    {
        ['organisation' => $organisation] = EntitlementGate::assert($actor, 'ADVANCED_WORKFLOW', 'ADMIN_WRITE', 0, $requestedOrganisationId);

        $decision = mb_strtoupper(trim((string) ($payload['decision'] ?? '')));
        if (! in_array($decision, ['APPROVE', 'REJECT'], true)) {
            throw new LicensingValidationException('DECISION_INVALID', 'Access decisions must be APPROVE or REJECT.');
        }
        $reason = trim((string) ($payload['reason'] ?? ''));
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 240) {
            throw new LicensingValidationException('REASON_REQUIRED', 'Provide a 5 to 240 character decision reason.');
        }

        $access = AccessRequest::where('id', $requestId)->where('organisation_id', $organisation->id)->first();
        if (! $access) {
            throw new LicensingValidationException('ACCESS_REQUEST_NOT_FOUND', 'The access request is outside the active organisation.');
        }
        if ($access->status !== 'PENDING_MANAGER') {
            throw new RepositoryConflictException('The access request has already been decided.');
        }
        if ($actor->id === $access->requested_by || $actor->id === $access->subject_user_id) {
            throw new LicensingValidationException('SELF_APPROVAL_DENIED', 'A requester or access subject cannot approve their own access request.');
        }

        $now = now();
        $status = $decision === 'APPROVE' ? 'APPROVED' : 'REJECTED';
        DB::transaction(function () use ($access, $actor, $decision, $reason, $status, $organisation, $now) {
            DB::table('access_approvals')->insert([
                'id' => (string) Str::uuid(), 'access_request_id' => $access->id, 'reviewer_id' => $actor->id,
                'reviewer_stage' => 'MANAGER', 'decision' => $decision, 'reason' => $reason, 'decided_at' => $now,
            ]);
            $access->update(['status' => $status, 'completed_at' => $now]);
            AuditService::append($actor, "ACCESS_{$decision}", 'ACCESS_REQUEST', $access->id, ['organisationId' => $organisation->id, 'reason' => $reason], $now);
            if ($decision === 'APPROVE') {
                DB::table('user_role_assignments')->insert([
                    'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'user_id' => $access->subject_user_id,
                    'employee_id' => null, 'organisation_role_id' => $access->organisation_role_id, 'status' => 'ACTIVE',
                    'effective_from' => $now, 'effective_to' => null, 'assigned_by' => $actor->id, 'created_at' => $now,
                ]);
            }
        });

        return ['id' => $access->id, 'status' => $status, 'decidedAt' => $now->toISOString()];
    }

    /**
     * A subject is certified RETAIN (kept as-is) or REVOKE (every active
     * role/capability grant and the membership itself revoked, mirroring
     * `offboardUser`'s own bulk revocation). The review auto-completes
     * once every active member of the organisation has a certification
     * row -- the count comparison below, run after this certification's
     * own insert so it sees its own row, exactly matching the source's
     * single sequential `db.batch()`.
     *
     * @return array<string, mixed>
     */
    public function certifyQuarterlyAccess(string $reviewId, array $payload, User $actor, ?string $requestedOrganisationId): array
    {
        ['organisation' => $organisation] = EntitlementGate::assert($actor, 'ADMINISTRATION', 'COMPLIANCE_WRITE', 0, $requestedOrganisationId);

        $subjectUserId = trim((string) ($payload['subject_user_id'] ?? ''));
        $disposition = mb_strtoupper(trim((string) ($payload['disposition'] ?? '')));
        $finding = trim((string) ($payload['finding'] ?? ''));
        if (! in_array($disposition, ['RETAIN', 'REVOKE'], true)) {
            throw new LicensingValidationException('DISPOSITION_INVALID', 'Access certification must RETAIN or REVOKE access.');
        }
        if ($actor->id === $subjectUserId) {
            throw new LicensingValidationException('SELF_CERTIFICATION_DENIED', 'Users cannot certify their own quarterly access.');
        }
        if (mb_strlen($finding) > 400) {
            throw new LicensingValidationException('FINDING_INVALID', 'Access-review findings cannot exceed 400 characters.');
        }

        $review = DB::table('access_reviews')->where('id', $reviewId)->where('organisation_id', $organisation->id)
            ->where('review_type', 'QUARTERLY')->first();
        $subject = DB::table('organisation_memberships')->where('organisation_id', $organisation->id)
            ->where('user_id', $subjectUserId)->where('status', 'ACTIVE')->first(['user_id', 'role_code']);
        $roleNames = DB::table('user_role_assignments as a')->join('organisation_roles as r', 'r.id', '=', 'a.organisation_role_id')
            ->where('a.organisation_id', $organisation->id)->where('a.user_id', $subjectUserId)->where('a.status', 'ACTIVE')
            ->pluck('r.name')->all();
        $administratorRoleCodes = DB::table('organisation_administrators')->where('organisation_id', $organisation->id)
            ->where('user_id', $subjectUserId)->where('status', 'ACTIVE')->pluck('administrator_role_code')->all();

        if (! $review || $review->status !== 'OPEN') {
            throw new RepositoryConflictException('The quarterly access review is not open.');
        }
        if (! $subject) {
            throw new LicensingValidationException('SUBJECT_NOT_ACTIVE', 'The certification subject has no active organisation membership.');
        }

        $now = now();
        $id = (string) Str::uuid();
        $snapshot = json_encode(['baseRole' => $subject->role_code, 'organisationRoles' => $roleNames, 'administratorRoles' => $administratorRoleCodes]);

        DB::transaction(function () use ($id, $review, $organisation, $subjectUserId, $actor, $snapshot, $disposition, $finding, $now) {
            AccessCertification::create([
                'id' => $id, 'access_review_id' => $review->id, 'organisation_id' => $organisation->id, 'subject_user_id' => $subjectUserId,
                'reviewer_id' => $actor->id, 'snapshot' => $snapshot, 'disposition' => $disposition, 'finding' => $finding !== '' ? $finding : null,
                'certified_at' => $now,
            ]);

            $certifiedCount = AccessCertification::where('access_review_id', $review->id)->count();
            $activeMemberCount = DB::table('organisation_memberships')->where('organisation_id', $organisation->id)->where('status', 'ACTIVE')->count();
            if ($certifiedCount >= $activeMemberCount) {
                DB::table('access_reviews')->where('id', $review->id)->update(['status' => 'COMPLETED', 'completed_at' => $now]);
            }

            AuditService::append($actor, "ACCESS_CERTIFIED_{$disposition}", 'ACCESS_REVIEW', $review->id, ['organisationId' => $organisation->id, 'subjectUserId' => $subjectUserId, 'finding' => $finding], $now);

            if ($disposition === 'REVOKE') {
                DB::table('organisation_memberships')->where('organisation_id', $organisation->id)->where('user_id', $subjectUserId)->where('status', 'ACTIVE')
                    ->update(['status' => 'REVOKED', 'valid_to' => $now]);
                DB::table('user_role_assignments')->where('organisation_id', $organisation->id)->where('user_id', $subjectUserId)->where('status', 'ACTIVE')
                    ->update(['status' => 'REVOKED', 'effective_to' => $now]);
                DB::table('user_capability_assignments')->where('organisation_id', $organisation->id)->where('user_id', $subjectUserId)->where('status', 'ACTIVE')
                    ->update(['status' => 'REVOKED', 'effective_to' => $now]);
            }
        });

        return ['id' => $id, 'reviewId' => $review->id, 'subjectUserId' => $subjectUserId, 'disposition' => $disposition, 'certifiedAt' => $now->toISOString()];
    }

    /**
     * Revokes one specific already-granted role (user_role_assignments,
     * from decideAccessRequest's APPROVE branch) or capability
     * (user_capability_assignments, from OrganisationAdminService::
     * grantCapability) on demand -- the narrow, single-grant counterpart
     * to certifyQuarterlyAccess's REVOKE disposition and offboardUser's
     * own bulk revocation, neither of which lets an administrator walk
     * back just one grant without touching everything else.
     *
     * @return array<string, mixed>
     */
    public function revokeAccessGrant(array $payload, User $actor, ?string $requestedOrganisationId): array
    {
        $revocation = AccessGovernanceValidator::accessRevocation($payload);
        ['organisation' => $organisation] = EntitlementGate::assert($actor, 'ADMINISTRATION', 'ADMIN_WRITE', 0, $requestedOrganisationId);

        $table = $revocation['grantType'] === 'ROLE' ? 'user_role_assignments' : 'user_capability_assignments';
        $resourceType = $revocation['grantType'] === 'ROLE' ? 'USER_ROLE_ASSIGNMENT' : 'USER_CAPABILITY_ASSIGNMENT';

        $grant = DB::table($table)->where('id', $revocation['grantId'])->where('organisation_id', $organisation->id)->first(['id', 'user_id', 'status']);
        if (! $grant) {
            throw new LicensingValidationException('GRANT_NOT_FOUND', 'The access grant is outside the active organisation.');
        }
        if ($actor->id === $grant->user_id) {
            throw new LicensingValidationException('SELF_REVOCATION_DENIED', 'You cannot revoke your own access grant.');
        }
        if ($grant->status !== 'ACTIVE') {
            return ['id' => $grant->id, 'grantType' => $revocation['grantType'], 'userId' => $grant->user_id, 'status' => $grant->status];
        }

        $now = now();
        DB::transaction(function () use ($table, $resourceType, $grant, $actor, $organisation, $revocation, $now) {
            DB::table($table)->where('id', $grant->id)->update(['status' => 'REVOKED', 'effective_to' => $now]);
            AuditService::append($actor, 'ACCESS_REVOKED', $resourceType, $grant->id, [
                'organisationId' => $organisation->id, 'userId' => $grant->user_id, 'grantType' => $revocation['grantType'], 'reason' => $revocation['reason'],
            ], $now);
        });

        return ['id' => $grant->id, 'grantType' => $revocation['grantType'], 'userId' => $grant->user_id, 'status' => 'REVOKED'];
    }

    /**
     * Revokes every active role/capability grant and the organisation
     * membership itself for one user, immediately -- the access-only
     * counterpart to `OrganisationAdminService::terminateEmployee()`
     * (which also ends the employment record and a licence seat) and to
     * `certifyQuarterlyAccess`'s REVOKE disposition (gated behind an open
     * review reaching full completion across every active member). For a
     * security incident or an access-only exit where the employment/
     * licence side should stay untouched. Idempotent: a user with no
     * active membership, role, or capability grant left is a no-op, not
     * an error.
     *
     * @return array<string, mixed>
     */
    public function offboardUser(array $payload, User $actor, ?string $requestedOrganisationId): array
    {
        $offboarding = AccessGovernanceValidator::offboarding($payload);
        ['organisation' => $organisation] = EntitlementGate::assert($actor, 'ADMINISTRATION', 'ADMIN_WRITE', 0, $requestedOrganisationId);
        if ($actor->id === $offboarding['userId']) {
            throw new LicensingValidationException('SELF_OFFBOARD_DENIED', 'You cannot offboard your own access.');
        }

        $membershipExists = DB::table('organisation_memberships')->where('organisation_id', $organisation->id)
            ->where('user_id', $offboarding['userId'])->where('status', 'ACTIVE')->exists();
        $roleAssignmentsRevoked = DB::table('user_role_assignments')->where('organisation_id', $organisation->id)
            ->where('user_id', $offboarding['userId'])->where('status', 'ACTIVE')->count();
        $capabilityAssignmentsRevoked = DB::table('user_capability_assignments')->where('organisation_id', $organisation->id)
            ->where('user_id', $offboarding['userId'])->where('status', 'ACTIVE')->count();

        if (! $membershipExists && $roleAssignmentsRevoked === 0 && $capabilityAssignmentsRevoked === 0) {
            return [
                'userId' => $offboarding['userId'], 'organisationId' => $organisation->id, 'membershipRevoked' => false,
                'roleAssignmentsRevoked' => 0, 'capabilityAssignmentsRevoked' => 0,
            ];
        }

        $now = now();
        DB::transaction(function () use ($organisation, $offboarding, $actor, $roleAssignmentsRevoked, $capabilityAssignmentsRevoked, $now) {
            DB::table('organisation_memberships')->where('organisation_id', $organisation->id)->where('user_id', $offboarding['userId'])->where('status', 'ACTIVE')
                ->update(['status' => 'REVOKED', 'valid_to' => $now]);
            DB::table('user_role_assignments')->where('organisation_id', $organisation->id)->where('user_id', $offboarding['userId'])->where('status', 'ACTIVE')
                ->update(['status' => 'REVOKED', 'effective_to' => $now]);
            DB::table('user_capability_assignments')->where('organisation_id', $organisation->id)->where('user_id', $offboarding['userId'])->where('status', 'ACTIVE')
                ->update(['status' => 'REVOKED', 'effective_to' => $now]);
            AuditService::append($actor, 'USER_OFFBOARDED', 'ORGANISATION_MEMBERSHIP', $offboarding['userId'], [
                'organisationId' => $organisation->id, 'reason' => $offboarding['reason'],
                'roleAssignmentsRevoked' => $roleAssignmentsRevoked, 'capabilityAssignmentsRevoked' => $capabilityAssignmentsRevoked,
            ], $now);
        });

        return [
            'userId' => $offboarding['userId'], 'organisationId' => $organisation->id, 'membershipRevoked' => $membershipExists,
            'roleAssignmentsRevoked' => $roleAssignmentsRevoked, 'capabilityAssignmentsRevoked' => $capabilityAssignmentsRevoked,
        ];
    }
}
