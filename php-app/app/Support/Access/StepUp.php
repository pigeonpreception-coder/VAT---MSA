<?php

namespace App\Support\Access;

use App\Support\Platform\PlatformConfigReader;
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
 * freshness check exactly (same `auth.password_confirmed_at` session key),
 * with one deliberate difference: the freshness window itself is read from
 * the seeded `STEP_UP_WINDOW` `access_policies` row (its own
 * `window_seconds` parameter) when an ACTIVE one exists, falling back to
 * Laravel's own `auth.password_timeout` config otherwise -- the first real
 * consumer of that seeded row (see App\Support\Platform\
 * PlatformConfigReader's own doc comment for why this is safe to wire
 * without touching anything else).
 */
final class StepUp
{
    public static function isFresh(Request $request): bool
    {
        $confirmedAt = Date::now()->unix() - (int) $request->session()->get('auth.password_confirmed_at', 0);
        $windowSeconds = PlatformConfigReader::policyInt('STEP_UP_WINDOW', 'window_seconds', (int) config('auth.password_timeout', 10800));

        return $confirmedAt <= $windowSeconds;
    }
}
