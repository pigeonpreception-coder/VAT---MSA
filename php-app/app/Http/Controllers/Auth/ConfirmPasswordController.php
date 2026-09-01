<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Real Laravel `password.confirm` reauthentication flow, used as the step-up
 * gate on the sensitive administrative actions ported from the source's
 * requireStepUp (lib/security/step-up.ts's server-verified TOTP): the source
 * requires a fresh, actively-re-proven identity check immediately before a
 * registration decision, taxpayer suspension, or membership assignment.
 * Password reconfirmation gives that same "fresh reauthentication, not just
 * an old session" property via Laravel's own tested mechanism; full TOTP
 * parity (step_up_events, mfa_totp_credentials) is a documented follow-up,
 * not silently dropped -- see docs/MIGRATION_MATRIX.md.
 */
class ConfirmPasswordController extends Controller
{
    public function show(): View
    {
        return view('auth.confirm-password');
    }

    public function store(Request $request): RedirectResponse
    {
        if (! Auth::guard('web')->validate(['email' => $request->user()->email, 'password' => $request->input('password')])) {
            throw ValidationException::withMessages(['password' => 'The provided password does not match our records.']);
        }

        $request->session()->put('auth.password_confirmed_at', time());

        return redirect()->intended();
    }
}
