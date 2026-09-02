<?php

namespace App\Support\Access;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

/**
 * Ported from lib/security/step-up.ts's hasFreshStepUp -- unlike every
 * other step-up-gated command in this migration (state/upgrade, taxpayer
 * suspension, registration decisions, membership assignment, invoice
 * cancellation), which are UNCONDITIONALLY step-up-gated and so simply wear
 * the route-level `password.confirm` middleware (see
 * App\Http\Controllers\Licensing\LicensingController's own doc comment),
 * `requestReportExport`/`approveReportExport` only require a fresh step-up
 * CONDITIONALLY -- on the report's own classification/`requires_step_up`
 * flag, data the router cannot see. This mirrors Laravel's own
 * `Illuminate\Auth\Middleware\RequirePassword::shouldConfirmPassword`
 * freshness check exactly (same `auth.password_confirmed_at` session key,
 * same `auth.password_timeout` config), just evaluated inline by the
 * service instead of gating the whole route.
 */
final class StepUp
{
    public static function isFresh(Request $request): bool
    {
        $confirmedAt = Date::now()->unix() - (int) $request->session()->get('auth.password_confirmed_at', 0);

        return $confirmedAt <= (int) config('auth.password_timeout', 10800);
    }
}
