<?php

namespace App\Domain\Identity;

use App\Exceptions\IdentityValidationException;

/** Ported from lib/domain/identity.ts's normalizeIdentityLink -- Module 1 LinkIdentity's own payload shape. */
class IdentityLinkValidator
{
    private const PROVIDER_KEY_PATTERN = '/^[A-Z][A-Z0-9_]{1,39}$/';

    /** @return array{user_id: string, provider_key: string, subject: string} */
    public static function link(array $payload): array
    {
        $messages = [];

        $userId = self::text($payload['user_id'] ?? null);
        if ($userId === '') {
            $messages[] = ['code' => 'USER_ID_REQUIRED', 'path' => '/user_id', 'message' => 'user_id is required.'];
        }
        $providerKey = mb_strtoupper(self::text($payload['provider_key'] ?? null));
        if (! preg_match(self::PROVIDER_KEY_PATTERN, $providerKey)) {
            $messages[] = ['code' => 'PROVIDER_KEY_INVALID', 'path' => '/provider_key', 'message' => 'provider_key must contain 2 to 40 uppercase letters, numbers or underscores.'];
        }
        $subject = self::text($payload['subject'] ?? null);
        if ($subject === '' || mb_strlen($subject) > 200) {
            $messages[] = ['code' => 'SUBJECT_INVALID', 'path' => '/subject', 'message' => 'subject must contain 1 to 200 characters.'];
        }
        if ($messages !== []) {
            throw new IdentityValidationException($messages);
        }

        return ['user_id' => $userId, 'provider_key' => $providerKey, 'subject' => $subject];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
