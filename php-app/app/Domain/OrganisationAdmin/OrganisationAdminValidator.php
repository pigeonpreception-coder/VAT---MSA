<?php

namespace App\Domain\OrganisationAdmin;

use App\Exceptions\LicensingValidationException;
use App\Support\Access\Permissions;

/**
 * Direct port of lib/domain/control-plane.ts's normalizeOrganisationRole/
 * normalizeEmployee/normalizeAdministratorAppointment/
 * normalizeEmployeeActivation/normalizeCapabilityGrant -- Phase 12 slice 2
 * (organisation administration/employees, and "the rest of Phase 8's" own
 * deferred employees/custom-roles gap, closed out together). Reuses
 * `App\Exceptions\LicensingValidationException` rather than a second,
 * near-identical exception class: the source itself throws the exact same
 * `ControlPlaneValidationError` (a single {code, message} pair) for both
 * this file and the Licensing & Entitlements slice's own validator.
 */
class OrganisationAdminValidator
{
    private static function cleanLabel(mixed $value, string $field, int $max = 100): string
    {
        if (! is_string($value)) {
            throw new LicensingValidationException('FIELD_REQUIRED', "{$field} is required.");
        }
        $result = trim((string) preg_replace('/\s+/', ' ', $value));
        if (mb_strlen($result) < 2 || mb_strlen($result) > $max) {
            throw new LicensingValidationException('FIELD_INVALID', "{$field} must contain 2 to {$max} characters.");
        }
        if (preg_match('/[<>]/', $result) || preg_match('/[\x00-\x1F]/', $result)) {
            throw new LicensingValidationException('FIELD_INVALID', "{$field} contains unsupported characters.");
        }

        return $result;
    }

    /** @return array{name: string, description: ?string, permissions: list<string>, branchScope: list<string>, approvalLimitCents: ?int} */
    public static function organisationRole(mixed $input): array
    {
        if (! is_array($input) || array_is_list($input)) {
            throw new LicensingValidationException('PAYLOAD_INVALID', 'A role definition is required.');
        }
        $permissions = array_values(array_unique(array_map(
            fn ($item) => mb_strtolower(trim($item)),
            array_filter($input['permissions'] ?? [], 'is_string'),
        )));
        if (! $permissions) {
            throw new LicensingValidationException('PERMISSION_REQUIRED', 'Select at least one permission.');
        }
        $grantable = Permissions::tenantGrantablePermissions();
        foreach ($permissions as $permission) {
            if (! preg_match('/^[a-z][a-z0-9-]*:[a-z][a-z0-9-]*$/', $permission)) {
                throw new LicensingValidationException('PERMISSION_INVALID', "Permission {$permission} is not valid.");
            }
            if (! in_array($permission, $grantable, true)) {
                throw new LicensingValidationException('PROTECTED_PERMISSION', "{$permission} is system-controlled and cannot be placed in an organisation role.");
            }
        }
        $approvalLimitCents = null;
        if (isset($input['approval_limit_cents']) && $input['approval_limit_cents'] !== null) {
            $approvalLimitCents = $input['approval_limit_cents'];
            if (! is_int($approvalLimitCents) || $approvalLimitCents < 0) {
                throw new LicensingValidationException('APPROVAL_LIMIT_INVALID', 'Approval limits must be non-negative integer minor units.');
            }
        }

        return [
            'name' => self::cleanLabel($input['name'] ?? null, 'Role name', 80),
            'description' => is_string($input['description'] ?? null) ? self::cleanLabel($input['description'], 'Description', 240) : null,
            'permissions' => $permissions,
            'branchScope' => array_values(array_filter($input['branch_scope'] ?? [], 'is_string')),
            'approvalLimitCents' => $approvalLimitCents,
        ];
    }

    /** @return array{employeeNumber: string, fullName: string, email: string, departmentId: ?string, branchId: ?string, jobTitleId: ?string, managerEmployeeId: ?string} */
    public static function employee(mixed $input): array
    {
        if (! is_array($input) || array_is_list($input)) {
            throw new LicensingValidationException('PAYLOAD_INVALID', 'An employee record is required.');
        }
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        if (! preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email)) {
            throw new LicensingValidationException('EMAIL_INVALID', 'A valid employee email is required.');
        }
        $optionalId = fn ($value) => is_string($value) && trim($value) !== '' ? trim($value) : null;

