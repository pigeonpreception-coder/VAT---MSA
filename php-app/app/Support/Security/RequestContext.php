<?php

namespace App\Support\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Ported from lib/security/request.ts's requestContext()/correlationIdFor()
 * -- reused here as a proper shared helper for the rate-limit and
 * security-event work, rather than continuing the ad hoc inline
 * derivation InvoiceController::store() already had
 * ($request->header('X-Device-Id')/'X-Source-Token'/IP). That existing
 * convention (plain header/IP passthrough, not the source's own SHA-256
 * hash of the IP) is kept as-is here -- a deliberate simplification this
 * port already made, not reinvented as something closer to the source.
 */
class RequestContext
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    public static function deviceId(Request $request): string
    {
        return trim((string) $request->header('X-Device-Id', '')) ?: 'browser-session';
    }

    public static function sourceToken(Request $request): string
    {
        return trim((string) $request->header('X-Source-Token', $request->ip() ?? 'unavailable'));
    }

    /**
     * A source/device identifier the caller cannot spoof -- the request's
     * own resolved IP address, ignoring `X-Source-Token`/`X-Device-Id`
     * entirely. Use this (not sourceToken()/deviceId()) for a genuinely
     * unauthenticated route -- self-serve signup is currently the only
     * one (App\Http\Controllers\Signup\SignupController/
     * SignupViewController).
     *
     * Why sourceToken()/deviceId() stay header-trusting for every other
     * caller: every other route this app rate-limits is authenticated, so
     * the real backstop against a spoofed header is the per-actor bucket
     * (RateLimitGuard::tenantKey()/$user->id -- not derivable from a
     * header at all); the header there is a supplementary signal on top
     * of an already-anchored identity, not the only one. Self-serve
     * signup has no actor at all, so a spoofable header is the *only*
     * signal, and trusting it defeats the rate limit entirely: a caller
     * can rotate X-Source-Token/X-Device-Id per request and dodge every
     * per-source/per-device bucket
     * (RateLimitGuard::enforceSelfServeSignup()), leaving only the global
     * (500/5min) and per-email (5/hour -- itself trivially spread across
     * many addresses) buckets standing.
     *
     * Also closes a real collision this doubles as: deviceId()'s own
     * fallback for "no header sent" is the literal string
     * 'browser-session' -- since no ordinary <form> submission (the
     * public Blade signup form's real-world caller) ever sends
     * X-Device-Id, every genuine anonymous browser visitor was already
     * landing on that identical shared string, pooling every real
     * applicant into one device bucket rather than distinguishing them.
     * Keying off the request's own IP instead gives distinct visitors
     * distinct buckets, as the bucket design intends.
     */
    public static function unauthenticatedRequestIp(Request $request): string
    {
        return $request->ip() ?? 'unavailable';
    }

    public static function correlationId(Request $request): string
    {
        $supplied = trim((string) $request->header('X-Correlation-Id', ''));

        return preg_match(self::UUID_PATTERN, $supplied) === 1 ? $supplied : (string) Str::uuid();
    }
}
