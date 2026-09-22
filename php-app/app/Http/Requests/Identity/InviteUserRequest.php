<?php

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;

/** Ported from lib/domain/identity.ts's normalizeUserInvitation, reusing AssignMembershipRequest's own ASSIGNABLE_MEMBERSHIP_ROLES ceiling rather than duplicating that list a third time. */
class InviteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:254'],
            'role_code' => ['required', 'string', 'in:'.implode(',', AssignMembershipRequest::ASSIGNABLE_ROLES)],
        ];
    }

    public function prepareForValidation(): void
    {
        $this->merge([
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'role_code' => mb_strtoupper(trim((string) $this->input('role_code'))),
        ]);
    }
}
