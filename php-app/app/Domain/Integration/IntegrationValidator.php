<?php

namespace App\Domain\Integration;

use App\Exceptions\IntegrationValidationException;

/**
 * Ported from lib/domain/integration.ts -- Module 10 Phase A: pure
 * validation and the connection lifecycle state machine for the generic,
 * provider-agnostic connector model (Integration aggregate /
 * `integration_connections`). No DB access, mirroring source's own
 * "each domain file owns its own tiny validation primitives" convention
 * rather than reusing another domain's identically-shaped helpers.
 */
class IntegrationValidator
{
    private const PROVIDER_KEY_PATTERN = '/^[A-Z][A-Z0-9_]{1,49}$/';

    private const CAPABILITY_PATTERN = '/^[A-Z][A-Z0-9_]{1,49}$/';

    /** @var list<string> */
    private const CATEGORY_VALUES = ['GOVERNMENT', 'BANKING', 'PAYMENT', 'ACCOUNTING', 'ERP', 'LOGISTICS', 'OTHER'];

    /** @var list<string> */
    private const DATA_CLASSIFICATION_VALUES = ['PUBLIC', 'INTERNAL', 'CONFIDENTIAL', 'TAX_CONFIDENTIAL', 'RESTRICTED'];

    private const JOB_TYPE_PATTERN = '/^[A-Z][A-Z0-9_]{1,50}$/';

    /** @var list<string> */
    private const DIRECTION_VALUES = ['INBOUND', 'OUTBOUND'];

    /**
     * RegisterIntegration. Deliberately provider-agnostic -- no ITAS/BIPA/
     * bank/treasury-specific fields -- so any SaaS/ERP provider registers
     * through this exact same shape, per source's own instruction.
     *
     * @return array{schema_version: string, provider_key: string, category: string, display_name: string, capabilities: list<string>, data_classification: string, endpoint_reference: ?string, credential_reference: ?string}
     */
    public static function registration(array $payload): array
    {
        $messages = [];
        self::schema($payload, $messages);
        $providerKey = mb_strtoupper(self::text($payload['provider_key'] ?? null));
        if (! preg_match(self::PROVIDER_KEY_PATTERN, $providerKey)) {
            $messages[] = ['code' => 'PROVIDER_KEY_INVALID', 'path' => '/provider_key', 'message' => 'provider_key must be 2 to 50 uppercase letters, numbers or underscores, starting with a letter.'];
        }
        $category = mb_strtoupper(self::text($payload['category'] ?? null));
        if (! in_array($category, self::CATEGORY_VALUES, true)) {
            $messages[] = ['code' => 'CATEGORY_INVALID', 'path' => '/category', 'message' => 'category must be one of: '.implode(', ', self::CATEGORY_VALUES).'.'];
        }
        $displayName = self::bounded($payload['display_name'] ?? null, '/display_name', 'Display name', 3, 150, $messages);
        $capabilitiesInput = is_array($payload['capabilities'] ?? null) ? $payload['capabilities'] : [];
        if ($capabilitiesInput === []) {
            $messages[] = ['code' => 'CAPABILITIES_REQUIRED', 'path' => '/capabilities', 'message' => 'At least one capability is required.'];
        }
        $capabilities = array_values(array_filter(array_map(fn ($value) => mb_strtoupper(self::text($value)), $capabilitiesInput)));
        foreach ($capabilities as $capability) {
            if (! preg_match(self::CAPABILITY_PATTERN, $capability)) {
                $messages[] = ['code' => 'CAPABILITY_INVALID', 'path' => '/capabilities', 'message' => "Capability \"{$capability}\" must be 2 to 50 uppercase letters, numbers or underscores."];
            }
        }
        $dataClassification = mb_strtoupper(self::text($payload['data_classification'] ?? null));
        if (! in_array($dataClassification, self::DATA_CLASSIFICATION_VALUES, true)) {
            $messages[] = ['code' => 'DATA_CLASSIFICATION_INVALID', 'path' => '/data_classification', 'message' => 'data_classification must be one of: '.implode(', ', self::DATA_CLASSIFICATION_VALUES).'.'];
        }
        $endpointReference = self::optionalBounded($payload['endpoint_reference'] ?? null, '/endpoint_reference', 'Endpoint reference', 3, 300, $messages);
        $credentialReference = self::optionalBounded($payload['credential_reference'] ?? null, '/credential_reference', 'Credential reference', 3, 300, $messages);
        if ($messages !== []) {
            throw new IntegrationValidationException($messages);
        }

        return [
            'schema_version' => '1.0.0', 'provider_key' => $providerKey, 'category' => $category,
            'display_name' => $displayName, 'capabilities' => $capabilities, 'data_classification' => $dataClassification,
            'endpoint_reference' => $endpointReference, 'credential_reference' => $credentialReference,
        ];
    }

