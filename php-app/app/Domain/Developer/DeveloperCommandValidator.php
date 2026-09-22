<?php

namespace App\Domain\Developer;

use App\Exceptions\DeveloperValidationException;

/**
 * Ported from lib/domain/developer.ts's validateClientCreation/
 * validateCredentialRevocation -- Module 10 Phase D's CreateClient/
 * RevokeCredential. No DB access, mirroring source's own "each domain file
 * owns its own tiny validation primitives" convention (same shape as
 * App\Domain\Integration\IntegrationValidator). Reuses
 * DeveloperConformanceEvaluator::RATE_LIMIT_PROFILES rather than
 * duplicating that enum a second time in this file.
 */
class DeveloperCommandValidator
{
    private const SCOPE_PATTERN = '/^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)+$/';

    /** @return array{schema_version: string, name: string, scopes: list<string>, rate_limit_profile: string} */
    public static function clientCreation(array $payload): array
    {
        $messages = [];
        self::schema($payload, $messages);
        $name = self::bounded($payload['name'] ?? null, '/name', 'Name', 3, 150, $messages);
        $scopesInput = is_array($payload['scopes'] ?? null) ? $payload['scopes'] : [];
        if ($scopesInput === []) {
            $messages[] = ['code' => 'SCOPES_REQUIRED', 'path' => '/scopes', 'message' => 'At least one scope is required.'];
        }
        $scopes = array_values(array_filter(array_map(fn ($value) => mb_strtolower(self::text($value)), $scopesInput)));
        foreach ($scopes as $scope) {
            if (! preg_match(self::SCOPE_PATTERN, $scope)) {
                $messages[] = ['code' => 'SCOPE_INVALID', 'path' => '/scopes', 'message' => "Scope \"{$scope}\" must use the form resource.action (lowercase letters/numbers, dot-separated)."];
            }
        }
        $rateLimitProfile = mb_strtoupper(self::text($payload['rate_limit_profile'] ?? null));
        if (! in_array($rateLimitProfile, DeveloperConformanceEvaluator::RATE_LIMIT_PROFILES, true)) {
            $messages[] = ['code' => 'RATE_LIMIT_PROFILE_INVALID', 'path' => '/rate_limit_profile', 'message' => 'rate_limit_profile must be one of: '.implode(', ', DeveloperConformanceEvaluator::RATE_LIMIT_PROFILES).'.'];
        }
        if ($messages !== []) {
            throw new DeveloperValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'name' => $name, 'scopes' => $scopes, 'rate_limit_profile' => $rateLimitProfile];
    }

    /** @return array{schema_version: string, reason: string} */
    public static function credentialRevocation(array $payload): array
    {
        $messages = [];
        self::schema($payload, $messages);
        $reason = self::bounded($payload['reason'] ?? null, '/reason', 'Reason', 10, 500, $messages);
        if ($messages !== []) {
            throw new DeveloperValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'reason' => $reason];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim(preg_replace('/\s+/', ' ', $value)) : '';
    }

    private static function bounded(mixed $value, string $path, string $label, int $min, int $max, array &$messages): string
    {
        $normalized = self::text($value);
        if (mb_strlen($normalized) < $min || mb_strlen($normalized) > $max) {
            $messages[] = ['code' => 'FIELD_LENGTH_INVALID', 'path' => $path, 'message' => "{$label} must contain {$min} to {$max} characters."];
        }

        return $normalized;
    }

    private static function schema(array $payload, array &$messages): void
    {
        if (($payload['schema_version'] ?? null) !== '1.0.0') {
            $messages[] = ['code' => 'SCHEMA_VERSION_UNSUPPORTED', 'path' => '/schema_version', 'message' => 'schema_version must be 1.0.0.'];
        }
    }
}
