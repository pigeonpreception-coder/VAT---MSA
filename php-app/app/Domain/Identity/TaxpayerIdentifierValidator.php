<?php

namespace App\Domain\Identity;

use App\Exceptions\IdentityValidationException;

/**
 * Ported from lib/domain/identity.ts's normalizeIdentifierCorrection --
 * Module 1 Taxpayer IdentifierVersion / correction's own payload shape.
 */
class TaxpayerIdentifierValidator
{
    private const IDENTIFIER_PATTERN = '/^[A-Z0-9][A-Z0-9.\/-]{2,39}$/';

    /** @return array{identifier_value: string, reason: string} */
    public static function correction(array $payload): array
    {
        $messages = [];

        $identifierValue = mb_strtoupper(self::text($payload['identifier_value'] ?? null));
        if (! preg_match(self::IDENTIFIER_PATTERN, $identifierValue)) {
            $messages[] = ['code' => 'IDENTIFIER_INVALID', 'path' => '/identifier_value', 'message' => 'Identifier value must contain 3 to 40 letters, numbers, dots, slashes or hyphens.'];
        }
        $reason = self::collapseWhitespace(self::text($payload['reason'] ?? null));
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 240) {
            $messages[] = ['code' => 'FIELD_LENGTH_INVALID', 'path' => '/reason', 'message' => 'Correction reason must contain 5 to 240 characters.'];
        }
        if ($messages !== []) {
            throw new IdentityValidationException($messages);
        }

        return ['identifier_value' => $identifierValue, 'reason' => $reason];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function collapseWhitespace(string $value): string
    {
        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }
}
