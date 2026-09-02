<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * New for the Laravel migration: the TS source trusted platform-injected
 * headers for authentication (app/chatgpt-auth.ts), which the migration
 * brief explicitly forbids continuing ("do NOT authenticate users by
 * trusting arbitrary HTTP headers"). This is real Laravel session
 * authentication instead -- rate-limited (mirrors the source's own
 * enforceRateLimits pattern elsewhere), and checks the account's own
 * status column so a SUSPENDED user (lib/data/identity-repository.ts's
 * suspendTaxpayer-adjacent app_users.status) is denied even with a
 * correct password, not just hidden from menus.
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $user = User::where('email', $this->string('email'))->first();

        if (! $user || ! Auth::attempt(['email' => $this->string('email'), 'password' => $this->string('password')], $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if (! $user->isActive()) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'This account has been suspended.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "Too many login attempts. Try again in {$seconds} seconds.",
        ]);
    }

    public function throttleKey(): string
    {
        return mb_strtolower($this->string('email')).'|'.$this->ip();
    }
}
