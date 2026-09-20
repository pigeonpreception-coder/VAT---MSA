<?php

namespace App\Http\Middleware;

use App\Exceptions\RateLimitExceededException;
use App\Support\Security\RateLimitGuard;
use App\Support\Security\RequestContext;
use App\Support\Security\SecurityEventRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Applies RateLimitGuard's own named bucket sets to a route, keyed by the
 * `$family` alias parameter (`rate-limit:invoice`, `rate-limit:registration`,
 * or `rate-limit:{family}` for the generic per-command shape the source's
 * own identity/control-plane/reconciliation/vat-rule families share --
 * see RateLimitGuard::enforceCommand()'s own doc comment).
 *
 * The source calls enforceRateLimits inline at the top of each command
 * handler in lib/data/*.ts; this port's own equivalent entry point is the
 * Laravel controller action, so route middleware -- not a change to every
 * Service class -- is the faithful adaptation, using the controller
 * action's own method name as the {command} identifier the source's
 * generic wrapper took as an explicit string argument per call site.
 */
class EnforceRateLimit
{
    public function handle(Request $request, Closure $next, string $family): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $sourceToken = RequestContext::sourceToken($request);
        $correlationId = RequestContext::correlationId($request);

        try {
            if ($family === 'invoice') {
                RateLimitGuard::enforceInvoice($user, RequestContext::deviceId($request), $sourceToken);
            } elseif ($family === 'registration') {
                RateLimitGuard::enforceRegistration($user, $sourceToken);
            } else {
                $command = $request->route()?->getActionMethod() ?? 'unknown';
                RateLimitGuard::enforceCommand($family, $command, $user);
            }
        } catch (RateLimitExceededException $e) {
            SecurityEventRecorder::recordRateLimitBreach($user->id, $sourceToken, $correlationId, $e, mb_strtoupper($family).'_RATE_LIMIT');
            throw $e;
        }

        return $next($request);
    }
}