    /** @return array{schema_version: string, reason: string} */
    public static function suspension(array $payload): array
    {
        $messages = [];
        self::schema($payload, $messages);
        $reason = self::bounded($payload['reason'] ?? null, '/reason', 'Reason', 10, 500, $messages);
        if ($messages !== []) {
            throw new IntegrationValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'reason' => $reason];
    }

    /** @return array{schema_version: string, job_type: string, direction: string} */
    public static function syncStart(array $payload): array
    {
        $messages = [];
        self::schema($payload, $messages);
        $jobType = mb_strtoupper(self::text($payload['job_type'] ?? null));
        if (! preg_match(self::JOB_TYPE_PATTERN, $jobType)) {
            $messages[] = ['code' => 'JOB_TYPE_INVALID', 'path' => '/job_type', 'message' => 'job_type must be 2 to 51 uppercase letters, numbers or underscores.'];
        }
        $direction = mb_strtoupper(self::text($payload['direction'] ?? null));
        if (! in_array($direction, self::DIRECTION_VALUES, true)) {
            $messages[] = ['code' => 'DIRECTION_INVALID', 'path' => '/direction', 'message' => 'direction must be INBOUND or OUTBOUND.'];
        }
        if ($messages !== []) {
            throw new IntegrationValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'job_type' => $jobType, 'direction' => $direction];
    }

    private const TRANSITIONS = [
        'DRAFT' => ['APPROVE' => 'CONFIGURED'],
        'CONFIGURED' => ['SUSPEND' => 'SUSPENDED'],
        'SUSPENDED' => ['APPROVE' => 'CONFIGURED'],
    ];

    /**
     * Only covers connections registered through RegisterIntegration --
     * this phase's own closed DRAFT/CONFIGURED/SUSPENDED enum. The four
     * pre-seeded platform connections (ITAS/BIPA/bank-org1/treasury) carry
     * free-text "REQUIRES_*_CONTRACT" configuration_status values that
     * deliberately fall outside this enum -- looking up an unrecognised
     * value here finds nothing and refuses, meaning ApproveIntegration can
     * never be the command that flips one of those genuinely
     * externally-gated connections live. A structural property of this
     * state machine's closed vocabulary, not a special case bolted on for
     * those four rows -- the same "fail closed on an unrecognised state"
     * posture this port's Payment sandbox guard already uses.
     */
    public static function assertTransition(string $action, string $current): string
    {
        $target = self::TRANSITIONS[$current][$action] ?? null;
        if ($target === null) {
            throw new IntegrationValidationException([
                ['code' => 'INTEGRATION_TRANSITION_INVALID', 'path' => '/action', 'message' => 'Cannot '.mb_strtolower($action)." a connection currently {$current}."],
            ]);
        }

        return $target;
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

    private static function optionalBounded(mixed $value, string $path, string $label, int $min, int $max, array &$messages): ?string
    {
        $normalized = self::text($value);
        if ($normalized === '') {
            return null;
        }
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
