<?php

namespace App\Domain\Licensing;

use App\Exceptions\LicensingValidationException;

/**
 * Direct port of lib/domain/control-plane.ts's normalizeLicenseStateChange/
 * assertLicenseStateTransition/normalizeLicenseUpgrade -- the Licensing &
 * Entitlements slice of Phase 12 (portals/licensing/governance).
 */
class LicensingValidator
{
    private const ACTIONS = ['ACTIVATE', 'SUSPEND', 'RENEW'];

    /**
     * EXPIRED/CANCELLED are deliberately terminal for these three actions
     * -- reaching either from here requires a new subscription, not a
     * state-change command.
     */
    private const STATE_TRANSITIONS = [
        'ACTIVATE' => ['TRIAL', 'GRACE_PERIOD', 'PENDING_RENEWAL', 'SUSPENDED'],
        'SUSPEND' => ['TRIAL', 'ACTIVE', 'GRACE_PERIOD', 'PENDING_RENEWAL'],
        'RENEW' => ['ACTIVE', 'GRACE_PERIOD', 'PENDING_RENEWAL', 'EXPIRED'],
    ];

    private const PLAN_CODE_PATTERN = '/^[A-Z][A-Z0-9_-]{1,39}$/';

    /**
     * Small, additive, read-only accessor over STATE_TRANSITIONS -- lets a
     * UI dropdown offer only the actions that would actually succeed from
     * a licence's current state, without duplicating this table (and
     * risking drift from assertStateTransition's own authoritative
     * enforcement of it). Same pattern already established for
     * ComplianceValidator::refundClaimActionsFor()/caseActionsFor().
     *
     * @return list<string>
     */
    public static function actionsFor(string $currentState): array
    {
        return array_values(array_filter(self::ACTIONS, fn (string $action) => in_array($currentState, self::STATE_TRANSITIONS[$action] ?? [], true)));
    }

    /** @return array{action: string, reason: string} */
    public static function stateChange(mixed $input): array
    {
        if (! is_array($input) || array_is_list($input)) {
            throw new LicensingValidationException('PAYLOAD_INVALID', 'A licence state-change object is required.');
        }
        $action = mb_strtoupper(trim((string) ($input['action'] ?? '')));
        if (! in_array($action, self::ACTIONS, true)) {
            throw new LicensingValidationException('LICENSE_ACTION_INVALID', 'action must be one of: '.implode(', ', self::ACTIONS).'.');
        }
        $reason = trim((string) preg_replace('/\s+/', ' ', (string) ($input['reason'] ?? '')));
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 240) {
            throw new LicensingValidationException('REASON_REQUIRED', 'Provide a 5 to 240 character reason.');
        }

        return ['action' => $action, 'reason' => $reason];
    }

    public static function assertStateTransition(string $action, string $currentState): void
    {
        if (! in_array($currentState, self::STATE_TRANSITIONS[$action] ?? [], true)) {
            throw new LicensingValidationException('LICENSE_TRANSITION_INVALID', "Cannot ".mb_strtolower($action)." a licence currently in state {$currentState}.");
        }
    }

    /** @return array{licensePlanCode: string} */
    public static function upgrade(mixed $input): array
    {
        if (! is_array($input) || array_is_list($input)) {
            throw new LicensingValidationException('PAYLOAD_INVALID', 'A licence upgrade object is required.');
        }
        $licensePlanCode = mb_strtoupper(trim((string) ($input['license_plan_code'] ?? '')));
        if (! preg_match(self::PLAN_CODE_PATTERN, $licensePlanCode)) {
            throw new LicensingValidationException('LICENSE_PLAN_CODE_INVALID', 'license_plan_code must contain 2 to 40 letters, numbers, hyphens or underscores.');
        }

        return ['licensePlanCode' => $licensePlanCode];
    }
}
