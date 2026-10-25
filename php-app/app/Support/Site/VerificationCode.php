<?php

namespace App\Support\Site;

/**
 * Turns whatever a visitor pastes into the public "verify an invoice" box
 * into a certificate verification token -- or null if it cannot be one.
 *
 * Accepts the bare token, the full verification link printed on (or behind
 * the QR code of) an invoice, or the API form of that link. The result is
 * restricted to [A-Za-z0-9_-], which is what makes it safe to hand straight
 * to route('verify.show', ...): nothing a visitor types can steer the
 * redirect anywhere but that one path on this site.
 */
final class VerificationCode
{
    public static function extract(string $input): ?string
    {
        // Pasted links often arrive wrapped across lines or with invisible
        // zero-width characters; neither belongs in a token.
        $compact = preg_replace('/[\s\x{200B}-\x{200D}\x{FEFF}]+/u', '', $input);
        if ($compact === null || $compact === '') {
            return null;
        }

        if (preg_match('~/verify/([^/?#]+)~i', $compact, $matches) === 1) {
            $compact = $matches[1];
        }

        return preg_match('/^[A-Za-z0-9_-]{8,128}$/', $compact) === 1 ? $compact : null;
    }
}
