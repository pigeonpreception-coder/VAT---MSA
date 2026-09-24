<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

/**
 * RT-005 (docs/RED_TEAM_ASSESSMENT_2026-09-02.md) -- see
 * ForgotPasswordRequest's own doc comment for the flow this completes.
 *
 * Every non-success Password::reset() outcome (invalid token, expired
 * token, unknown email) is collapsed into the same single generic message,
 * even though Laravel's own INVALID_USER status would otherwise let this
 * endpoint be used to test whether an email is registered by pairing it
 * with a syntactically-valid but made-up token -- the same account-
 * enumeration class of gap RT-003 fixed on the login form.
 */
class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(10)->mixedCase()->numbers()],
        ];
    }

    public function resetPassword(): void
    {
        $status = Password::reset(
            $this->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // Fraud/Authentication Resistance pass (2026-09-14): a
                // password reset is very often a response to a suspected
                // compromise -- but Laravel's session guard trusts an
                // already-established session cookie without re-checking
                // the password hash, so nothing here previously stopped an
                // attacker's own already-authenticated session (or any
                // other device's) from continuing to work with the new
                // password never having invalidated it. Live-confirmed
                // against a real running instance with two independent
                // sessions of the same account: both remained fully valid
                // (dashboard returned 200) after a genuine reset. Deleting
                // every `sessions` row for this user forces every device --
                // including the one this reset was submitted from -- to log
                // in again with the new credential.
                DB::table('sessions')->where('user_id', $user->id)->delete();

                // Gap-finding pass (2026-09-24): this blanket wipe wrote no
                // audit_events row, unlike MfaViewController's own session-
                // revocation actions (see that class's own doc comment,
                // fixed in the same pass) and every other security-
                // sensitive actor action in this codebase.
                AuditService::append($user, 'PASSWORD_RESET_SESSIONS_REVOKED', 'USER', $user->id, [], now());

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'This password reset link is invalid or has expired. Request a new one.',
            ]);
        }
    }
}
