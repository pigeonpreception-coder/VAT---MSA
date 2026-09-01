<?php

namespace App\Services\OrganisationAdmin;

use App\Domain\Licensing\AccessReviewWindow;
use App\Domain\OrganisationAdmin\OrganisationAdminValidator;
use App\Exceptions\LicensingValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Models\AccessPermission;
use App\Models\AccessReview;
use App\Models\Employee;
use App\Models\OrganisationAdministrator;
use App\Models\OrganisationAdministratorRole;
use App\Models\OrganisationRole;
use App\Models\OrganisationRolePermission;
use App\Models\OutboxEvent;
use App\Models\User;
use App\Models\UserCapabilityAssignment;
use App\Services\Audit\AuditService;
use App\Support\Licensing\EntitlementGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/control-plane-repository.ts's inviteEmployee/
 * activateEmployee/terminateEmployee/appointAdministrator/
 * createOrganisationRole/listCapabilityGrants/grantCapability/
 * openQuarterlyAccessReview -- Phase 12 slice 2 (organisation
 * administration/employees), closing out "the rest of Phase 8" (employees,
 * organisation-defined custom roles) alongside it. `getAdministrationSnapshot`
 * (the fixed-list dashboard aggregate, pulling in workflow/access-request
 * tables this slice doesn't build) and `certifyQuarterlyAccess` (the
 * review's own completion path, with its bulk role/capability revocation)
 * remain deferred -- see docs/MIGRATION_MATRIX.md.
 */
class OrganisationAdminService
{
    /** @return array<string, mixed> */
    public function inviteEmployee(array $payload, User $actor, ?string $requestedOrganisationId): array
    {
        $employee = OrganisationAdminValidator::employee($payload);
        ['organisation' => $organisation, 'license' => $license] = EntitlementGate::assert($actor, 'USER_SEATS', 'ADMIN_WRITE', 1, $requestedOrganisationId);

        $duplicate = Employee::where('organisation_id', $organisation->id)
            ->where(fn ($q) => $q->where('employee_number', $employee['employeeNumber'])->orWhereRaw('lower(email) = lower(?)', [$employee['email']]))
            ->exists();
        if ($duplicate) {
            throw new RepositoryConflictException('An employee with this number or email already exists.');
        }
        foreach ([['departments', $employee['departmentId']], ['branches', $employee['branchId']], ['job_titles', $employee['jobTitleId']], ['employees', $employee['managerEmployeeId']]] as [$table, $referenceId]) {
            if (! $referenceId) {
                continue;
            }
            $valid = DB::table($table)->where('id', $referenceId)->where('organisation_id', $organisation->id)->exists();
            if (! $valid) {
                $label = str_replace('_', ' ', $table);
                throw new LicensingValidationException('REFERENCE_OUT_OF_SCOPE', "The selected {$label} record is outside this organisation.");
            }
        }

        $id = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($employee, $organisation, $license, $actor, $id, $now) {
            Employee::create([
                'id' => $id, 'organisation_id' => $organisation->id, 'user_id' => null, 'employee_number' => $employee['employeeNumber'],
                'full_name' => $employee['fullName'], 'email' => $employee['email'], 'position_id' => null,
                'job_title_id' => $employee['jobTitleId'], 'department_id' => $employee['departmentId'], 'business_unit_id' => null,
                'branch_id' => $employee['branchId'], 'manager_employee_id' => $employee['managerEmployeeId'], 'status' => 'INVITED',
                'invited_at' => $now, 'activated_at' => null, 'terminated_at' => null, 'last_activity_at' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('license_usage')->where('organisation_license_id', $license['id'])->where('metric_key', 'USER_SEATS')
                ->update(['reserved_value' => DB::raw('reserved_value + 1'), 'version' => DB::raw('version + 1'), 'updated_at' => $now]);
            OutboxEvent::create([
                'id' => (string) Str::uuid(), 'aggregate_type' => 'EMPLOYEE', 'aggregate_id' => $id, 'event_type' => 'EmployeeInvitationRecorded',
                'event_version' => 1, 'partition_key' => $organisation->id,
                'payload' => AuditService::canonicalJson(['employee_id' => $id, 'delivery' => 'DISABLED_LOCAL_STAGING']),
                'status' => 'PENDING', 'publish_attempts' => 0, 'occurred_at' => $now, 'available_at' => $now,
            ]);
            AuditService::append($actor, 'EMPLOYEE_INVITED', 'EMPLOYEE', $id, ['organisationId' => $organisation->id, 'email' => $employee['email'], 'delivery' => 'DISABLED_LOCAL_STAGING'], $now);
        });

        return array_merge($this->presentEmployee(Employee::findOrFail($id)), ['invitation_delivery' => 'DISABLED_LOCAL_STAGING']);
    }

    /**
     * Employee INVITED -> ACTIVE. Links the invited employee record to an
     * existing, already-active user and converts the USER_SEATS licence
     * reservation inviteEmployee made into actual usage (mirrors
     * terminateEmployee's used_value decrement on the way out). Idempotent
     * on an already-ACTIVE employee.
     *
     * @return array<string, mixed>
     */
    public function activateEmployee(string $employeeId, array $payload, User $actor, ?string $requestedOrganisationId): array
    {
        $input = OrganisationAdminValidator::employeeActivation($payload);
        ['organisation' => $organisation, 'license' => $license] = EntitlementGate::assert($actor, 'ADMINISTRATION', 'ADMIN_WRITE', 0, $requestedOrganisationId);

        $employee = Employee::where('id', $employeeId)->where('organisation_id', $organisation->id)->first();
        if (! $employee) {
            throw new LicensingValidationException('EMPLOYEE_NOT_FOUND', 'The employee is outside the active organisation scope.');
        }
        if ($employee->status === 'ACTIVE') {
            return ['id' => $employee->id, 'status' => 'ACTIVE', 'user_id' => $employee->user_id];
        }
        if ($employee->status !== 'INVITED') {
            throw new LicensingValidationException('EMPLOYEE_NOT_INVITED', "Cannot activate an employee currently {$employee->status}.");
        }
        $targetUser = User::find($input['userId']);
        if (! $targetUser) {
            throw new LicensingValidationException('USER_NOT_FOUND', 'The target user does not exist.');
        }
        if ($targetUser->status !== 'ACTIVE') {
            throw new LicensingValidationException('USER_NOT_ACTIVE', 'The target user is not active.');
        }
        $alreadyLinked = Employee::where('organisation_id', $organisation->id)->where('user_id', $input['userId'])->where('status', 'ACTIVE')->exists();
        if ($alreadyLinked) {
            throw new RepositoryConflictException('This user is already linked to an active employee record in this organisation.');
        }

        $now = now();
        DB::transaction(function () use ($employee, $input, $organisation, $license, $actor, $now) {
            Employee::where('id', $employee->id)->where('organisation_id', $organisation->id)
                ->update(['user_id' => $input['userId'], 'status' => 'ACTIVE', 'activated_at' => $now, 'updated_at' => $now]);
            DB::table('license_usage')->where('organisation_license_id', $license['id'])->where('metric_key', 'USER_SEATS')
                ->update(['used_value' => DB::raw('used_value + 1'), 'reserved_value' => DB::raw('GREATEST(0, reserved_value - 1)'), 'version' => DB::raw('version + 1'), 'updated_at' => $now]);
            AuditService::append($actor, 'EMPLOYEE_ACTIVATED', 'EMPLOYEE', $employee->id, ['organisationId' => $organisation->id, 'userId' => $input['userId']], $now);
        });

        return ['id' => $employee->id, 'status' => 'ACTIVE', 'user_id' => $input['userId']];
    }

    /**
     * Historical records (the employee row, past ledger/audit entries) are
     * always preserved -- only status flips and access is revoked.
     * Workflow-task reassignment to the organisation's primary
     * administrator (the source's own final step) is deliberately not
     * ported: it needs `workflow_assignments`/`workflow_instances`, both
     * still deferred to the workflow-engine slice of this same phase.
     *
     * @return array<string, mixed>
     */
    public function terminateEmployee(string $employeeId, mixed $reasonInput, User $actor, ?string $requestedOrganisationId): array
    {
        ['organisation' => $organisation, 'license' => $license] = EntitlementGate::assert($actor, 'ADMINISTRATION', 'ADMIN_WRITE', 0, $requestedOrganisationId);
        $employee = Employee::where('id', $employeeId)->where('organisation_id', $organisation->id)->first();
        if (! $employee) {
            throw new LicensingValidationException('EMPLOYEE_NOT_FOUND', 'The employee is outside the active organisation scope.');
        }
        if ($employee->user_id === $actor->id) {
            throw new LicensingValidationException('SELF_OFFBOARD_DENIED', 'Administrators cannot offboard their own privileged identity.');
        }
        if ($employee->status === 'TERMINATED') {
            return ['id' => $employee->id, 'status' => $employee->status];
        }
        $reason = OrganisationAdminValidator::offboardingReason($reasonInput);
        $now = now();

        DB::transaction(function () use ($employee, $organisation, $license, $actor, $reason, $now) {
            Employee::where('id', $employee->id)->where('organisation_id', $organisation->id)
                ->update(['status' => 'TERMINATED', 'terminated_at' => $now, 'updated_at' => $now]);
            DB::table('license_usage')->where('organisation_license_id', $license['id'])->where('metric_key', 'USER_SEATS')
                ->update(['used_value' => DB::raw('GREATEST(0, used_value - 1)'), 'version' => DB::raw('version + 1'), 'updated_at' => $now]);
            AuditService::append($actor, 'EMPLOYEE_TERMINATED', 'EMPLOYEE', $employee->id, ['organisationId' => $organisation->id, 'reason' => $reason, 'historicalRecordsPreserved' => true], $now);
            if ($employee->user_id) {
                DB::table('organisation_memberships')->where('organisation_id', $organisation->id)->where('user_id', $employee->user_id)->where('status', 'ACTIVE')
                    ->update(['status' => 'REVOKED', 'valid_to' => $now]);
                DB::table('user_role_assignments')->where('organisation_id', $organisation->id)->where('user_id', $employee->user_id)->where('status', 'ACTIVE')
                    ->update(['status' => 'REVOKED', 'effective_to' => $now]);
                DB::table('user_capability_assignments')->where('organisation_id', $organisation->id)->where('user_id', $employee->user_id)->where('status', 'ACTIVE')
                    ->update(['status' => 'REVOKED', 'effective_to' => $now]);
                User::where('id', $employee->user_id)->update(['status' => 'SUSPENDED']);
            }
        });

        return ['id' => $employee->id, 'status' => 'TERMINATED', 'historical_records_preserved' => true];
    }

    /**
     * Requires the target to already be an active employee of this
     * organisation -- administrators are always grounded in a real
     * employee record. Appointing a new primary administrator demotes any
     * existing one: exactly one primary Organisation Portal Administrator
     * per organisation.
     *
     * @return array<string, mixed>
     */
    public function appointAdministrator(array $payload, User $actor, ?string $requestedOrganisationId): array
    {
        $appointment = OrganisationAdminValidator::administratorAppointment($payload);
        ['organisation' => $organisation] = EntitlementGate::assert($actor, 'ADMINISTRATION', 'ADMIN_WRITE', 1, $requestedOrganisationId);

        $role = OrganisationAdministratorRole::find($appointment['administratorRoleCode']);
        if (! $role) {
            throw new LicensingValidationException('ADMINISTRATOR_ROLE_NOT_FOUND', 'The administrator role is not in the approved catalogue.');
        }
        $employee = Employee::where('organisation_id', $organisation->id)->where('user_id', $appointment['userId'])->where('status', 'ACTIVE')->first();
        if (! $employee) {
            throw new LicensingValidationException('EMPLOYEE_NOT_ACTIVE', 'The target user must be an active employee of this organisation before appointment.');
        }
        $existing = OrganisationAdministrator::where('organisation_id', $organisation->id)->where('user_id', $appointment['userId'])
            ->where('administrator_role_code', $appointment['administratorRoleCode'])->where('status', 'ACTIVE')->exists();
        if ($existing) {
            throw new RepositoryConflictException('This user already holds this administrator role.');
        }

        $id = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($appointment, $organisation, $employee, $actor, $id, $now) {
            if ($appointment['isPrimary']) {
                OrganisationAdministrator::where('organisation_id', $organisation->id)->where('is_primary', true)->where('status', 'ACTIVE')
                    ->update(['is_primary' => false]);
            }
            OrganisationAdministrator::create([
                'id' => $id, 'organisation_id' => $organisation->id, 'user_id' => $appointment['userId'], 'employee_id' => $employee->id,
                'administrator_role_code' => $appointment['administratorRoleCode'], 'scope' => AuditService::canonicalJson(['organisation_id' => $organisation->id]),
                'is_primary' => $appointment['isPrimary'], 'status' => 'ACTIVE', 'effective_from' => $now, 'effective_to' => null,
                'appointed_by' => $actor->id, 'approval_reference' => $appointment['approvalReference'],
            ]);
            AuditService::append($actor, 'ADMINISTRATOR_APPOINTED', 'ORGANISATION_ADMINISTRATOR', $id, [
                'organisationId' => $organisation->id, 'userId' => $appointment['userId'], 'role' => $appointment['administratorRoleCode'], 'isPrimary' => $appointment['isPrimary'],
            ], $now);
        });

        return [
            'id' => $id, 'organisation_id' => $organisation->id, 'user_id' => $appointment['userId'],
            'administrator_role_code' => $appointment['administratorRoleCode'], 'is_primary' => $appointment['isPrimary'], 'status' => 'ACTIVE',
        ];
    }

    /** @return array<string, mixed> */
    public function createOrganisationRole(array $payload, User $actor, ?string $requestedOrganisationId): array
    {
        $role = OrganisationAdminValidator::organisationRole($payload);
        ['organisation' => $organisation] = EntitlementGate::assert($actor, 'ADMINISTRATION', 'ADMIN_WRITE', 1, $requestedOrganisationId);

        $catalogueCount = AccessPermission::whereIn('code', $role['permissions'])->count();
        if ($catalogueCount !== count($role['permissions'])) {
            throw new LicensingValidationException('PERMISSION_UNKNOWN', 'One or more permissions are not in the approved catalogue.');
        }
        $priorVersion = (int) (OrganisationRole::where('organisation_id', $organisation->id)->where('name', $role['name'])->max('version') ?? 0);
        $version = $priorVersion + 1;

        $id = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($role, $organisation, $actor, $id, $version, $now) {
            OrganisationRole::create([
                'id' => $id, 'organisation_id' => $organisation->id, 'name' => $role['name'],
                'description' => $role['description'] ?? 'Organisation-defined least-privilege role.', 'version' => $version,
                'branch_scope' => AuditService::canonicalJson($role['branchScope']), 'approval_limit_cents' => $role['approvalLimitCents'],
                'status' => 'ACTIVE', 'created_by' => $actor->id, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($role['permissions'] as $permission) {
                OrganisationRolePermission::create([
                    'id' => (string) Str::uuid(), 'organisation_role_id' => $id, 'permission_code' => $permission,
                    'record_scope' => 'ORGANISATION', 'effect' => 'ALLOW', 'created_at' => $now,
                ]);
            }
            AuditService::append($actor, 'ORGANISATION_ROLE_CREATED', 'ORGANISATION_ROLE', $id, [
                'organisationId' => $organisation->id, 'name' => $role['name'], 'version' => $version, 'permissions' => $role['permissions'],
            ], $now);
        });

        return [
            'id' => $id, 'organisation_id' => $organisation->id, 'name' => $role['name'], 'description' => $role['description'],
            'permissions' => $role['permissions'], 'branch_scope' => $role['branchScope'], 'approval_limit_cents' => $role['approvalLimitCents'],
            'version' => $version, 'status' => 'ACTIVE',
        ];
    }

    /** No prior read of user_capability_assignments existed outside the internal buildUserContext join. @return array<string, mixed> */
    public function listCapabilityGrants(User $actor, ?string $requestedOrganisationId): array
    {
        ['organisation' => $organisation] = EntitlementGate::assert($actor, 'ADMINISTRATION', 'READ', 0, $requestedOrganisationId);
        $capabilities = UserCapabilityAssignment::with('user')->where('organisation_id', $organisation->id)
            ->get()->sortBy([['user.name', 'asc'], ['capability', 'asc']])
            ->map(fn (UserCapabilityAssignment $c) => [
                'id' => $c->id, 'user_id' => $c->user_id, 'display_name' => $c->user?->name, 'email' => $c->user?->email,
                'capability' => $c->capability, 'status' => $c->status,
                'effective_from' => optional($c->effective_from)->toISOString(), 'effective_to' => optional($c->effective_to)->toISOString(),
            ])->values()->all();

        return ['organisation_id' => $organisation->id, 'capabilities' => $capabilities];
    }

    /**
     * Requires the organisation itself to already hold the capability
     * (organisation_capabilities) and the target to be an active member --
     * this only ever narrows visibility within what the organisation is
     * already entitled to, never grants something the org itself doesn't
     * hold. Upserts rather than blind-inserts: the unique index on
     * (organisation_id, user_id, capability) means a prior revoked grant
     * must be reactivated in place, not duplicated.
     *
     * @return array<string, mixed>
     */
    public function grantCapability(array $payload, User $actor, ?string $requestedOrganisationId): array
    {
        $grant = OrganisationAdminValidator::capabilityGrant($payload);
        ['organisation' => $organisation] = EntitlementGate::assert($actor, 'ADMINISTRATION', 'ADMIN_WRITE', 0, $requestedOrganisationId);

        $targetUser = User::find($grant['userId']);
        if (! $targetUser) {
            throw new LicensingValidationException('USER_NOT_FOUND', 'The target user does not exist.');
        }
        if ($targetUser->status !== 'ACTIVE') {
            throw new LicensingValidationException('USER_NOT_ACTIVE', 'The target user is not active.');
        }
        $now = now();
        $orgCapability = DB::table('organisation_capabilities')->where('organisation_id', $organisation->id)->where('capability', $grant['capability'])
            ->where('status', 'ACTIVE')->where('effective_from', '<=', $now)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $now))->exists();
        if (! $orgCapability) {
            throw new LicensingValidationException('ORGANISATION_CAPABILITY_INACTIVE', "The organisation does not currently hold {$grant['capability']} capability.");
        }
        $membership = DB::table('organisation_memberships')->where('organisation_id', $organisation->id)->where('user_id', $grant['userId'])->where('status', 'ACTIVE')->exists();
        if (! $membership) {
            throw new LicensingValidationException('USER_NOT_MEMBER', 'The target user is not an active member of this organisation.');
        }

        $existing = UserCapabilityAssignment::where('organisation_id', $organisation->id)->where('user_id', $grant['userId'])->where('capability', $grant['capability'])->first();
        if ($existing && $existing->status === 'ACTIVE') {
            return ['id' => $existing->id, 'organisation_id' => $organisation->id, 'user_id' => $grant['userId'], 'capability' => $grant['capability'], 'status' => 'ACTIVE'];
        }

        $id = $existing->id ?? (string) Str::uuid();
        DB::transaction(function () use ($existing, $id, $organisation, $grant, $actor, $now) {
            if ($existing) {
                UserCapabilityAssignment::where('id', $id)->update(['status' => 'ACTIVE', 'effective_from' => $now, 'effective_to' => null, 'assigned_by' => $actor->id]);
            } else {
                UserCapabilityAssignment::create([
                    'id' => $id, 'organisation_id' => $organisation->id, 'user_id' => $grant['userId'], 'capability' => $grant['capability'],
                    'status' => 'ACTIVE', 'effective_from' => $now, 'effective_to' => null, 'assigned_by' => $actor->id,
                ]);
            }
            AuditService::append($actor, 'CAPABILITY_GRANTED', 'USER_CAPABILITY_ASSIGNMENT', $id, ['organisationId' => $organisation->id, 'userId' => $grant['userId'], 'capability' => $grant['capability']], $now);
        });

        return ['id' => $id, 'organisation_id' => $organisation->id, 'user_id' => $grant['userId'], 'capability' => $grant['capability'], 'status' => 'ACTIVE'];
    }

    /**
     * Opens the current calendar-quarter's review if one doesn't already
     * exist -- the real prerequisite `EntitlementGate::assert`'s own
     * `ADMIN_WRITE` gate requires before any of this slice's other write
     * commands will run. Idempotent: returns the existing review if
     * already open for this quarter.
     *
     * @return array<string, mixed>
     */
    public function openQuarterlyAccessReview(User $actor, ?string $requestedOrganisationId): array
    {
        ['organisation' => $organisation] = EntitlementGate::assert($actor, 'ADMINISTRATION', 'COMPLIANCE_WRITE', 0, $requestedOrganisationId);
        $window = AccessReviewWindow::current();
        $existing = AccessReview::where('organisation_id', $organisation->id)->where('review_type', 'QUARTERLY')->where('period_start', $window['periodStart'])->first();
        if ($existing) {
            return ['id' => $existing->id, 'status' => $existing->status, 'period_start' => $window['periodStart'], 'due_at' => optional($existing->due_at)->toISOString()];
        }

        $id = (string) Str::uuid();
        $now = now();
        DB::transaction(function () use ($id, $organisation, $window, $actor, $now) {
            AccessReview::create([
                'id' => $id, 'organisation_id' => $organisation->id, 'name' => "{$window['key']} privileged and dormant access review",
                'review_type' => 'QUARTERLY', 'status' => 'OPEN', 'period_start' => $window['periodStart'], 'due_at' => $window['dueAt'],
                'created_by' => $actor->id, 'created_at' => $now, 'completed_at' => null,
            ]);
            AuditService::append($actor, 'QUARTERLY_ACCESS_REVIEW_OPENED', 'ACCESS_REVIEW', $id, ['organisationId' => $organisation->id, 'period' => $window['key'], 'dueAt' => $window['dueAt']->toISOString()], $now);
        });

        return ['id' => $id, 'status' => 'OPEN', 'period_start' => $window['periodStart'], 'due_at' => $window['dueAt']->toISOString()];
    }

    /** @return array<string, mixed> */
    private function presentEmployee(Employee $employee): array
    {
        return [
            'id' => $employee->id, 'organisation_id' => $employee->organisation_id, 'employee_number' => $employee->employee_number,
            'full_name' => $employee->full_name, 'email' => $employee->email, 'department_id' => $employee->department_id,
            'branch_id' => $employee->branch_id, 'job_title_id' => $employee->job_title_id, 'manager_employee_id' => $employee->manager_employee_id,
            'status' => $employee->status,
        ];
    }
}
