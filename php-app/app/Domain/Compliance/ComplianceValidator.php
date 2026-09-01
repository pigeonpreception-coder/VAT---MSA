<?php

namespace App\Domain\Compliance;

use App\Exceptions\ComplianceValidationException;

/**
 * Direct port of lib/domain/compliance.ts's normalize/validate functions
 * and its two adjacency-list state machines (audit case lifecycle,
 * refund-claim lifecycle). This phase's slice covers: audit cases
 * (open/transition/findings/evidence/notes), obligations, disputes, and
 * risk (assign review/approve action/evaluate/restricted query). NOT
 * covered yet (see docs/MIGRATION_MATRIX.md's Phase 11 section): refunds
 * (blocked on the still-unbuilt vat_return_versions/tax_rule_sets tables
 * -- Phase 9's own deferred VAT-period/return-workflow surface),
 * communications, and notifications' own standalone queue/preference
 * commands (though the shared notificationRecord side-effect these five
 * case/dispute/obligation/risk commands trigger IS ported -- see
 * App\Support\Compliance\NotificationRecorder). Every validate* function
 * throws ComplianceValidationException (a list of {code, path, message})
 * on failure, matching the source's own ComplianceValidationError exactly.
 */
class ComplianceValidator
{
    private const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{1,99}$/';
    private const CURRENCY_PATTERN = '/^[A-Z]{3}$/';
    private const CASE_TYPES = ['DESK_REVIEW', 'VAT_AUDIT', 'REFUND_VERIFICATION', 'INVESTIGATION'];
    private const RISK_TIERS = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];
    private const OBLIGATION_TYPE_PATTERN = '/^[A-Z][A-Z0-9_]{1,49}$/';
    private const PERIOD_CODE_PATTERN = '/^\d{4}-\d{2}$/';
    private const DUE_DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';
    private const DISPUTED_RESOURCE_TYPES = ['AUDIT_FINDING', 'VAT_RETURN', 'REFUND_DECISION', 'OBLIGATION'];
    private const EVIDENCE_SOURCE_TYPES = ['INVOICE', 'VAT_RETURN', 'DOCUMENT', 'OTHER'];
    private const EVIDENCE_CUSTODY_ACTIONS = ['VERIFY', 'SET_LEGAL_HOLD', 'RELEASE_LEGAL_HOLD'];
    private const SHA256_PATTERN = '/^[a-f0-9]{64}$/i';
    private const RISK_INDICATOR_STATUSES = ['OPEN', 'UNDER_REVIEW', 'ESCALATED_TO_CASE', 'DISMISSED'];
    private const RISK_SEVERITIES = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];
    private const MAX_QUERY_LIMIT = 200;
    private const DEFAULT_QUERY_LIMIT = 50;

    private const CASE_ACTIONS = ['AUTHORIZE', 'ASSIGN', 'ADVANCE', 'SUSPEND', 'RESUME', 'CANCEL', 'REOPEN', 'CLOSE', 'LINK_APPEAL'];

    /**
     * Module 4 Phase C's adjacency-list case lifecycle -- `null` marks
     * RESUME as dynamic (its real target is whatever status the case was
     * suspended *from*, only the service layer can resolve that).
     */
    private const CASE_TRANSITIONS = [
        'PROPOSED' => ['AUTHORIZE' => 'AUTHORIZED', 'CANCEL' => 'CANCELLED'],
        'AUTHORIZED' => ['ASSIGN' => 'ASSIGNED', 'CANCEL' => 'CANCELLED'],
        'ASSIGNED' => ['ADVANCE' => 'PLANNING', 'SUSPEND' => 'SUSPENDED'],
        'PLANNING' => ['ADVANCE' => 'EVIDENCE_COLLECTION', 'SUSPEND' => 'SUSPENDED'],
        'EVIDENCE_COLLECTION' => ['ADVANCE' => 'ANALYSIS', 'SUSPEND' => 'SUSPENDED'],
        'ANALYSIS' => ['ADVANCE' => 'TAXPAYER_RESPONSE', 'SUSPEND' => 'SUSPENDED'],
        'TAXPAYER_RESPONSE' => ['ADVANCE' => 'FINDINGS_REVIEW', 'SUSPEND' => 'SUSPENDED'],
        'FINDINGS_REVIEW' => ['ADVANCE' => 'DECISION', 'SUSPEND' => 'SUSPENDED'],
        'DECISION' => ['CLOSE' => 'CLOSED', 'SUSPEND' => 'SUSPENDED'],
        'SUSPENDED' => ['RESUME' => null],
        'CLOSED' => ['REOPEN' => 'FINDINGS_REVIEW', 'LINK_APPEAL' => 'CLOSED'],
        'CANCELLED' => [],
    ];

    /** @return array{schema_version: string, taxpayer_id: string, case_type: string, title: string, opening_reason: string, risk_tier: string} */
    public static function caseOpening(array $input): array
    {
        $messages = [];
        self::schema($input, $messages);
        $taxpayerId = self::id($input['taxpayer_id'] ?? null, '/taxpayer_id', $messages) ?? '';
        $caseType = mb_strtoupper(self::text($input['case_type'] ?? null));
        if (! in_array($caseType, self::CASE_TYPES, true)) {
            $messages[] = ['code' => 'CASE_TYPE_INVALID', 'path' => '/case_type', 'message' => 'Select a supported case type.'];
        }
        $title = self::bounded($input['title'] ?? null, '/title', 'Title', 5, 200, $messages);
        $openingReason = self::bounded($input['opening_reason'] ?? null, '/opening_reason', 'Opening reason', 20, 2000, $messages);
        $riskTier = mb_strtoupper(self::text($input['risk_tier'] ?? null));
        if (! in_array($riskTier, self::RISK_TIERS, true)) {
            $messages[] = ['code' => 'RISK_TIER_INVALID', 'path' => '/risk_tier', 'message' => 'Select a supported risk tier.'];
        }
        if (count($messages) > 0) {
            throw new ComplianceValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'taxpayer_id' => $taxpayerId, 'case_type' => $caseType, 'title' => $title, 'opening_reason' => $openingReason, 'risk_tier' => $riskTier];
    }

    /**
     * Validates the (status, action) pair is legal and returns the static
     * target status, or null if the target is dynamic (RESUME only).
     */
    public static function assertCaseTransition(string $action, string $currentStatus): ?string
    {
        $rule = self::CASE_TRANSITIONS[$currentStatus] ?? null;
        if ($rule === null || ! array_key_exists($action, $rule)) {
            throw new ComplianceValidationException([
                ['code' => 'CASE_TRANSITION_INVALID', 'path' => '/action', 'message' => 'Cannot '.mb_strtolower(str_replace('_', ' ', $action))." a case currently {$currentStatus}."],
            ]);
        }

        return $rule[$action];
    }

    /** @return array{schema_version: string, action: string, reason: string, officerId: ?string, appealReference: ?string, overrideReason: ?string} */
    public static function caseTransition(array $input): array
    {
        $messages = [];
        self::schema($input, $messages);
        $action = mb_strtoupper(self::text($input['action'] ?? null));
        if (! in_array($action, self::CASE_ACTIONS, true)) {
            $messages[] = ['code' => 'ACTION_INVALID', 'path' => '/action', 'message' => 'action must be one of: '.implode(', ', self::CASE_ACTIONS).'.'];
        }
        $reason = self::bounded($input['reason'] ?? null, '/reason', 'Reason', 10, 2000, $messages);
        $officerId = $action === 'ASSIGN' ? self::id($input['officer_id'] ?? null, '/officer_id', $messages) : null;
        $appealReference = $action === 'LINK_APPEAL' ? self::bounded($input['appeal_reference'] ?? null, '/appeal_reference', 'Appeal reference', 3, 100, $messages) : null;
        $overrideReason = self::optionalBounded($input['override_reason'] ?? null, '/override_reason', 'Override reason', 10, 2000, $messages);
        if (count($messages) > 0) {
            throw new ComplianceValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'action' => $action, 'reason' => $reason, 'officerId' => $officerId, 'appealReference' => $appealReference, 'overrideReason' => $overrideReason];
    }

    /** @return array{schema_version: string, finding_code: string, title: string, description: string, legal_reference: ?string, amount_cents: int, currency: string, overrideReason: ?string} */
    public static function findingIssuance(array $input): array
    {
        $messages = [];
        self::schema($input, $messages);
        $findingCode = self::id($input['finding_code'] ?? null, '/finding_code', $messages) ?? '';
        $title = self::bounded($input['title'] ?? null, '/title', 'Title', 5, 200, $messages);
        $description = self::bounded($input['description'] ?? null, '/description', 'Description', 20, 4000, $messages);
        $legalReference = self::text($input['legal_reference'] ?? null) ?: null;
        $amount = self::safeInt($input['amount_cents'] ?? null, '/amount_cents', 'amount_cents', $messages, 0);
        $currency = mb_strtoupper(self::text($input['currency'] ?? null));
        if (! preg_match(self::CURRENCY_PATTERN, $currency)) {
            $messages[] = ['code' => 'CURRENCY_INVALID', 'path' => '/currency', 'message' => 'Currency must be a three-letter ISO 4217 code.'];
        }
        $overrideReason = self::optionalBounded($input['override_reason'] ?? null, '/override_reason', 'Override reason', 10, 2000, $messages);
        if (count($messages) > 0) {
            throw new ComplianceValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'finding_code' => $findingCode, 'title' => $title, 'description' => $description, 'legal_reference' => $legalReference, 'amount_cents' => $amount, 'currency' => $currency, 'overrideReason' => $overrideReason];
    }

    /** @return array{schema_version: string, taxpayer_id: ?string, audit_case_id: ?string, disputed_resource_type: string, disputed_resource_id: string, grounds: string, disputed_amount_cents: int, currency: string} */
    public static function dispute(array $input): array
    {
        $messages = [];
        self::schema($input, $messages);
        $taxpayerId = self::id($input['taxpayer_id'] ?? null, '/taxpayer_id', $messages, true);
        $auditCaseId = self::id($input['audit_case_id'] ?? null, '/audit_case_id', $messages, true);
        $resourceType = mb_strtoupper(self::text($input['disputed_resource_type'] ?? null));
        if (! in_array($resourceType, self::DISPUTED_RESOURCE_TYPES, true)) {
            $messages[] = ['code' => 'RESOURCE_TYPE_INVALID', 'path' => '/disputed_resource_type', 'message' => 'Select a supported disputed resource type.'];
        }
        $resourceId = self::id($input['disputed_resource_id'] ?? null, '/disputed_resource_id', $messages) ?? '';
        $grounds = self::bounded($input['grounds'] ?? null, '/grounds', 'Grounds', 20, 4000, $messages);
        $amount = self::safeInt($input['disputed_amount_cents'] ?? null, '/disputed_amount_cents', 'Disputed amount cents', $messages, 0);
        $currency = mb_strtoupper(self::text($input['currency'] ?? null));
        if (! preg_match(self::CURRENCY_PATTERN, $currency)) {
            $messages[] = ['code' => 'CURRENCY_INVALID', 'path' => '/currency', 'message' => 'Currency must be a three-letter ISO 4217 code.'];
        }
        if (count($messages) > 0) {
            throw new ComplianceValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'taxpayer_id' => $taxpayerId, 'audit_case_id' => $auditCaseId, 'disputed_resource_type' => $resourceType, 'disputed_resource_id' => $resourceId, 'grounds' => $grounds, 'disputed_amount_cents' => $amount, 'currency' => $currency];
    }

    /** @return array{schema_version: string, taxpayer_id: string, obligation_type: string, period_code: string, due_date: string, amount_cents: int, currency: string} */
    public static function obligationCreation(array $input): array
    {
        $messages = [];
        self::schema($input, $messages);
        $taxpayerId = self::id($input['taxpayer_id'] ?? null, '/taxpayer_id', $messages) ?? '';
        $obligationType = mb_strtoupper(self::text($input['obligation_type'] ?? null));
        if (! preg_match(self::OBLIGATION_TYPE_PATTERN, $obligationType)) {
            $messages[] = ['code' => 'OBLIGATION_TYPE_INVALID', 'path' => '/obligation_type', 'message' => 'obligation_type must contain 2 to 50 uppercase letters, numbers or underscores.'];
        }
        $periodCode = self::text($input['period_code'] ?? null);
        if (! preg_match(self::PERIOD_CODE_PATTERN, $periodCode)) {
            $messages[] = ['code' => 'PERIOD_CODE_INVALID', 'path' => '/period_code', 'message' => 'period_code must use YYYY-MM.'];
        }
        $dueDate = self::text($input['due_date'] ?? null);
        if (! preg_match(self::DUE_DATE_PATTERN, $dueDate)) {
            $messages[] = ['code' => 'DUE_DATE_INVALID', 'path' => '/due_date', 'message' => 'due_date must use YYYY-MM-DD.'];
        }
        $amount = self::safeInt($input['amount_cents'] ?? null, '/amount_cents', 'amount_cents', $messages, 0);
        $currency = mb_strtoupper(self::text($input['currency'] ?? null));
        if (! preg_match(self::CURRENCY_PATTERN, $currency)) {
            $messages[] = ['code' => 'CURRENCY_INVALID', 'path' => '/currency', 'message' => 'Currency must be a three-letter ISO 4217 code.'];
        }
        if (count($messages) > 0) {
            throw new ComplianceValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'taxpayer_id' => $taxpayerId, 'obligation_type' => $obligationType, 'period_code' => $periodCode, 'due_date' => $dueDate, 'amount_cents' => $amount, 'currency' => $currency];
    }

    /** @return array{schema_version: string, notes: string} */
    public static function obligationSatisfaction(array $input): array
    {
        $messages = [];
        self::schema($input, $messages);
        $notes = self::bounded($input['notes'] ?? null, '/notes', 'Notes', 10, 2000, $messages);
        if (count($messages) > 0) {
            throw new ComplianceValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'notes' => $notes];
    }

    /** @return array{schema_version: string, officerId: string} */
    public static function riskReviewAssignment(array $input): array
    {
        $messages = [];
        self::schema($input, $messages);
        $officerId = self::id($input['officer_id'] ?? null, '/officer_id', $messages) ?? '';
        if (count($messages) > 0) {
            throw new ComplianceValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'officerId' => $officerId];
    }

    /**
     * A discriminated union on decision: DISMISS only needs a rationale;
     * ESCALATE_TO_CASE additionally needs case_type/case_title -- the
     * resulting case's risk_tier and opening_reason are deliberately NOT
     * taken from this payload (the service derives them from the
     * indicator's own severity and this decision's rationale).
     *
     * @return array{schema_version: string, decision: string, rationale: string, caseType: ?string, caseTitle: ?string}
     */
    public static function riskActionApproval(array $input): array
    {
        $messages = [];
        self::schema($input, $messages);
        $decision = mb_strtoupper(self::text($input['decision'] ?? null));
        if (! in_array($decision, ['ESCALATE_TO_CASE', 'DISMISS'], true)) {
            $messages[] = ['code' => 'DECISION_INVALID', 'path' => '/decision', 'message' => 'decision must be ESCALATE_TO_CASE or DISMISS.'];
        }
        $rationale = self::bounded($input['rationale'] ?? null, '/rationale', 'Rationale', 20, 2000, $messages);
        if ($decision === 'ESCALATE_TO_CASE') {
            $caseType = mb_strtoupper(self::text($input['case_type'] ?? null));
            if (! in_array($caseType, self::CASE_TYPES, true)) {
                $messages[] = ['code' => 'CASE_TYPE_INVALID', 'path' => '/case_type', 'message' => 'Select a supported case type.'];
            }
            $caseTitle = self::bounded($input['case_title'] ?? null, '/case_title', 'Case title', 5, 200, $messages);
            if (count($messages) > 0) {
                throw new ComplianceValidationException($messages);
            }

            return ['schema_version' => '1.0.0', 'decision' => 'ESCALATE_TO_CASE', 'rationale' => $rationale, 'caseType' => $caseType, 'caseTitle' => $caseTitle];
        }
        if (count($messages) > 0) {
            throw new ComplianceValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'decision' => 'DISMISS', 'rationale' => $rationale, 'caseType' => null, 'caseTitle' => null];
    }

    public static function riskEvaluationRequest(array $input): void
    {
        $messages = [];
        self::schema($input, $messages);
        if (count($messages) > 0) {
            throw new ComplianceValidationException($messages);
        }
    }

    /** @return array{taxpayerId: ?string, status: ?string, severity: ?string, limit: int, offset: int} */
    public static function riskIndicatorQuery(array $params): array
    {
        $messages = [];
        $taxpayerId = isset($params['taxpayer_id']) && $params['taxpayer_id'] !== '' ? trim((string) $params['taxpayer_id']) : null;

        $status = isset($params['status']) && $params['status'] !== '' ? mb_strtoupper(trim((string) $params['status'])) : null;
        if ($status && ! in_array($status, self::RISK_INDICATOR_STATUSES, true)) {
            $messages[] = ['code' => 'STATUS_INVALID', 'path' => '/status', 'message' => 'status must be one of: '.implode(', ', self::RISK_INDICATOR_STATUSES).'.'];
        }

        $severity = isset($params['severity']) && $params['severity'] !== '' ? mb_strtoupper(trim((string) $params['severity'])) : null;
        if ($severity && ! in_array($severity, self::RISK_SEVERITIES, true)) {
            $messages[] = ['code' => 'SEVERITY_INVALID', 'path' => '/severity', 'message' => 'severity must be one of: '.implode(', ', self::RISK_SEVERITIES).'.'];
        }

        [$limit, $offset] = self::limitOffset($params, $messages);
        if (count($messages) > 0) {
            throw new ComplianceValidationException($messages);
        }

        return ['taxpayerId' => $taxpayerId, 'status' => $status, 'severity' => $severity, 'limit' => $limit, 'offset' => $offset];
    }

    /**
     * checksum_sha256 is only accepted (and required) for source_resource_type
     * OTHER -- an officer-supplied hash of external material this system has
     * no canonical record for. Every other source type has its hash derived
     * authoritatively by the service layer from the cited record itself.
     *
     * @return array{schema_version: string, sourceResourceType: string, sourceResourceId: string, description: string, checksumSha256: ?string, supersedesEvidenceId: ?string}
     */
    public static function evidenceAddition(array $input): array
    {
        $messages = [];
        self::schema($input, $messages);
        $sourceResourceType = mb_strtoupper(self::text($input['source_resource_type'] ?? null));
        if (! in_array($sourceResourceType, self::EVIDENCE_SOURCE_TYPES, true)) {
            $messages[] = ['code' => 'SOURCE_TYPE_INVALID', 'path' => '/source_resource_type', 'message' => 'source_resource_type must be one of: '.implode(', ', self::EVIDENCE_SOURCE_TYPES).'.'];
        }
        $sourceResourceId = self::id($input['source_resource_id'] ?? null, '/source_resource_id', $messages) ?? '';
        $description = self::bounded($input['description'] ?? null, '/description', 'Description', 10, 2000, $messages);
        $supersedesEvidenceId = self::id($input['supersedes_evidence_id'] ?? null, '/supersedes_evidence_id', $messages, true);
        $checksumSha256 = null;
        if ($sourceResourceType === 'OTHER') {
            $raw = mb_strtolower(self::text($input['checksum_sha256'] ?? null));
            if (! preg_match(self::SHA256_PATTERN, $raw)) {
                $messages[] = ['code' => 'CHECKSUM_INVALID', 'path' => '/checksum_sha256', 'message' => 'checksum_sha256 is required and must be a 64-character hex SHA-256 digest for externally supplied evidence.'];
            } else {
                $checksumSha256 = $raw;
            }
        }
        if (count($messages) > 0) {
            throw new ComplianceValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'sourceResourceType' => $sourceResourceType, 'sourceResourceId' => $sourceResourceId, 'description' => $description, 'checksumSha256' => $checksumSha256, 'supersedesEvidenceId' => $supersedesEvidenceId];
    }

    /** @return array{schema_version: string, action: string, notes: ?string} */
    public static function evidenceCustodyEvent(array $input): array
    {
        $messages = [];
        self::schema($input, $messages);
        $action = mb_strtoupper(self::text($input['action'] ?? null));
        if (! in_array($action, self::EVIDENCE_CUSTODY_ACTIONS, true)) {
            $messages[] = ['code' => 'ACTION_INVALID', 'path' => '/action', 'message' => 'action must be one of: '.implode(', ', self::EVIDENCE_CUSTODY_ACTIONS).'.'];
        }
        $requiresNotes = in_array($action, ['SET_LEGAL_HOLD', 'RELEASE_LEGAL_HOLD'], true);
        $notes = $requiresNotes
            ? self::bounded($input['notes'] ?? null, '/notes', 'Notes', 10, 2000, $messages)
            : (self::text($input['notes'] ?? null) ?: null);
        if (count($messages) > 0) {
            throw new ComplianceValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'action' => $action, 'notes' => $notes];
    }

    /** Append-only case notes: a correction is a new note with supersedes_note_id pointing at the prior one -- the prior note is never edited or deleted. @return array{schema_version: string, body: string, supersedesNoteId: ?string} */
    public static function caseNoteAddition(array $input): array
    {
        $messages = [];
        self::schema($input, $messages);
        $body = self::bounded($input['body'] ?? null, '/body', 'Note body', 5, 4000, $messages);
        $supersedesNoteId = self::id($input['supersedes_note_id'] ?? null, '/supersedes_note_id', $messages, true);
        if (count($messages) > 0) {
            throw new ComplianceValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'body' => $body, 'supersedesNoteId' => $supersedesNoteId];
    }

    // -- shared field helpers, ported from lib/domain/compliance.ts's own private helpers --

    private static function schema(array $input, array &$messages): void
    {
        if (($input['schema_version'] ?? null) !== '1.0.0') {
            $messages[] = ['code' => 'SCHEMA_VERSION_UNSUPPORTED', 'path' => '/schema_version', 'message' => 'schema_version must be 1.0.0.'];
        }
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim(preg_replace('/\s+/', ' ', $value)) : '';
    }

    private static function id(mixed $value, string $path, array &$messages, bool $optional = false): ?string
    {
        $normalized = self::text($value);
        if ($normalized === '' && $optional) {
            return null;
        }
        if (! preg_match(self::ID_PATTERN, $normalized)) {
            $messages[] = ['code' => 'IDENTIFIER_INVALID', 'path' => $path, 'message' => 'Identifier is invalid.'];
        }

        return $normalized;
    }

    private static function bounded(mixed $value, string $path, string $label, int $min, int $max, array &$messages): string
    {
        $normalized = self::text($value);
        $length = mb_strlen($normalized);
        if ($length < $min || $length > $max) {
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

    private static function safeInt(mixed $value, string $path, string $label, array &$messages, int $min): int
    {
        if (! is_int($value) || $value < $min) {
            $messages[] = ['code' => 'AMOUNT_INVALID', 'path' => $path, 'message' => "{$label} must be a non-negative safe integer."];

            return 0;
        }

        return $value;
    }

    /** @return array{0: int, 1: int} */
    private static function limitOffset(array $params, array &$messages): array
    {
        $limit = self::DEFAULT_QUERY_LIMIT;
        if (isset($params['limit']) && $params['limit'] !== '') {
            $parsed = filter_var($params['limit'], FILTER_VALIDATE_INT);
            if ($parsed === false || $parsed < 1 || $parsed > self::MAX_QUERY_LIMIT) {
                $messages[] = ['code' => 'LIMIT_INVALID', 'path' => '/limit', 'message' => 'limit must be an integer between 1 and '.self::MAX_QUERY_LIMIT.'.'];
            } else {
                $limit = $parsed;
            }
        }
        $offset = 0;
        if (isset($params['offset']) && $params['offset'] !== '') {
            $parsed = filter_var($params['offset'], FILTER_VALIDATE_INT);
            if ($parsed === false || $parsed < 0) {
                $messages[] = ['code' => 'OFFSET_INVALID', 'path' => '/offset', 'message' => 'offset must be a non-negative integer.'];
            } else {
                $offset = $parsed;
            }
        }

        return [$limit, $offset];
    }
}
