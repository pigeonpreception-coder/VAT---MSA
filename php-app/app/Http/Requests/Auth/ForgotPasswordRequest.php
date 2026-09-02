<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * RT-005 (docs/RED_TEAM_ASSESSMENT_2026-09-02.md): this app had no
 * self-service password-reset flow at all -- every locked-out user (and
 * every newly provisioned staff member; PlatformChangeService::provisionStaff
 * seeds a random, never-communicated password, so without this flow a new
 * staff account has no way to ever log in at all) required a manual
 * administrator password reset, which does not scale past the pilot.
 *
 * Deliberately returns the exact same generic confirmation regardless of
 * whether the submitted email exists -- Password::sendResetLink()'s own
 * RESET_LINK_SENT / INVALID_USER outcomes are intentionally not
 * distinguished here or in the controller's response, so this endpoint
 * cannot become an account-enumeration oracle (the same class of gap
 * RT-003 fixed on the login form itself).
 *
 * No AuditService::append() call here, matching this app's existing
 * login/logout/confirm-password flows, none of which emit an audit event
 * either -- AuditService::append() requires an authenticated User actor
 * (see its own doc comment), which a not-yet-authenticated password-reset
 * request never has. Introducing a fictitious "system actor" just for this
 * flow would be a new, inconsistent pattern, not a fix.
 */
class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
        ];
    }

    public function sendResetLink(): void
    {
        $this->ensureIsNotRateLimited();

        RateLimiter::hit($this->throttleKey(), 60);

        // The return status (RESET_LINK_SENT vs INVALID_USER) is
        // deliberately ignored -- see this class's own doc comment.
        Password::sendResetLink($this->only('email'));
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "Too many password reset attempts. Try again in {$seconds} seconds.",
        ]);
    }

    public function throttleKey(): string
    {
        return 'password-reset|'.mb_strtolower($this->string('email')).'|'.$this->ip();
    }
}
