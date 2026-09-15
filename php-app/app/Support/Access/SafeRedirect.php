<?php

namespace App\Support\Access;

use Illuminate\Http\Request;

/**
 * Extracted from App\Http\Controllers\Auth\ConfirmPasswordController's own
 * safeRedirectTarget() during the 2026-09-15 TOTP step-up cutover: the same
 * same-origin check is now needed by both App\Http\Middleware\
 * EnsureFreshStepUp (which replaces ConfirmPasswordController as the
 * step-up gate) and Identity\MfaViewController (whose stepUp() is now the
 * action that actually redirects back to the originally blocked page).
 * A tampered `redirect_to` value -- it travels through a hidden form field
 * or query string, not a signed value -- could otherwise turn a real
 * authentication check into an open redirect.
 */
final class SafeRedirect
{
    public static function target(Request $request, ?string $candidate, string $fallbackRouteName = 'dashboard'): string
    {
        if (! $candidate) {
            return route($fallbackRouteName);
        }

        $host = parse_url($candidate, PHP_URL_HOST);
        if ($host !== null && $host !== $request->getHost()) {
            return route($fallbackRouteName);
        }

        return $candidate;
    }
}
