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

    public static function correlationId(Request $request): string
    {
        $supplied = trim((string) $request->header('X-Correlation-Id', ''));

        return preg_match(self::UUID_PATTERN, $supplied) === 1 ? $supplied : (string) Str::uuid();
    }
}
