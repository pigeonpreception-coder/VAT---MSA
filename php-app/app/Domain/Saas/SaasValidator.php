<?php

namespace App\Domain\Saas;

use App\Exceptions\SaasValidationException;

/**
 * Direct port of lib/domain/saas.ts -- Module 10 Phase C: SaaS provider
 * onboarding. Pure validation plus the conformance test harness's fixed,
 * code-versioned check catalogue, no DB access. Unlike the source's
 * SaasValidationError (every failing field reported at once via a
 * `messages` array), this throws on the first invalid field -- matching
 * this migration's own established single-error-at-a-time
 * *ValidationException precedent (see TaxpayerSystemValidator).
 */
class SaasValidator
{
    private const PROVIDER_KEY_PATTERN = '/^[A-Z][A-Z0-9_]{1,49}$/';
    private const CAPABILITY_PATTERN = '/^[A-Z][A-Z0-9_]{1,49}$/';
    private const EMAIL_PATTERN = '/^[^\s@]+@[^\s@]+\.[^\s@]+$/';
    private const ENDPOINT_PATTERN = '/^https:\/\/[^\s]{5,290}$/';
    private const CATEGORIES = ['ACCOUNTING', 'ERP', 'PAYROLL', 'BANKING', 'LOGISTICS', 'OTHER'];
    private const ENVIRONMENTS = ['SANDBOX', 'PRODUCTION'];

    public const CONFORMANCE_SUITE_VERSION = '1.0';

    /**
     * The full documented event catalogue
     * (08-enterprise-architecture/event-catalog.csv) a conformance
     * submission's acknowledged_events is checked against. Kept as a
     * literal, code-versioned list (not read from the CSV at runtime) so a
     * change to the catalogue is a deliberate, reviewed edit here, not a
     * silent behavioural drift -- reproduced verbatim from source.
     */
    public const KNOWN_EVENT_CATALOG = [
        'TaxpayerRegistered', 'TaxpayerVerified', 'UserCreated', 'UserRoleChanged', 'QuotationCreated', 'QuotationAccepted',
        'InvoiceCreated', 'InvoiceCertified', 'InvoiceCancelled', 'InvoiceCorrected', 'VATTransactionCreated', 'VATTransactionMatched',
        'VATTransactionExceptionDetected', 'VATPeriodOpened', 'VATPeriodClosed', 'VATReturnGenerated', 'VATReturnSubmitted',
        'AuditCaseCreated', 'AuditCaseAssigned', 'RefundReviewStarted', 'DocumentUploaded', 'SecurityThreatDetected', 'IdentityLinked',
        'ConsentRevoked', 'ReturnAcknowledged', 'PaymentSettled', 'SyncConflictDetected', 'LicensePurchased', 'LicenseActivated',
        'LicenseExpired', 'LicenseSuspended', 'LicenseUpgraded', 'LicenseDowngraded', 'OrganisationAdminCreated', 'OrganisationAdminChanged',
        'EmployeeInvited', 'EmployeeActivated', 'EmployeeSuspended', 'EmployeeTerminated', 'RoleCreated', 'RoleChanged',
        'PermissionGranted', 'PermissionRevoked', 'WorkflowCreated', 'WorkflowPublished', 'WorkflowChanged', 'WorkflowRetired',
        'WorkflowDecisionRecorded', 'AccessRequested', 'AccessApproved', 'AccessRejected', 'AccessCertified', 'SoDViolationDetected',
        'PrivilegedActionPerformed', 'NavigationConfigurationChanged', 'CountryPackVersionCreated', 'CountryPackValidated',
        'CountryPackApproved', 'CountryPackActivated', 'CountryPackRejected', 'JurisdictionResolved', 'JurisdictionConflictDetected',
        'OrganisationJurisdictionMigrationRequested', 'OrganisationJurisdictionMigrationCompleted', 'CurrencyRatePublished',
        'ManualCurrencyRateApproved', 'TaxRuleVersionSelected', 'TaxDeterminationCompleted', 'CountryReadinessStateChanged',
        'DocumentTemplatePublished', 'BusinessCalendarPublished', 'DataResidencyPolicyActivated', 'SecurityProfileVersionCreated',
        'SecurityProfileValidated', 'SecurityProfileApproved', 'SecurityProfileActivated', 'SecurityProfileRejected',
        'PrivilegedAccessRequested', 'PrivilegedAccessGranted', 'PrivilegedAccessExpired', 'SecurityIncidentDeclared',
        'SecurityResponseActionExecuted', 'DigitalEvidenceCollected', 'PrivacyRightsRequestReceived', 'PrivacyImpactAssessmentApproved',
        'VulnerabilityDetected', 'VulnerabilityRemediated', 'BackupRestoreTestCompleted', 'SecurityControlStateChanged',
    ];

