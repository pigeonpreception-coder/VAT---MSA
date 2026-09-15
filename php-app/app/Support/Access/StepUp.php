<?php

namespace App\Support\Access;

use App\Services\Identity\MfaService;
use Illuminate\Http\Request;

/**
 * Ported from lib/security/step-up.ts's hasFreshStepUp -- unlike every
 * other step-up-gated command in this migration (state/upgrade, taxpayer
 * suspension, registration decisions, membership assignment, invoice
 * cancellation), which are UNCONDITIONALLY step-up-gated and so simply wear
 * the route-level `step-up` middleware (see
 * App\Http\Controllers\Licensing\LicensingController's own doc comment),
 * `requestReportExport`/`approveReportExport` only require a fresh step-up
 * CONDITIONALLY -- on the report's own classification/`requires_step_up`
 * flag, data the router cannot see.
 *
 * 2026-09-15 TOTP cutover: this used to be its own reimplementation of
 * Laravel's `Illuminate\Auth\Middleware\RequirePassword::shouldConfirmPassword`
 * freshness check against the `auth.password_confirmed_at` session key. Now
 * that App\Http\Middleware\EnsureFreshStepUp gates every *unconditional*
 * step-up route through the real TOTP mechanism (App\Services\Identity\
 * MfaService), this conditional check delegates to the exact same
 * freshness source instead of maintaining a second, divergent notion of
 * "fresh" -- see MfaService::hasFreshStepUp's own doc comment.
 */
final class StepUp
{
    public static function isFresh(Request $request): bool
    {
        return app(MfaService::class)->hasFreshStepUp($request->user()->id);
    }
}
