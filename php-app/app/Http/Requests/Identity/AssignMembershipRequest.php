<?php

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ported from lib/domain/identity.ts's normalizeMembershipAssignment and its
 * ASSIGNABLE_MEMBERSHIP_ROLES ceiling -- deliberately excludes NamRA,
 * PILOT_ADMIN, platform and seller/buyer portal roles; granting those here
 * would be a privilege-escalation path for an organisation admin.
 */
class AssignMembershipRequest extends FormRequest
{
    public const ASSIGNABLE_ROLES = ['TAXPAYER_OWNER', 'TAXPAYER_ADMIN', 'TAXPAYER_ACCOUNTANT', 'TAXPAYER_STAFF', 'TAXPAYER_VIEWER'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'string', 'uuid'],
            'role_code' => ['required', 'string', 'in:'.implode(',', self::ASSIGNABLE_ROLES)],
            'branch_id' => ['nullable', 'string', 'uuid'],
        ];
    }

    public function prepareForValidation(): void
    {
        $this->merge(['role_code' => mb_strtoupper(trim((string) $this->input('role_code')))]);
    }
}
