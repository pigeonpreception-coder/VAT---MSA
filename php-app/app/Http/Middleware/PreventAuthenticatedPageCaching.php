<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Red team finding RT-001 (docs/RED_TEAM_ASSESSMENT_2026-09-02.md): Laravel's
 * session middleware sends `Cache-Control: no-cache, private` by default,
 * which requires revalidation before reuse from the *HTTP* cache but does
 * NOT stop a browser's back-forward cache (bfcache) from replaying a whole
 * authenticated page after logout with zero server request -- confirmed via
 * manual reproduction (log in, view /dashboard, log out, press Back: the
 * full authenticated Dashboard rendered with nothing in the access log).
 *
 * Only `Cache-Control: no-store` reliably defeats bfcache replay. Applied to
 * the `auth` route group only (see routes/web.php) -- not the whole `web`
 * group -- so /login and any other unauthenticated page keep their existing
 * headers untouched, and the /build/assets/** static-asset routes (a
 * separate route group with no session middleware at all) are unaffected.
 */
class PreventAuthenticatedPageCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
