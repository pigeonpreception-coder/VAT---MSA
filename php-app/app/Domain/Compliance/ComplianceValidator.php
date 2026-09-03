<?php

namespace App\Domain\Compliance;

use App\Exceptions\ComplianceValidationException;

/**
 * Direct port of lib/domain/compliance.ts's normalize/validate functions
 * and its two adjacency-list state machines (audit case lifecycle,
 * refund-claim lifecycle). Covers: audit cases (open/transition/findings/
 * evidence/notes), obligations, disputes, risk (assign review/approve
 * action/evaluate/restricted query), communications/notifications, and
 * refunds (request/transition/dispute -- the workflow the VAT-return-
 * generation prerequisite was built to unblock; see
 * App\Services\Refund\RefundService). Every validate* function throws
 * ComplianceValidationException (a list of {code, path, message}) on
 * failure, matching the source's own ComplianceValidationError exactly.
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
    private const CASE_REFERENCE_TYPES = ['AUDIT_CASE', 'REFUND_CLAIM', 'RECONCILIATION_EXCEPTION'];
    private const COMMUNICATION_CHANNELS = ['PORTAL', 'EMAIL', 'SMS', 'LETTER'];
    private const COMMUNICATION_CLASSIFICATIONS = ['INTERNAL', 'CONFIDENTIAL', 'TAX_CONFIDENTIAL', 'RESTRICTED'];
    private const CONVERSATION_STATUSES = ['OPEN', 'CLOSED'];
    private const NOTIFICATION_CHANNELS = ['IN_APP', 'EMAIL', 'SMS', 'PORTAL'];
    private const NOTIFICATION_SEVERITIES = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];
    private const NOTIFICATION_TYPE_PATTERN = '/^[A-Z][A-Z0-9_]{2,59}$/';
    private const NOTIFICATION_STATUSES = ['UNREAD', 'READ', 'CANCELLED'];
    private const REFUND_CLAIM_ACTIONS = [
        'RECHECK_ELIGIBILITY', 'APPROVE', 'REJECT', 'REQUEST_INFORMATION', 'HOLD', 'RESUME',
        'DISPUTE', 'RESOLVE_DISPUTE_UPHOLD', 'RESOLVE_DISPUTE_OVERTURN', 'CLOSE',
    ];

    /**
     * Ported from lib/domain/compliance.ts's REFUND_CLAIM_TRANSITIONS -- a
     * real adjacency-list state machine, mirroring CASE_TRANSITIONS's own
     * shape exactly, down to the dynamic-target `null` convention for
     * RESUME (its real target is whichever stage the claim was
     * EVIDENCE_REQUESTED/ON_HOLD *from*, resolved by RefundService reading
     * `refund_claims.resume_status`, not by this map). PAYMENT_PENDING and
     * CLOSED are deliberately terminal: Payment itself stays DISABLED
     * PENDING AUTHORITY, so nothing beyond PAYMENT_PENDING is modeled.
     */
    private const REFUND_CLAIM_TRANSITIONS = [
        'BLOCKED_RETURN_NOT_FILED' => ['RECHECK_ELIGIBILITY' => 'RECEIVED'],
        'RECEIVED' => ['APPROVE' => 'RISK_REVIEW', 'REJECT' => 'REJECTED', 'REQUEST_INFORMATION' => 'EVIDENCE_REQUESTED', 'HOLD' => 'ON_HOLD'],
        'RISK_REVIEW' => ['APPROVE' => 'OFFICER_REVIEW', 'REJECT' => 'REJECTED', 'REQUEST_INFORMATION' => 'EVIDENCE_REQUESTED', 'HOLD' => 'ON_HOLD'],
        'OFFICER_REVIEW' => ['APPROVE' => 'PAYMENT_AUTHORISATION', 'REJECT' => 'REJECTED', 'REQUEST_INFORMATION' => 'EVIDENCE_REQUESTED', 'HOLD' => 'ON_HOLD'],
        'PAYMENT_AUTHORISATION' => ['APPROVE' => 'PAYMENT_PENDING', 'REJECT' => 'REJECTED', 'REQUEST_INFORMATION' => 'EVIDENCE_REQUESTED', 'HOLD' => 'ON_HOLD'],
        'EVIDENCE_REQUESTED' => ['RESUME' => null],
        'ON_HOLD' => ['RESUME' => null],
        'REJECTED' => ['DISPUTE' => 'DISPUTED', 'CLOSE' => 'CLOSED'],
        'DISPUTED' => ['RESOLVE_DISPUTE_UPHOLD' => 'CLOSED', 'RESOLVE_DISPUTE_OVERTURN' => 'RISK_REVIEW'],
        'PAYMENT_PENDING' => [],
        'CLOSED' => [],
    ];

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

    /**
     * Deliberately takes no taxpayer_id -- the service derives it from the
     * referenced case/claim/exception's own taxpayer_id column, so a caller
     * can never send a notice to a taxpayer that doesn't match the case it
     * is actually about.
     *
     * @return array{schema_version: string, related_resource_type: string, related_resource_id: string, channel: string, subject: string, content_summary: string, classification: string}
     */
    public static function notice(array $input): array
    {
        $messages = [];
        self::schema($input, $messages);
        $relatedResourceType = mb_strtoupper(self::text($input['related_resource_type'] ?? null));
        if (! in_array($relatedResourceType, self::CASE_REFERENCE_TYPES, true)) {
            $messages[] = ['code' => 'CASE_REFERENCE_TYPE_INVALID', 'path' => '/related_resource_type', 'message' => 'related_resource_type must be one of: '.implode(', ', self::CASE_REFERENCE_TYPES).'.'];
        }
        $relatedResourceId = self::id($input['related_resource_id'] ?? null, '/related_resource_id', $messages) ?? '';
        $channel = mb_strtoupper(self::text($input['channel'] ?? null));
        if (! in_array($channel, self::COMMUNICATION_CHANNELS, true)) {
            $messages[] = ['code' => 'CHANNEL_INVALID', 'path' => '/channel', 'message' => 'Select a supported channel.'];
        }
        $subject = self::bounded($input['subject'] ?? null, '/subject', 'Subject', 5, 200, $messages);
        $contentSummary = self::bounded($input['content_summary'] ?? null, '/content_summary', 'Content summary', 10, 4000, $messages);
        $classification = mb_strtoupper(self::text($input['classification'] ?? null));
        if (! in_array($classification, self::COMMUNICATION_CLASSIFICATIONS, true)) {
            $messages[] = ['code' => 'CLASSIFICATION_INVALID', 'path' => '/classification', 'message' => 'Select a supported classification.'];
        }
        if (count($messages) > 0) {
            throw new ComplianceValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'related_resource_type' => $relatedResourceType, 'related_resource_id' => $relatedResourceId, 'channel' => $channel, 'subject' => $subject, 'content_summary' => $contentSummary, 'classification' => $classification];
    }

    /** Inherits the thread's own subject and classification rather than re-declaring them per message. @return array{schema_version: string, channel: string, content_summary: string} */
    public static function conversationResponse(array $input): array
    {
        $messages = [];
        self::schema($input, $messages);
        $channel = mb_strtoupper(self::text($input['channel'] ?? null));
        if (! in_array($channel, self::COMMUNICATION_CHANNELS, true)) {
            $messages[] = ['code' => 'CHANNEL_INVALID', 'path' => '/channel', 'message' => 'Select a supported channel.'];
        }
        $contentSummary = self::bounded($input['content_summary'] ?? null, '/content_summary', 'Content summary', 10, 4000, $messages);
        if (count($messages) > 0) {
            throw new ComplianceValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'channel' => $channel, 'content_summary' => $contentSummary];
    }

    /** @return array{schema_version: string, reason: string} */
    public static function conversationClosure(array $input): array
    {
        $messages = [];
        self::schema($input, $messages);
        $reason = self::bounded($input['reason'] ?? null, '/reason', 'Closure reason', 10, 2000, $messages);
        if (count($messages) > 0) {
            throw new ComplianceValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'reason' => $reason];
    }

    /** @return array{status: ?string, relatedResourceType: ?string, taxpayerId: ?string, limit: int, offset: int} */
    public static function inboxQuery(array $params): array
    {
        $messages = [];
        $status = isset($params['status']) && $params['status'] !== '' ? mb_strtoupper(trim((string) $params['status'])) : null;
        if ($status && ! in_array($status, self::CONVERSATION_STATUSES, true)) {
            $messages[] = ['code' => 'STATUS_INVALID', 'path' => '/status', 'message' => 'status must be OPEN or CLOSED.'];
        }
        $relatedResourceType = isset($params['related_resource_type']) && $params['related_resource_type'] !== '' ? mb_strtoupper(trim((string) $params['related_resource_type'])) : null;
        if ($relatedResourceType && ! in_array($relatedResourceType, self::CASE_REFERENCE_TYPES, true)) {
            $messages[] = ['code' => 'CASE_REFERENCE_TYPE_INVALID', 'path' => '/related_resource_type', 'message' => 'related_resource_type must be one of: '.implode(', ', self::CASE_REFERENCE_TYPES).'.'];
        }
        $taxpayerId = isset($params['taxpayer_id']) && $params['taxpayer_id'] !== '' ? trim((string) $params['taxpayer_id']) : null;
        [$limit, $offset] = self::limitOffset($params, $messages);
        if (count($messages) > 0) {
            throw new ComplianceValidationException($messages);
        }

        return ['status' => $status, 'relatedResourceType' => $relatedResourceType, 'taxpayerId' => $taxpayerId, 'limit' => $limit, 'offset' => $offset];
    }

    /** At least one of user_id/taxpayer_id is required -- a notification with neither has no one to reach. @return array{schema_version: string, user_id: ?string, taxpayer_id: ?string, notification_type: string, title: string, message: string, severity: string, action_url: ?string, channels: list<string>} */
    public static function notificationQueue(array $input): array
    {
        $messages = [];
        self::schema($input, $messages);
        $userId = self::id($input['user_id'] ?? null, '/user_id', $messages, true);
        $taxpayerId = self::id($input['taxpayer_id'] ?? null, '/taxpayer_id', $messages, true);
        if (! $userId && ! $taxpayerId) {
            $messages[] = ['code' => 'RECIPIENT_REQUIRED', 'path' => '/user_id', 'message' => 'At least one of user_id or taxpayer_id is required.'];
        }
        $notificationType = mb_strtoupper(self::text($input['notification_type'] ?? null));
        if (! preg_match(self::NOTIFICATION_TYPE_PATTERN, $notificationType)) {
            $messages[] = ['code' => 'NOTIFICATION_TYPE_INVALID', 'path' => '/notification_type', 'message' => 'notification_type must be 3 to 60 uppercase letters, digits or underscores, starting with a letter.'];
        }
        $title = self::bounded($input['title'] ?? null, '/title', 'Title', 3, 200, $messages);
        $message = self::bounded($input['message'] ?? null, '/message', 'Message', 3, 2000, $messages);
        $severity = mb_strtoupper(self::text($input['severity'] ?? null));
        if (! in_array($severity, self::NOTIFICATION_SEVERITIES, true)) {
            $messages[] = ['code' => 'SEVERITY_INVALID', 'path' => '/severity', 'message' => 'Select a supported severity.'];
        }
        $actionUrl = self::optionalBounded($input['action_url'] ?? null, '/action_url', 'Action URL', 1, 500, $messages);
        $rawChannels = is_array($input['channels'] ?? null) ? $input['channels'] : [];
        $channels = array_values(array_unique(array_map(fn ($v) => mb_strtoupper(self::text($v)), $rawChannels)));
        if (count($channels) < 1 || count($channels) > count(self::NOTIFICATION_CHANNELS)) {
            $messages[] = ['code' => 'CHANNELS_INVALID', 'path' => '/channels', 'message' => 'channels must contain 1 to '.count(self::NOTIFICATION_CHANNELS).' entries.'];
        }
        foreach ($channels as $channel) {
            if (! in_array($channel, self::NOTIFICATION_CHANNELS, true)) {
                $messages[] = ['code' => 'CHANNEL_INVALID', 'path' => '/channels', 'message' => ($channel ?: 'Empty channel').' is not a supported channel.'];
            }
        }
        if (count($messages) > 0) {
            throw new ComplianceValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'user_id' => $userId, 'taxpayer_id' => $taxpayerId, 'notification_type' => $notificationType, 'title' => $title, 'message' => $message, 'severity' => $severity, 'action_url' => $actionUrl, 'channels' => $channels];
    }

    /** @return array{schema_version: string, reason: string} */
    public static function notificationCancellation(array $input): array
    {
        $messages = [];
        self::schema($input, $messages);
        $reason = self::bounded($input['reason'] ?? null, '/reason', 'Cancellation reason', 5, 500, $messages);
        if (count($messages) > 0) {
            throw new ComplianceValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'reason' => $reason];
    }

    /** Self-service -- every actor manages only their own row. @return array{schema_version: string, channel: string, enabled: bool} */
    public static function notificationPreference(array $input): array
    {
        $messages = [];
        self::schema($input, $messages);
        $channel = mb_strtoupper(self::text($input['channel'] ?? null));
        if (! in_array($channel, self::NOTIFICATION_CHANNELS, true)) {
            $messages[] = ['code' => 'CHANNEL_INVALID', 'path' => '/channel', 'message' => 'Select a supported channel.'];
        }
        if (! is_bool($input['enabled'] ?? null)) {
            $messages[] = ['code' => 'ENABLED_INVALID', 'path' => '/enabled', 'message' => 'enabled must be a boolean.'];
        }
        if (count($messages) > 0) {
            throw new ComplianceValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'channel' => $channel, 'enabled' => (bool) ($input['enabled'] ?? false)];
    }

    /** @return array{status: ?string, severity: ?string, limit: int, offset: int} */
    public static function notificationQuery(array $params): array
    {
        $messages = [];
        $status = isset($params['status']) && $params['status'] !== '' ? mb_strtoupper(trim((string) $params['status'])) : null;
        if ($status && ! in_array($status, self::NOTIFICATION_STATUSES, true)) {
            $messages[] = ['code' => 'STATUS_INVALID', 'path' => '/status', 'message' => 'status must be UNREAD, READ or CANCELLED.'];
        }
        $severity = isset($params['severity']) && $params['severity'] !== '' ? mb_strtoupper(trim((string) $params['severity'])) : null;
        if ($severity && ! in_array($severity, self::NOTIFICATION_SEVERITIES, true)) {
            $messages[] = ['code' => 'SEVERITY_INVALID', 'path' => '/severity', 'message' => 'Select a supported severity.'];
        }
        [$limit, $offset] = self::limitOffset($params, $messages);
        if (count($messages) > 0) {
            throw new ComplianceValidationException($messages);
        }

        return ['status' => $status, 'severity' => $severity, 'limit' => $limit, 'offset' => $offset];
    }

    /** @return array{schema_version: string, vat_return_version_id: string} */
    public static function refundRequest(array $input): array
    {
        $messages = [];
        self::schema($input, $messages);
        $versionId = self::id($input['vat_return_version_id'] ?? null, '/vat_return_version_id', $messages) ?? '';
        if (count($messages) > 0) {
            throw new ComplianceValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'vat_return_version_id' => $versionId];
    }

    /**
     * Validates the (status, action) pair is legal and returns the static
     * target status, or null if the target is dynamic (RESUME only -- see
     * REFUND_CLAIM_TRANSITIONS's own doc comment).
     */
    public static function assertRefundClaimTransition(string $action, string $currentStatus): ?string
    {
        $rule = self::REFUND_CLAIM_TRANSITIONS[$currentStatus] ?? null;
        if ($rule === null || ! array_key_exists($action, $rule)) {
            throw new ComplianceValidationException([
                ['code' => 'REFUND_TRANSITION_INVALID', 'path' => '/action', 'message' => 'Cannot '.mb_strtolower(str_replace('_', ' ', $action))." a refund claim currently {$currentStatus}."],
            ]);
        }

        return $rule[$action];
    }

    /**
     * Read-only view of which actions are legal from a given refund claim
     * status -- lets a UI build a real "only the actions that would
     * actually succeed" control (e.g. a transition dropdown) without
     * duplicating REFUND_CLAIM_TRANSITIONS's own state table, which would
     * risk drifting out of sync with assertRefundClaimTransition's own
     * authoritative enforcement of it.
     *
     * @return list<string>
     */
    public static function refundClaimActionsFor(string $currentStatus): array
    {
        return array_keys(self::REFUND_CLAIM_TRANSITIONS[$currentStatus] ?? []);
    }

    /** @return array{schema_version: string, action: string, findings: string} */
    public static function refundClaimTransition(array $input): array
    {
        $messages = [];
        self::schema($input, $messages);
        $action = mb_strtoupper(self::text($input['action'] ?? null));
        if (! in_array($action, self::REFUND_CLAIM_ACTIONS, true)) {
            $messages[] = ['code' => 'REFUND_ACTION_INVALID', 'path' => '/action', 'message' => 'Select a supported refund action.'];
        }
        $findings = self::bounded($input['findings'] ?? null, '/findings', 'Findings', 5, 2000, $messages);
        if (count($messages) > 0) {
            throw new ComplianceValidationException($messages);
        }

        return ['schema_version' => '1.0.0', 'action' => $action, 'findings' => $findings];
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