        return [
            'employeeNumber' => mb_strtoupper(self::cleanLabel($input['employee_number'] ?? null, 'Employee number', 40)),
            'fullName' => self::cleanLabel($input['full_name'] ?? null, 'Employee name', 120),
            'email' => $email,
            'departmentId' => $optionalId($input['department_id'] ?? null),
            'branchId' => $optionalId($input['branch_id'] ?? null),
            'jobTitleId' => $optionalId($input['job_title_id'] ?? null),
            'managerEmployeeId' => $optionalId($input['manager_employee_id'] ?? null),
        ];
    }

    /** @return array{userId: string} */
    public static function employeeActivation(mixed $input): array
    {
        if (! is_array($input) || array_is_list($input)) {
            throw new LicensingValidationException('PAYLOAD_INVALID', 'An employee activation object is required.');
        }
        $userId = trim((string) ($input['user_id'] ?? ''));
        if ($userId === '') {
            throw new LicensingValidationException('USER_ID_REQUIRED', 'user_id is required.');
        }

        return ['userId' => $userId];
    }

    /**
     * The administrator role code itself is validated against the DB
     * catalogue (organisation_administrator_roles) in the service layer,
     * same pattern as organisationRole()'s permission-catalogue check --
     * this only validates shape.
     *
     * @return array{userId: string, administratorRoleCode: string, isPrimary: bool, approvalReference: string}
     */
    public static function administratorAppointment(mixed $input): array
    {
        if (! is_array($input) || array_is_list($input)) {
            throw new LicensingValidationException('PAYLOAD_INVALID', 'An administrator appointment object is required.');
        }
        $userId = trim((string) ($input['user_id'] ?? ''));
        if ($userId === '') {
            throw new LicensingValidationException('USER_ID_REQUIRED', 'user_id is required.');
        }
        $administratorRoleCode = mb_strtoupper(trim((string) ($input['administrator_role_code'] ?? '')));
        if (! preg_match('/^[A-Z][A-Z0-9_]{1,39}$/', $administratorRoleCode)) {
            throw new LicensingValidationException('ADMINISTRATOR_ROLE_INVALID', 'administrator_role_code must contain 2 to 40 uppercase letters, numbers or underscores.');
        }
        $approvalReference = trim((string) preg_replace('/\s+/', ' ', (string) ($input['approval_reference'] ?? '')));
        if (mb_strlen($approvalReference) < 5 || mb_strlen($approvalReference) > 240) {
            throw new LicensingValidationException('APPROVAL_REFERENCE_REQUIRED', 'Provide a 5 to 240 character approval_reference.');
        }

        return ['userId' => $userId, 'administratorRoleCode' => $administratorRoleCode, 'isPrimary' => ($input['is_primary'] ?? null) === true, 'approvalReference' => $approvalReference];
    }

    /** @return array{userId: string, capability: string} */
    public static function capabilityGrant(mixed $input): array
    {
        if (! is_array($input) || array_is_list($input)) {
            throw new LicensingValidationException('PAYLOAD_INVALID', 'A capability grant object is required.');
        }
        $userId = trim((string) ($input['user_id'] ?? ''));
        if ($userId === '') {
            throw new LicensingValidationException('USER_ID_REQUIRED', 'user_id is required.');
        }
        $capability = mb_strtoupper(trim((string) ($input['capability'] ?? '')));
        if (! in_array($capability, ['BUYER', 'SELLER'], true)) {
            throw new LicensingValidationException('CAPABILITY_INVALID', 'capability must be BUYER or SELLER.');
        }

        return ['userId' => $userId, 'capability' => $capability];
    }

    public static function offboardingReason(mixed $value): string
    {
        $reason = trim((string) $value);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 240) {
            throw new LicensingValidationException('REASON_REQUIRED', 'Provide a 5 to 240 character offboarding reason.');
        }

        return $reason;
    }
}
