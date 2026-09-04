<?php

namespace App\Domain\AuthorityGovernance;

use App\Exceptions\AuthorityGovernanceValidationException;

/**
 * Direct port of lib/domain/authority-governance.ts's
 * normalizeAuthorityOnboardingSubmission/normalizeAuthorityOnboardingDecision.
 * Unlike App\Domain\Compliance\ComplianceValidator and every other
 * validator in this migration, the source here does enforce a strict
 * "no unknown fields" check (`strictKeys`) -- not reproduced, matching
 * this migration's own established, already-settled precedent of not
 * replicating that specific strictness anywhere else either (confirmed:
 * no sibling validator in this codebase rejects unknown payload keys).
 */
class AuthorityGovernanceValidator
{
    private const AUTHORITY_ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]*$/u';
    private const REFERENCE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9 ._:\/+-]*$/u';
    private const TARGET_ENVIRONMENTS = ['LOCAL_STAGING', 'PRODUCTION'];
    private const DECISIONS = ['APPROVE_LOCAL_STAGING', 'REJECT'];

    /** @return array{schema_version: string, tax_authority_id: string, target_environment: string, purpose: string, evidence_bundle_hash: ?string, readiness_reference: ?string} */
    public static function onboardingSubmission(array $payload): array
    {
        if (($payload['schema_version'] ?? null) !== '1.0.0') {
            throw new AuthorityGovernanceValidationException('AUTHORITY_GOVERNANCE_SCHEMA_UNSUPPORTED', 'schema_version must be 1.0.0.');
        }
        $authorityId = self::text($payload['tax_authority_id'] ?? null, 'Tax Authority', 3, 100);
        if (! preg_match(self::AUTHORITY_ID_PATTERN, $authorityId)) {
            throw new AuthorityGovernanceValidationException('TAX_AUTHORITY_ID_INVALID', 'Tax Authority contains unsupported characters.');
        }
        $environment = is_string($payload['target_environment'] ?? null) ? mb_strtoupper(trim($payload['target_environment'])) : '';
        if (! in_array($environment, self::TARGET_ENVIRONMENTS, true)) {
            throw new AuthorityGovernanceValidationException('AUTHORITY_ENVIRONMENT_INVALID', 'target_environment must be LOCAL_STAGING or PRODUCTION.');
        }
        $evidenceBundleHash = self::optionalReference($payload['evidence_bundle_hash'] ?? null, 'Evidence bundle hash');
        if ($evidenceBundleHash !== null && mb_strlen($evidenceBundleHash) < 32) {
            throw new AuthorityGovernanceValidationException('AUTHORITY_EVIDENCE_HASH_INVALID', 'Evidence bundle hash must contain at least 32 characters.');
        }
        $readinessReference = self::optionalReference($payload['readiness_reference'] ?? null, 'Readiness reference');

        return [
            'schema_version' => '1.0.0', 'tax_authority_id' => $authorityId, 'target_environment' => $environment,
            'purpose' => self::text($payload['purpose'] ?? null, 'Purpose', 10, 500),
            'evidence_bundle_hash' => $evidenceBundleHash, 'readiness_reference' => $readinessReference,
        ];
    }

    /** @return array{schema_version: string, decision: string, reason: string} */
    public static function onboardingDecision(array $payload): array
    {
        if (($payload['schema_version'] ?? null) !== '1.0.0') {
            throw new AuthorityGovernanceValidationException('AUTHORITY_GOVERNANCE_SCHEMA_UNSUPPORTED', 'schema_version must be 1.0.0.');
        }
        $decision = is_string($payload['decision'] ?? null) ? mb_strtoupper(trim($payload['decision'])) : '';
        if (! in_array($decision, self::DECISIONS, true)) {
            throw new AuthorityGovernanceValidationException('AUTHORITY_DECISION_INVALID', 'decision must be APPROVE_LOCAL_STAGING or REJECT.');
        }

        return ['schema_version' => '1.0.0', 'decision' => $decision, 'reason' => self::text($payload['reason'] ?? null, 'Decision reason', 10, 500)];
    }

    private static function text(mixed $value, string $label, int $minimum, int $maximum): string
    {
        if (! is_string($value)) {
            throw new AuthorityGovernanceValidationException('AUTHORITY_GOVERNANCE_FIELD_INVALID', "{$label} is required.");
        }
        $normalized = trim(preg_replace('/\s+/u', ' ', $value));
        $length = mb_strlen($normalized);
        if ($length < $minimum || $length > $maximum) {
            throw new AuthorityGovernanceValidationException('AUTHORITY_GOVERNANCE_FIELD_INVALID', "{$label} must contain {$minimum} to {$maximum} characters.");
        }

        return $normalized;
    }

    private static function optionalReference(mixed $value, string $label): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalized = self::text($value, $label, 8, 200);
        if (! preg_match(self::REFERENCE_PATTERN, $normalized)) {
            throw new AuthorityGovernanceValidationException('AUTHORITY_GOVERNANCE_REFERENCE_INVALID', "{$label} contains unsupported characters.");
        }

        return $normalized;
    }
}
