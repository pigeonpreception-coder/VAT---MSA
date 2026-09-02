<?php

namespace App\Domain\Platform;

use App\Exceptions\PlatformValidationException;

/**
 * Direct port of lib/domain/platform.ts's validatePlatformChangeRequest/
 * validatePlatformChangeDecision/validateProvisionStaff (plus its
 * PLATFORM_STAFF_ROLES constant) -- Module 8 Phase A's platform
 * config/change-management command payloads, the last sub-module of
 * platform-repository.ts.
 */
class PlatformChangeValidator
{
    private const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{1,99}$/';

    private const EMAIL_PATTERN = '/^[^\s@]+@[^\s@]+\.[^\s@]+$/';

    private const CHANGE_TARGET_TYPES = ['FEATURE_FLAG', 'PLATFORM_CONFIG', 'ACCESS_POLICY'];

    /**
     * Platform/NamRA technical staff -- accounts with no taxpayer_id,
     * holding a national-scope role. Deliberately a distinct, narrower set
     * of roles than Organisation Administration's own employee invitation
     * (which onboards TAXPAYER-side staff into one organisation) -- these
     * are the platform's own internal accounts.
     */
    public const PLATFORM_STAFF_ROLES = [
        'SUPER_ADMIN', 'INFRASTRUCTURE_ADMIN', 'SECURITY_ANALYST', 'INTERNAL_AUDITOR', 'PILOT_ADMIN',
        'NAMRA_SYSTEM_ADMIN', 'NAMRA_SUPERVISOR', 'NAMRA_COMPLIANCE_OFFICER', 'NAMRA_AUDITOR', 'NAMRA_REFUND_OFFICER',
    ];

