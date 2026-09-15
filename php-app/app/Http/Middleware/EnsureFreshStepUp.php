<?php

namespace App\Http\Middleware;

use App\Services\Identity\MfaService;
use App\Support\Access\SafeRedirect;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The real TOTP-backed replacement for Laravel's own `password.confirm`
 * (Illuminate\Auth\Middleware\RequirePassword) on every route that was
 * wearing it as a documented stand-in -- see
 * App\Http\Controllers\Auth\ConfirmPasswordController's own doc comment
 * (now removed) and docs/LAUNCH_READINESS_BACKLOG.md item #8, which
 * already named this exact cutover as the deliberate follow-up to the
 * 2026-09-15 TOTP infrastructure build.
 *
 * Mirrors RequirePassword's own behaviour exactly, including its status
 * code: a JSON request that fails the check gets a 423 Locked
 * (RequirePassword::handle() throws the same `HttpException(423)` for an
 * `expectsJson()` request rather than redirecting -- several existing
 * step-up-gated JSON routes' own tests already assert this exact code,
 * unaffected by the underlying mechanism switching from a confirmed
 * password to a confirmed TOTP code). An HTML request is sent to the
 * step-up page with the *page containing the blocked form* preserved as
 * `redirect_to` (the referer of this POST, i.e. url()->previous() -- the
 * same value ConfirmPasswordController's own show() used to capture, and
 * deliberately not redirect()->intended(), which would replay the blocked
 * POST's own URL as a GET and 404/405 on these POST-only action routes).
 * MfaViewController's index/enroll/verify/stepUp carry that value forward
 * through the whole enrol-or-confirm flow and land the user back on it
 * once step-up is confirmed.
 */
class EnsureFreshStepUp
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app(MfaService::class)->hasFreshStepUp($request->user()->id)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            throw new HttpException(423, 'This action requires a fresh step-up confirmation. Confirm a current code from your authenticator app and try again.');
        }

        $redirectTo = SafeRedirect::target($request, url()->previous());

        return redirect()->route('security.mfa', ['redirect_to' => $redirectTo])
            ->with('status', 'This action requires a fresh step-up confirmation. Confirm a current code from your authenticator app, then try the action again.');
    }
}
