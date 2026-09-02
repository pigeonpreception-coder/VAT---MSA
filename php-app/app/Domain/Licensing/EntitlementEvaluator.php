<?php

namespace App\Domain\Licensing;

/**
 * Direct port of lib/domain/control-plane.ts's evaluateEntitlement -- the
 * pure decision function `App\Support\Licensing\EntitlementGate`'s
 * assertEntitledOperation wraps with the actual database reads. Kept as
 * its own pure, side-effect-free class exactly like the source itself
 * separates it from the repository layer.
 */
class EntitlementEvaluator
{
    private const CONTINUITY_OPERATIONS = ['READ', 'EXPORT', 'COMPLIANCE_WRITE', 'CORRECTION_WRITE'];
    private const RESTRICTED_STATES = ['SUSPENDED', 'EXPIRED', 'CANCELLED'];

    /**
     * @param array{licenseState: string, featureKey: string, featureEnabled: bool, operationClass: string, limit: ?int, used: int, reserved?: int, requested?: int} $input
     * @return array{allowed: bool, code: string, reason: string, remaining: ?int, obligations: list<string>}
     */
    public static function evaluate(array $input): array
    {
        $requested = max(0, $input['requested'] ?? 1);
        $reserved = max(0, $input['reserved'] ?? 0);
        $used = max(0, $input['used']);
        $limit = $input['limit'];
        $remaining = $limit === null ? null : max(0, $limit - $used - $reserved);

        if (! $input['featureEnabled']) {
            return ['allowed' => false, 'code' => 'FEATURE_NOT_ENTITLED', 'reason' => "{$input['featureKey']} is not included in the organisation licence.", 'remaining' => $remaining, 'obligations' => []];
        }

        if (in_array($input['licenseState'], self::RESTRICTED_STATES, true)) {
            if (! in_array($input['operationClass'], self::CONTINUITY_OPERATIONS, true)) {
                return [
                    'allowed' => false, 'code' => "LICENSE_{$input['licenseState']}",
                    'reason' => 'The licence is restricted. Historical records remain preserved and authorised continuity actions remain available.',
                    'remaining' => $remaining, 'obligations' => ['PRESERVE_RECORDS', 'DISPLAY_RENEWAL_CONTACT'],
                ];
            }

            return [
                'allowed' => true, 'code' => 'CONTINUITY_ACCESS',
                'reason' => 'Authorised read, export, compliance or correction access remains available without deleting records.',
                'remaining' => $remaining, 'obligations' => ['READ_ONLY_UNLESS_CONTINUITY_ACTION', 'ENHANCED_AUDIT'],
            ];
        }

        if ($input['licenseState'] === 'GRACE_PERIOD' && $input['operationClass'] === 'ADMIN_WRITE') {
            return [
                'allowed' => false, 'code' => 'GRACE_PERIOD_NO_EXPANSION',
                'reason' => 'The grace period does not permit expanding users, branches, roles or licensed capacity.',
                'remaining' => $remaining, 'obligations' => ['DISPLAY_RENEWAL_CONTACT'],
            ];
        }

        if ($remaining !== null && $requested > $remaining) {
            return [
                'allowed' => false, 'code' => 'ENTITLEMENT_LIMIT_EXCEEDED',
                'reason' => "The requested operation exceeds the {$input['featureKey']} licence limit.",
                'remaining' => $remaining, 'obligations' => ['NO_PARTIAL_WRITE', 'AUDIT_DENIAL'],
            ];
        }

        return [
            'allowed' => true, 'code' => 'ENTITLED', 'reason' => 'The organisation licence permits this operation.',
            'remaining' => $remaining === null ? null : $remaining - $requested,
            'obligations' => $input['licenseState'] === 'TRIAL' ? ['TRIAL_LABEL'] : [],
        ];
    }
}
