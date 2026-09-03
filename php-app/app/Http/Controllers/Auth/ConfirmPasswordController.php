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
    public function show(Request $request): View
    {
        return view('auth.confirm-password', [
            'redirectTo' => $this->safeRedirectTarget($request, url()->previous(route('dashboard'))),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if (! Auth::guard('web')->validate(['email' => $request->user()->email, 'password' => $request->input('password')])) {
            throw ValidationException::withMessages(['password' => 'The provided password does not match our records.']);
        }

        $request->session()->put('auth.password_confirmed_at', time());

        // Deliberately not redirect()->intended(): that replays the *blocked*
        // request's own URL as a GET, which is fine for a step-up-gated GET
        // but 404s/405s for this app's step-up-gated actions (membership
        // assignment, taxpayer suspension), which are POST-only forms with
        // no GET handler at that same path. Redirecting instead to wherever
        // the user actually was -- the page containing the form, captured
        // via url()->previous() at render time in show() above, before this
        // request's own URL overwrites it -- lands them somewhere real, so
        // they can resubmit the action now that the session is freshly
        // confirmed. safeRedirectTarget() keeps this same-origin: the value
        // travels through a hidden form field, so a tampered submission
        // could otherwise turn this into an open redirect straight after a
        // real authentication check.
        return redirect()->to($this->safeRedirectTarget($request, $request->input('redirect_to')));
    }

    private function safeRedirectTarget(Request $request, ?string $candidate): string
    {
        if (! $candidate) {
            return route('dashboard');
        }

        $host = parse_url($candidate, PHP_URL_HOST);
        if ($host !== null && $host !== $request->getHost()) {
            return route('dashboard');
        }

        return $candidate;
    }
}
