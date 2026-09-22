<?php

namespace App\Domain\Developer;

/**
 * Ported from lib/domain/developer.ts's evaluateClientConformance/
 * conformanceOutcome -- Module 10 Phase D's RunConformance harness: a
 * fixed, code-versioned catalogue of explainable checks, the same shape
 * Module 9 Phase B / Module 10 Phase C already established (named,
 * deterministic, PASS/FAIL with a rationale). Pure computation, no DB
 * access -- mirrors this migration's own ReconciliationValidator::
 * evaluateInvoiceMatch() precedent for a domain-layer "evaluate against
 * given state" method living alongside validation rather than in the
 * service.
 *
 * CREDENTIAL_ISSUED checks this platform's own internal credential_refs
 * bookkeeping (genuinely PASSable today); EXTERNAL_CREDENTIAL_PROVISIONED
 * is deliberately NOT_CONFIGURED and non-blocking -- this codebase has no
 * real secret manager integration to mint a live production credential
 * (api_clients.status stays PENDING_CREDENTIAL_PROVISIONING forever in
 * this phase), and persisting that honestly rather than silently passing
 * or skipping it matches Module 9 Phase B's IDENTITY_VERIFICATION/
 * BANK_ACCOUNT_OWNERSHIP/SANCTIONS_SCREENING precedent exactly.
 */
class DeveloperConformanceEvaluator
{
    public const TEST_SUITE_VERSION = '1.0';

    private const SCOPE_PATTERN = '/^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)+$/';

    /** @var list<string> */
    public const RATE_LIMIT_PROFILES = ['SANDBOX', 'PILOT_STANDARD', 'PILOT_ELEVATED'];

    /**
     * @param  array{scopes: list<string>, rate_limit_profile: string, client_status: string, current_credential_status: ?string}  $input
     * @return list<array{code: string, status: string, rationale: string}>
     */
    public static function evaluate(array $input): array
    {
        $checks = [];

        $scopes = $input['scopes'];
        $validScopes = $scopes !== [] && array_reduce($scopes, fn ($ok, $scope) => $ok && preg_match(self::SCOPE_PATTERN, $scope) === 1, true);
        $checks[] = $validScopes
            ? ['code' => 'SCOPES_DECLARED', 'status' => 'PASS', 'rationale' => count($scopes).' valid scope(s) declared.']
            : ['code' => 'SCOPES_DECLARED', 'status' => 'FAIL', 'rationale' => 'No valid scopes are declared for this client.'];

        $rateLimitProfile = $input['rate_limit_profile'];
        $checks[] = in_array($rateLimitProfile, self::RATE_LIMIT_PROFILES, true)
            ? ['code' => 'RATE_LIMIT_PROFILE_KNOWN', 'status' => 'PASS', 'rationale' => "{$rateLimitProfile} is a recognised rate-limit profile."]
            : ['code' => 'RATE_LIMIT_PROFILE_KNOWN', 'status' => 'FAIL', 'rationale' => "{$rateLimitProfile} is not a recognised rate-limit profile."];

        $checks[] = $input['client_status'] !== 'REVOKED'
            ? ['code' => 'CLIENT_OPERATIONAL', 'status' => 'PASS', 'rationale' => 'The client has not been revoked.']
            : ['code' => 'CLIENT_OPERATIONAL', 'status' => 'FAIL', 'rationale' => "This client's credential has been revoked."];

        $currentCredentialStatus = $input['current_credential_status'];
        $checks[] = $currentCredentialStatus === 'ACTIVE'
            ? ['code' => 'CREDENTIAL_ISSUED', 'status' => 'PASS', 'rationale' => 'A credential reference is on record and marked ACTIVE.']
            : ['code' => 'CREDENTIAL_ISSUED', 'status' => 'FAIL', 'rationale' => 'No ACTIVE credential reference is on record (current: '.($currentCredentialStatus ?? 'none').').'];

        $checks[] = ['code' => 'EXTERNAL_CREDENTIAL_PROVISIONED', 'status' => 'NOT_CONFIGURED', 'rationale' => 'No external secret manager is integrated in this environment -- the credential reference is a pointer, never a live secret. Advisory only; does not block conformance.'];

        return $checks;
    }

    /** @param list<array{code: string, status: string, rationale: string}> $checks */
    public static function outcome(array $checks): string
    {
        foreach ($checks as $check) {
            if ($check['status'] === 'FAIL') {
                return 'FAILED';
            }
        }

        return 'PASSED';
    }
}