    private const RESTRICTED_DATA_CLASSIFICATIONS = ['TAX_CONFIDENTIAL', 'RESTRICTED'];

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim(preg_replace('/\s+/', ' ', $value)) : '';
    }

    private static function bounded(mixed $value, string $label, int $min, int $max): string
    {
        $normalized = self::text($value);
        if (mb_strlen($normalized) < $min || mb_strlen($normalized) > $max) {
            throw new SaasValidationException('FIELD_LENGTH_INVALID', "{$label} must contain {$min} to {$max} characters.");
        }

        return $normalized;
    }

    private static function assertSchema(array $payload): void
    {
        if (($payload['schema_version'] ?? null) !== '1.0.0') {
            throw new SaasValidationException('SCHEMA_VERSION_UNSUPPORTED', 'schema_version must be 1.0.0.');
        }
    }

    /**
     * RegisterProvider. No separate "create application" verb is named by
     * the playbook -- a provider registers with exactly one named
     * application (the one it wants conformance-certified) in the same
     * call, creating both the SaaSProvider and Application rows atomically.
     *
     * @return array{schema_version: string, provider_key: string, legal_name: string, contact_email: string, category: string, application: array{name: string, description: string, requested_capabilities: list<string>, endpoint_reference: string}}
     */
    public static function providerRegistration(array $payload): array
    {
        self::assertSchema($payload);
        $providerKey = mb_strtoupper(self::text($payload['provider_key'] ?? null));
        if (! preg_match(self::PROVIDER_KEY_PATTERN, $providerKey)) {
            throw new SaasValidationException('PROVIDER_KEY_INVALID', 'provider_key must be 2 to 50 uppercase letters, numbers or underscores, starting with a letter.');
        }
        $legalName = self::bounded($payload['legal_name'] ?? null, 'Legal name', 3, 200);
        $contactEmail = mb_strtolower(self::text($payload['contact_email'] ?? null));
        if (! preg_match(self::EMAIL_PATTERN, $contactEmail)) {
            throw new SaasValidationException('EMAIL_INVALID', 'A valid contact email address is required.');
        }
        $category = mb_strtoupper(self::text($payload['category'] ?? null));
        if (! in_array($category, self::CATEGORIES, true)) {
            throw new SaasValidationException('CATEGORY_INVALID', 'category must be one of: '.implode(', ', self::CATEGORIES).'.');
        }

        $appInput = is_array($payload['application'] ?? null) ? $payload['application'] : [];
        $name = self::bounded($appInput['name'] ?? null, 'Application name', 3, 150);
        $description = self::bounded($appInput['description'] ?? null, 'Application description', 10, 2_000);
        $capabilitiesInput = is_array($appInput['requested_capabilities'] ?? null) ? $appInput['requested_capabilities'] : [];
        if (count($capabilitiesInput) === 0) {
            throw new SaasValidationException('CAPABILITIES_REQUIRED', 'At least one requested capability is required.');
        }
        $requestedCapabilities = array_values(array_filter(array_map(fn ($value) => mb_strtoupper(self::text($value)), $capabilitiesInput)));
        foreach ($requestedCapabilities as $capability) {
            if (! preg_match(self::CAPABILITY_PATTERN, $capability)) {
                throw new SaasValidationException('CAPABILITY_INVALID', "Capability \"{$capability}\" must be 2 to 50 uppercase letters, numbers or underscores.");
            }
        }
        $endpointReference = self::text($appInput['endpoint_reference'] ?? null);
        if (! preg_match(self::ENDPOINT_PATTERN, $endpointReference)) {
            throw new SaasValidationException('ENDPOINT_REFERENCE_INVALID', 'endpoint_reference must be an https:// URL.');
        }

        return [
            'schema_version' => '1.0.0', 'provider_key' => $providerKey, 'legal_name' => $legalName,
            'contact_email' => $contactEmail, 'category' => $category,
            'application' => [
                'name' => $name, 'description' => $description,
                'requested_capabilities' => $requestedCapabilities, 'endpoint_reference' => $endpointReference,
            ],
        ];
    }

    /** @return array{schema_version: string, environment: string, tested_capabilities: list<string>, acknowledged_events: list<string>} */
    public static function conformanceSubmission(array $payload): array
    {
        self::assertSchema($payload);
        $environment = mb_strtoupper(self::text($payload['environment'] ?? null));
        if (! in_array($environment, self::ENVIRONMENTS, true)) {
            throw new SaasValidationException('ENVIRONMENT_INVALID', 'environment must be SANDBOX or PRODUCTION.');
        }
        $testedInput = is_array($payload['tested_capabilities'] ?? null) ? $payload['tested_capabilities'] : [];
        if (count($testedInput) === 0) {
            throw new SaasValidationException('CAPABILITIES_REQUIRED', 'At least one tested capability is required.');
        }
        $testedCapabilities = array_values(array_filter(array_map(fn ($value) => mb_strtoupper(self::text($value)), $testedInput)));
        $eventsInput = is_array($payload['acknowledged_events'] ?? null) ? $payload['acknowledged_events'] : [];
        if (count($eventsInput) === 0) {
            throw new SaasValidationException('EVENTS_REQUIRED', 'At least one acknowledged event is required.');
        }
        $acknowledgedEvents = array_values(array_filter(array_map(fn ($value) => self::text($value), $eventsInput)));

        return [
            'schema_version' => '1.0.0', 'environment' => $environment,
            'tested_capabilities' => $testedCapabilities, 'acknowledged_events' => $acknowledgedEvents,
        ];
    }

    /**
     * The conformance test harness itself -- a fixed, code-versioned
     * catalogue of explainable checks (mirrors Module 9 Phase B's
     * refund_claim_checks pattern: named, deterministic, PASS/FAIL with a
     * rationale, never a black-box composite score), evaluated purely from
     * data already on hand (the application's own registration, the
     * submission, and -- for SANDBOX_PRECEDES_PRODUCTION only -- whether a
     * prior PASSED sandbox run exists, passed in by the service since
     * that's the one check needing a DB read). No live endpoint is ever
     * actually called -- there is nothing to call.
     *
     * @param array{requested_capabilities: list<string>} $application
     * @param array{environment: string, tested_capabilities: list<string>, acknowledged_events: list<string>} $submission
     * @return list<array{code: string, status: string, rationale: string}>
     */
    public static function evaluateConformance(array $application, array $submission, bool $priorSandboxPassed): array
    {
        $checks = [];

        $unrequested = array_values(array_diff($submission['tested_capabilities'], $application['requested_capabilities']));
        $checks[] = count($unrequested) === 0
            ? ['code' => 'CAPABILITY_SCOPE_MATCHED', 'status' => 'PASS', 'rationale' => 'Every tested capability was declared at registration.']
            : ['code' => 'CAPABILITY_SCOPE_MATCHED', 'status' => 'FAIL', 'rationale' => 'Tested capabilities exceed what was registered: '.implode(', ', $unrequested).'.'];

        $unknownEvents = array_values(array_diff($submission['acknowledged_events'], self::KNOWN_EVENT_CATALOG));
        $checks[] = count($unknownEvents) === 0
            ? ['code' => 'EVENT_CONTRACT_ACKNOWLEDGED', 'status' => 'PASS', 'rationale' => 'All '.count($submission['acknowledged_events']).' acknowledged event(s) match the documented event catalogue.']
            : ['code' => 'EVENT_CONTRACT_ACKNOWLEDGED', 'status' => 'FAIL', 'rationale' => 'Unrecognised event name(s), not present in the documented catalogue: '.implode(', ', $unknownEvents).'.'];

        $restrictedRequested = count(array_intersect($submission['tested_capabilities'], self::RESTRICTED_DATA_CLASSIFICATIONS)) > 0;
        $checks[] = $submission['environment'] === 'PRODUCTION' || ! $restrictedRequested
            ? ['code' => 'DATA_CLASSIFICATION_BOUNDED', 'status' => 'PASS', 'rationale' => 'No restricted-tier data classification requested for a SANDBOX submission.']
            : ['code' => 'DATA_CLASSIFICATION_BOUNDED', 'status' => 'FAIL', 'rationale' => 'SANDBOX conformance may not request a TAX_CONFIDENTIAL/RESTRICTED-tier capability.'];

        $checks[] = $submission['environment'] === 'SANDBOX' || $priorSandboxPassed
            ? ['code' => 'SANDBOX_PRECEDES_PRODUCTION', 'status' => 'PASS', 'rationale' => $submission['environment'] === 'SANDBOX' ? 'Not applicable to a SANDBOX submission.' : 'A prior PASSED SANDBOX conformance run exists for this application.']
            : ['code' => 'SANDBOX_PRECEDES_PRODUCTION', 'status' => 'FAIL', 'rationale' => 'A PRODUCTION conformance submission requires a prior PASSED SANDBOX run for the same application.'];

        return $checks;
    }

    /** @param list<array{code: string, status: string, rationale: string}> $checks */
    public static function conformanceOutcome(array $checks): string
    {
        foreach ($checks as $check) {
            if ($check['status'] !== 'PASS') {
                return 'FAILED';
            }
        }

        return 'PASSED';
    }
}