    /**
     * Module 8 Phase A RequestPlatformChange: one generic command family
     * behind ChangeFeature/ChangePolicy/ChangeConfig, not three
     * near-identical copy-pasted commands. Only validates generic shape
     * here; the proposed_value's per-target-type contents are checked in
     * App\Services\Platform\PlatformChangeService, which already needs the
     * target's current row to build the diff.
     *
     * @return array{schema_version: string, target_type: string, target_id: string, proposed_value: array<string, mixed>, reason: string}
     */
    public static function requestChange(array $payload): array
    {
        $messages = [];
        if (($payload['schema_version'] ?? null) !== '1.0.0') {
            $messages[] = ['code' => 'SCHEMA_VERSION_UNSUPPORTED', 'path' => '/schema_version', 'message' => 'schema_version must be 1.0.0.'];
        }
        $targetType = is_string($payload['target_type'] ?? null) ? mb_strtoupper(trim($payload['target_type'])) : '';
        if (! in_array($targetType, self::CHANGE_TARGET_TYPES, true)) {
            $messages[] = ['code' => 'TARGET_TYPE_INVALID', 'path' => '/target_type', 'message' => 'target_type must be FEATURE_FLAG, PLATFORM_CONFIG or ACCESS_POLICY.'];
        }
        $targetId = is_string($payload['target_id'] ?? null) ? trim($payload['target_id']) : '';
        if (! preg_match(self::ID_PATTERN, $targetId)) {
            $messages[] = ['code' => 'TARGET_ID_INVALID', 'path' => '/target_id', 'message' => 'target_id is invalid.'];
        }
        $proposedValue = $payload['proposed_value'] ?? null;
        if (! is_array($proposedValue)) {
            $messages[] = ['code' => 'PROPOSED_VALUE_INVALID', 'path' => '/proposed_value', 'message' => 'proposed_value must be an object.'];
        } else {
            $serialized = json_encode($proposedValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($serialized === false) {
                $messages[] = ['code' => 'PROPOSED_VALUE_INVALID', 'path' => '/proposed_value', 'message' => 'proposed_value must be JSON-serializable.'];
            } elseif (mb_strlen($serialized) > 4_096) {
                $messages[] = ['code' => 'PROPOSED_VALUE_TOO_LARGE', 'path' => '/proposed_value', 'message' => 'proposed_value must serialize to at most 4096 characters.'];
            }
        }
        $reason = is_string($payload['reason'] ?? null) ? trim($payload['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            $messages[] = ['code' => 'REASON_INVALID', 'path' => '/reason', 'message' => 'reason must contain 5 to 500 characters.'];
        }
        if (count($messages) > 0) {
            throw new PlatformValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'target_type' => $targetType, 'target_id' => $targetId, 'proposed_value' => $proposedValue, 'reason' => $reason];
    }

    /**
     * Module 8 Phase A DecidePlatformChange: maker-checker on a pending
     * change request -- a decision, either way, always needs a recorded
     * rationale.
     *
     * @return array{schema_version: string, decision: string, notes: string}
     */
    public static function decideChange(array $payload): array
    {
        $messages = [];
        if (($payload['schema_version'] ?? null) !== '1.0.0') {
            $messages[] = ['code' => 'SCHEMA_VERSION_UNSUPPORTED', 'path' => '/schema_version', 'message' => 'schema_version must be 1.0.0.'];
        }
        $decision = is_string($payload['decision'] ?? null) ? mb_strtoupper(trim($payload['decision'])) : '';
        if ($decision !== 'APPROVE' && $decision !== 'REJECT') {
            $messages[] = ['code' => 'DECISION_INVALID', 'path' => '/decision', 'message' => 'decision must be APPROVE or REJECT.'];
        }
        $notes = is_string($payload['notes'] ?? null) ? trim($payload['notes']) : '';
        if (mb_strlen($notes) < 5 || mb_strlen($notes) > 500) {
            $messages[] = ['code' => 'NOTES_INVALID', 'path' => '/notes', 'message' => 'notes must contain 5 to 500 characters.'];
        }
        if (count($messages) > 0) {
            throw new PlatformValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'decision' => $decision, 'notes' => $notes];
    }

    /**
     * Module 8 Phase A ProvisionStaff.
     *
     * @return array{schema_version: string, external_user_id: string, email: string, display_name: string, role: string}
     */
    public static function provisionStaff(array $payload): array
    {
        $messages = [];
        if (($payload['schema_version'] ?? null) !== '1.0.0') {
            $messages[] = ['code' => 'SCHEMA_VERSION_UNSUPPORTED', 'path' => '/schema_version', 'message' => 'schema_version must be 1.0.0.'];
        }
        $externalUserId = is_string($payload['external_user_id'] ?? null) ? trim($payload['external_user_id']) : '';
        if (! preg_match(self::ID_PATTERN, $externalUserId)) {
            $messages[] = ['code' => 'EXTERNAL_USER_ID_INVALID', 'path' => '/external_user_id', 'message' => 'external_user_id is invalid.'];
        }
        $email = is_string($payload['email'] ?? null) ? mb_strtolower(trim($payload['email'])) : '';
        if (! preg_match(self::EMAIL_PATTERN, $email)) {
            $messages[] = ['code' => 'EMAIL_INVALID', 'path' => '/email', 'message' => 'A valid email is required.'];
        }
        $displayName = is_string($payload['display_name'] ?? null) ? trim($payload['display_name']) : '';
        if (mb_strlen($displayName) < 2 || mb_strlen($displayName) > 120) {
            $messages[] = ['code' => 'DISPLAY_NAME_INVALID', 'path' => '/display_name', 'message' => 'display_name must contain 2 to 120 characters.'];
        }
        $role = is_string($payload['role'] ?? null) ? mb_strtoupper(trim($payload['role'])) : '';
        if (! in_array($role, self::PLATFORM_STAFF_ROLES, true)) {
            $messages[] = ['code' => 'ROLE_INVALID', 'path' => '/role', 'message' => 'role is not a provisionable platform staff role.'];
        }
        if (count($messages) > 0) {
            throw new PlatformValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'external_user_id' => $externalUserId, 'email' => $email, 'display_name' => $displayName, 'role' => $role];
    }
}
