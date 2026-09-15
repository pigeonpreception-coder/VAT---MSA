<?php

namespace App\Support\Access;

use App\Exceptions\IdentityValidationException;

/**
 * Ported from lib/domain/mfa.ts -- Security fix 2026-08-27
 * (SECURITY_GAP_ASSESSMENT.md item #2): a real, standards-compliant TOTP
 * (RFC 6238) implementation using only PHP's own hash_hmac(), no external
 * MFA provider or vendor integration required. This is what closes the
 * gap the source's own comment describes: "step-up" used to be a header
 * the caller supplied and lib/security/step-up.ts trusted verbatim, with
 * no server-side verification of any kind. This class never persists
 * anything itself (see App\Services\Identity\MfaService for that); it is
 * pure algorithm and validation, matching the source's own domain-layer
 * separation.
 *
 * One deliberate deviation from the source's own generateTotpCode/
 * verifyTotpCode (which default to raw Date.now()): the default clock
 * here is Laravel's own testable now()->valueOf(), matching this
 * codebase's established convention everywhere else a service reads the
 * current time -- real wall-clock time in production (nothing ever calls
 * Carbon::setTestNow() outside a test), but a frozen/advanceable clock in
 * tests, avoiding a genuine 30-second-window race at test time.
 */
final class Totp
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const STEP_SECONDS = 30;

    private const DIGITS = 6;

    private static function base32Encode(string $bytes): string
    {
        $bits = 0;
        $value = 0;
        $output = '';
        foreach (str_split($bytes) as $byte) {
            $value = ($value << 8) | ord($byte);
            $bits += 8;
            while ($bits >= 5) {
                $output .= self::BASE32_ALPHABET[($value >> ($bits - 5)) & 31];
                $bits -= 5;
            }
        }
        if ($bits > 0) {
            $output .= self::BASE32_ALPHABET[($value << (5 - $bits)) & 31];
        }

        return $output;
    }

    private static function base32Decode(string $input): string
    {
        $clean = preg_replace('/[^A-Z2-7]/', '', strtoupper($input));
        $bits = 0;
        $value = 0;
        $bytes = '';
        foreach (str_split($clean) as $char) {
            $index = strpos(self::BASE32_ALPHABET, $char);
            if ($index === false) {
                continue;
            }
            $value = ($value << 5) | $index;
            $bits += 5;
            if ($bits >= 8) {
                $bytes .= chr(($value >> ($bits - 8)) & 0xFF);
                $bits -= 8;
            }
        }

        return $bytes;
    }

    /** Generates a fresh random TOTP secret (20 bytes = 160 bits, the RFC 4226-recommended minimum), base32-encoded for QR/manual entry. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    private static function hotp(string $secretBytes, int $counter): string
    {
        $counterBytes = pack('N2', 0, $counter);
        $signature = hash_hmac('sha1', $counterBytes, $secretBytes, true);
        $offset = ord($signature[strlen($signature) - 1]) & 0x0F;
        $binary = ((ord($signature[$offset]) & 0x7F) << 24)
            | ((ord($signature[$offset + 1]) & 0xFF) << 16)
            | ((ord($signature[$offset + 2]) & 0xFF) << 8)
            | (ord($signature[$offset + 3]) & 0xFF);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function counterForTime(int $nowMs): int
    {
        return intdiv(intdiv($nowMs, 1000), self::STEP_SECONDS);
    }

    /**
     * Computes the current 6-digit code for a secret -- the exact operation
     * an authenticator app performs client-side. No production code path
     * in this application calls this (only the user's own authenticator
     * app ever generates a code); it exists so this algorithm has one
     * shared, tested implementation, matching the source's own
     * generateTotpCode (used there only by its test suite).
     */
    public static function generateCode(string $secretBase32, ?int $nowMs = null): string
    {
        $nowMs ??= (int) now()->valueOf();

        return self::hotp(self::base32Decode($secretBase32), self::counterForTime($nowMs));
    }

    /**
     * Verifies a 6-digit code against a base32 secret, tolerating one
     * 30-second step of clock drift either side (the standard TOTP
     * allowance). Returns the matched HOTP counter on success -- the
     * caller persists this as `last_used_counter` so the same code (or an
     * earlier one) can never be replayed -- or null if no candidate step
     * matched.
     */
    public static function verifyCode(string $secretBase32, string $code, ?int $nowMs = null): ?int
    {
        $nowMs ??= (int) now()->valueOf();
        $secretBytes = self::base32Decode($secretBase32);
        $currentCounter = self::counterForTime($nowMs);
        for ($drift = -1; $drift <= 1; $drift++) {
            $counter = $currentCounter + $drift;
            if ($counter < 0) {
                continue;
            }
            if (hash_equals(self::hotp($secretBytes, $counter), $code)) {
                return $counter;
            }
        }

        return null;
    }

    public static function validateCode(mixed $payload): string
    {
        $input = is_array($payload) ? $payload : [];
        $code = is_string($input['code'] ?? null) ? trim($input['code']) : '';
        if (! preg_match('/^\d{6}$/', $code)) {
            throw new IdentityValidationException([['code' => 'CODE_INVALID', 'path' => '/code', 'message' => 'code must be exactly 6 digits.']]);
        }

        return $code;
    }

    /** An otpauth:// URI an authenticator app (Google Authenticator, Authy, 1Password, etc.) can enrol directly -- a standard client the user already owns, not a vendor integration this platform must contract for. */
    public static function authUri(string $secretBase32, string $accountLabel, string $issuer = 'VAT-MSA'): string
    {
        $params = http_build_query([
            'secret' => $secretBase32, 'issuer' => $issuer, 'algorithm' => 'SHA1',
            'digits' => (string) self::DIGITS, 'period' => (string) self::STEP_SECONDS,
        ]);

        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($accountLabel).'?'.$params;
    }
}
