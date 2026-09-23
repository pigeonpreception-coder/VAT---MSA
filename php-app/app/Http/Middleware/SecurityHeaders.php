<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gap-finding pass (2026-09-23): app/security/page.tsx's own "Control
 * posture" panel claims a "Browser defence" control (CSP, HSTS, frame,
 * MIME and privacy response policies) is ACTIVE -- source's own repo has
 * no equivalent header-setting code either (plausibly configured at the
 * Cloudflare edge, outside this repository), but this Laravel port has no
 * origin capable of setting it at all, so porting that panel's copy
 * without also adding real headers here would make the claim false for
 * this deployment.
 *
 * `script-src` and `style-src` include 'unsafe-inline' deliberately, not
 * as an oversight: a repo-wide grep found 26 Blade views using inline
 * `onchange`/`onclick`/`onsubmit` handlers (e.g. this migration's own
 * auto-submitting filter `<select>`s) plus several inline `<script>`
 * blocks, none of which a nonce-free strict CSP could allow without
 * breaking real, working functionality no automated test would catch
 * (PHPUnit never executes CSP in a real browser). Every other directive
 * here is fully strict. Tightening script-src to drop 'unsafe-inline'
 * needs a nonce (or moving every inline handler/script to an external,
 * CSP-safe file) -- real, separate frontend work, tracked as a follow-up
 * rather than attempted here.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        return $response;
    }
}
