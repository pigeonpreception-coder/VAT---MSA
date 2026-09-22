<?php

namespace App\Http\Requests\Identity;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password as PasswordRule;

/**
 * Ported from lib/domain/identity.ts's normalizeInvitationClaim, extended
 * with real credential fields -- see App\Services\Identity\
 * UserInvitationService::claim()'s own doc comment for why: source's own
 * claim half trusted a platform-asserted identity (subject/email/
 * displayName) with no password at all, the exact header-trust mechanism
 * this port's whole authentication model rejected in favour of real
 * Laravel session auth (App\Http\Requests\Auth\LoginRequest's own doc
 * comment). `password` mirrors App\Http\Requests\Auth\ResetPasswordRequest's
 * own rule exactly.
 */
class ClaimInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(10)->mixedCase()->numbers()],
        ];
    }
}
