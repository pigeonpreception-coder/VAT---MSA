<?php

namespace App\Domain\AccessGovernance;

use App\Exceptions\LicensingValidationException;

/**
 * Direct port of lib/domain/control-plane.ts's normalizeAccessRevocation/
 * normalizeOffboarding -- Phase 12 slice 4 (the rest of Access
 * governance). `requestRoleAccess`/`decideAccessRequest`/
 * `certifyQuarterlyAccess` have no equivalent dedicated normalize*
 * function in the source either -- they validate inline in the repository
 * function itself -- so `AccessGovernanceService` mirrors that inline
 * validation directly rather than inventing normalizer methods the source
 * doesn't have. Reuses `App\Exceptions\LicensingValidationException`
 * rather than a new exception class, exactly matching the source's single
 * `ControlPlaneValidationError` shared across this whole file.
 */
class AccessGovernanceValidator
{
    /** @return array{grantType: string, grantId: string, reason: string} */
    public static function accessRevocation(mixed $input): array
    {
        if (! is_array($input) || array_is_list($input)) {
            throw new LicensingValidationException('PAYLOAD_INVALID', 'An access revocation object is required.');
        }
        $grantType = mb_strtoupper(trim((string) ($input['grant_type'] ?? '')));
        if (! in_array($grantType, ['ROLE', 'CAPABILITY'], true)) {
            throw new LicensingValidationException('GRANT_TYPE_INVALID', 'grant_type must be ROLE or CAPABILITY.');
        }
        $grantId = trim((string) ($input['grant_id'] ?? ''));
        if ($grantId === '') {
            throw new LicensingValidationException('GRANT_ID_REQUIRED', 'grant_id is required.');
        }
        $reason = self::cleanReason($input['reason'] ?? null);

        return ['grantType' => $grantType, 'grantId' => $grantId, 'reason' => $reason];
    }

    /** @return array{userId: string, reason: string} */
    public static function offboarding(mixed $input): array
    {
        if (! is_array($input) || array_is_list($input)) {
            throw new LicensingValidationException('PAYLOAD_INVALID', 'An offboarding object is required.');
        }
        $userId = trim((string) ($input['user_id'] ?? ''));
        if ($userId === '') {
            throw new LicensingValidationException('USER_ID_REQUIRED', 'user_id is required.');
        }
        $reason = self::cleanReason($input['reason'] ?? null);

        return ['userId' => $userId, 'reason' => $reason];
    }

    private static function cleanReason(mixed $value): string
    {
        $reason = trim((string) preg_replace('/\s+/', ' ', (string) $value));
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 240) {
            throw new LicensingValidationException('REASON_REQUIRED', 'Provide a 5 to 240 character reason.');
        }

        return $reason;
    }
}
