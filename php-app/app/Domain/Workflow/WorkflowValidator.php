<?php

namespace App\Domain\Workflow;

use App\Exceptions\LicensingValidationException;

/**
 * Direct port of lib/domain/control-plane.ts's normalizeWorkflowDefinition/
 * normalizeWorkflowAssignment/normalizeWorkflowTestContext/
 * normalizeDelegation/assertWorkflowDecision -- Phase 12's workflow-engine
 * slice (Module 8 Phase C). Reuses `App\Exceptions\LicensingValidationException`
 * rather than a new exception class, exactly matching the source's single
 * `ControlPlaneValidationError` shared across this whole file.
 */
class WorkflowValidator
{
    /**
     * REFUND is registered here (matching the source) so a future phase
     * can build a refund-approval workflow against this engine --
     * Refund's own existing maker-checker (RefundService::reviewRefund)
     * is deliberately NOT migrated onto it in this slice. Cross-module
     * surgery on already-shipped, tested code is out of scope for a
     * slice that stays inside this one file's own functions.
     */
    private const DOMAIN_ACTIONS = ['PURCHASE_REQUEST', 'EXPENSE', 'JOURNAL', 'VAT_RETURN', 'ROLE_CHANGE', 'PRIMARY_ADMIN_CHANGE', 'API_CREDENTIAL', 'REFUND'];

    private const CONTEXT_FIELDS = ['amount_cents', 'branch_id', 'department_id'];

    private const ISO_TIMESTAMP_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{3})?Z$/';

    /** @return array{name: string, domainAction: string, nodes: list<array{id: string, type: string, assigneeType: ?string, assigneeRef: ?string, label: string}>, transitions: list<array{from: string, to: string, condition: ?array{field: string, operator: string, value: mixed}}>} */
    public static function definition(mixed $input): array
    {
        if (! is_array($input) || array_is_list($input)) {
            throw new LicensingValidationException('PAYLOAD_INVALID', 'A workflow definition is required.');
        }
        $domainAction = mb_strtoupper(trim((string) ($input['domain_action'] ?? '')));
        if (! in_array($domainAction, self::DOMAIN_ACTIONS, true)) {
            throw new LicensingValidationException('WORKFLOW_DOMAIN_UNSUPPORTED', 'The workflow domain action is not configurable.');
        }
        $rawNodes = $input['nodes'] ?? null;
        if (! is_array($rawNodes) || ! array_is_list($rawNodes) || count($rawNodes) < 2 || count($rawNodes) > 30) {
            throw new LicensingValidationException('WORKFLOW_NODES_INVALID', 'A workflow needs 2 to 30 typed nodes.');
        }
        $nodes = [];
        foreach ($rawNodes as $index => $value) {
            if (! is_array($value) || array_is_list($value)) {
                throw new LicensingValidationException('WORKFLOW_NODE_INVALID', 'Node '.($index + 1).' is invalid.');
            }
            $id = trim((string) ($value['id'] ?? ''));
            $type = mb_strtoupper(trim((string) ($value['type'] ?? '')));
            if (! preg_match('/^[a-z][a-z0-9-]{0,39}$/i', $id) || ! in_array($type, ['START', 'APPROVAL', 'END'], true)) {
                throw new LicensingValidationException('WORKFLOW_NODE_INVALID', 'Node '.($index + 1).' is invalid.');
            }
            $assigneeType = ! empty($value['assignee_type']) ? mb_strtoupper((string) $value['assignee_type']) : null;
            $assigneeRef = is_string($value['assignee_ref'] ?? null) ? trim($value['assignee_ref']) : null;
            if ($type === 'APPROVAL' && (! $assigneeType || ! in_array($assigneeType, ['ROLE', 'USER', 'MANAGER'], true))) {
                throw new LicensingValidationException('WORKFLOW_ASSIGNEE_REQUIRED', "Approval node {$id} needs a typed assignee.");
            }
            $nodes[] = ['id' => $id, 'type' => $type, 'assigneeType' => $assigneeType, 'assigneeRef' => $assigneeRef, 'label' => self::cleanLabel($value['label'] ?? $id, "Node {$id} label", 80)];
        }
        $nodeIds = array_column($nodes, 'id');
        if (count(array_unique($nodeIds)) !== count($nodes)) {
            throw new LicensingValidationException('WORKFLOW_NODE_DUPLICATE', 'Workflow node IDs must be unique.');
        }
        $startCount = count(array_filter($nodes, fn ($n) => $n['type'] === 'START'));
        $endCount = count(array_filter($nodes, fn ($n) => $n['type'] === 'END'));
        if ($startCount !== 1 || $endCount !== 1) {
            throw new LicensingValidationException('WORKFLOW_TERMINALS_INVALID', 'A workflow requires exactly one START and one END node.');
        }
        $rawTransitions = $input['transitions'] ?? null;
        if (! is_array($rawTransitions) || ! array_is_list($rawTransitions) || count($rawTransitions) === 0) {
            throw new LicensingValidationException('WORKFLOW_TRANSITIONS_REQUIRED', 'Workflow transitions are required.');
        }
        $nodeIdSet = array_flip($nodeIds);
        $transitions = [];
        foreach ($rawTransitions as $value) {
            if (! is_array($value) || array_is_list($value)) {
                throw new LicensingValidationException('WORKFLOW_TRANSITION_INVALID', 'A workflow transition is invalid.');
            }
            $from = trim((string) ($value['from'] ?? ''));
            $to = trim((string) ($value['to'] ?? ''));
            if (! isset($nodeIdSet[$from]) || ! isset($nodeIdSet[$to]) || $from === $to) {
                throw new LicensingValidationException('WORKFLOW_TRANSITION_INVALID', "{$from} to {$to} is not a valid transition.");
            }
            $condition = null;
            if (array_key_exists('condition', $value) && $value['condition'] !== null) {
                if (! is_array($value['condition']) || array_is_list($value['condition'])) {
                    throw new LicensingValidationException('WORKFLOW_CONDITION_INVALID', 'Workflow conditions must use the typed condition vocabulary.');
                }
                $raw = $value['condition'];
                $field = (string) ($raw['field'] ?? '');
                $operator = mb_strtoupper((string) ($raw['operator'] ?? ''));
                if (! in_array($field, self::CONTEXT_FIELDS, true) || ! in_array($operator, ['LTE', 'GT', 'EQ'], true)) {
                    throw new LicensingValidationException('WORKFLOW_CONDITION_INVALID', 'Workflow conditions must use approved fields and operators.');
                }
                $condition = ['field' => $field, 'operator' => $operator, 'value' => is_int($raw['value'] ?? null) || is_float($raw['value'] ?? null) ? $raw['value'] : (string) ($raw['value'] ?? '')];
            }
            $transitions[] = ['from' => $from, 'to' => $to, 'condition' => $condition];
        }

        return ['name' => self::cleanLabel($input['name'] ?? null, 'Workflow name', 100), 'domainAction' => $domainAction, 'nodes' => $nodes, 'transitions' => $transitions];
    }

    public static function assertDecision(string $actorId, string $initiatedBy, ?string $assignedUserId, string $decision, bool $emergencyOverride): void
    {
        if ($emergencyOverride) {
            throw new LicensingValidationException('EMERGENCY_OVERRIDE_DISABLED', 'Emergency segregation-of-duties override is disabled.');
        }
        if ($actorId === $initiatedBy) {
            throw new LicensingValidationException('SELF_APPROVAL_DENIED', 'The initiator cannot approve or reject their own protected transaction.');
        }
        if ($assignedUserId && $assignedUserId !== $actorId) {
            throw new LicensingValidationException('TASK_NOT_ASSIGNED', 'The workflow task is assigned to another user.');
        }
        if (! in_array(mb_strtoupper($decision), ['APPROVE', 'REJECT'], true)) {
            throw new LicensingValidationException('DECISION_INVALID', 'The workflow decision must be APPROVE or REJECT.');
        }
    }

    /** @return array{domainAction: string, resourceType: string, resourceId: string, context: array<string, int|float|string>} */
    public static function assignment(mixed $input): array
    {
        if (! is_array($input) || array_is_list($input)) {
            throw new LicensingValidationException('PAYLOAD_INVALID', 'A workflow assignment object is required.');
        }
        $domainAction = mb_strtoupper(trim((string) ($input['domain_action'] ?? '')));
        if (! in_array($domainAction, self::DOMAIN_ACTIONS, true)) {
            throw new LicensingValidationException('WORKFLOW_DOMAIN_UNSUPPORTED', 'The workflow domain action is not configurable.');
        }
        $resourceType = mb_strtoupper(trim((string) ($input['resource_type'] ?? '')));
        if (! preg_match('/^[A-Z][A-Z0-9_]{1,39}$/', $resourceType)) {
            throw new LicensingValidationException('RESOURCE_TYPE_INVALID', 'resource_type must contain 2 to 40 uppercase letters, numbers or underscores.');
        }
        $resourceId = trim((string) ($input['resource_id'] ?? ''));
        if ($resourceId === '' || mb_strlen($resourceId) > 100) {
            throw new LicensingValidationException('RESOURCE_ID_INVALID', 'resource_id is required.');
        }

        return ['domainAction' => $domainAction, 'resourceType' => $resourceType, 'resourceId' => $resourceId, 'context' => self::context($input['context'] ?? null)];
    }

    /** @return array<string, int|float|string> */
    public static function testContext(mixed $input): array
    {
        if ($input === null) {
            return [];
        }
        if (! is_array($input) || array_is_list($input)) {
            throw new LicensingValidationException('PAYLOAD_INVALID', 'A test request object is required.');
        }

        return self::context($input['context'] ?? null);
    }

    /** @return array<string, int|float|string> */
    private static function context(mixed $contextRaw): array
    {
        if ($contextRaw === null) {
            return [];
        }
        if (! is_array($contextRaw) || array_is_list($contextRaw)) {
            throw new LicensingValidationException('CONTEXT_INVALID', 'context must be an object.');
        }
        $context = [];
        foreach ($contextRaw as $key => $value) {
            if (! in_array($key, self::CONTEXT_FIELDS, true)) {
                throw new LicensingValidationException('CONTEXT_FIELD_UNSUPPORTED', "context.{$key} is not a supported routing field.");
            }
            if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
                throw new LicensingValidationException('CONTEXT_VALUE_INVALID', "context.{$key} must be a number or string.");
            }
            $context[$key] = $value;
        }

        return $context;
    }

    /** @return array{delegatorUserId: string, delegateUserId: string, workflowId: ?string, scope: string, effectiveFrom: string, effectiveTo: string, reason: string} */
    public static function delegation(mixed $input): array
    {
        if (! is_array($input) || array_is_list($input)) {
            throw new LicensingValidationException('PAYLOAD_INVALID', 'A delegation object is required.');
        }
        $delegatorUserId = trim((string) ($input['delegator_user_id'] ?? ''));
        if ($delegatorUserId === '') {
            throw new LicensingValidationException('DELEGATOR_REQUIRED', 'delegator_user_id is required.');
        }
        $delegateUserId = trim((string) ($input['delegate_user_id'] ?? ''));
        if ($delegateUserId === '') {
            throw new LicensingValidationException('DELEGATE_REQUIRED', 'delegate_user_id is required.');
        }
        if ($delegatorUserId === $delegateUserId) {
            throw new LicensingValidationException('DELEGATION_SELF', 'A user cannot delegate to themselves.');
        }
        $workflowId = is_string($input['workflow_id'] ?? null) && trim($input['workflow_id']) !== '' ? trim($input['workflow_id']) : null;
        $scope = $workflowId ? 'WORKFLOW' : 'ALL';
        $effectiveFrom = trim((string) ($input['effective_from'] ?? ''));
        $effectiveTo = trim((string) ($input['effective_to'] ?? ''));
        if (! preg_match(self::ISO_TIMESTAMP_PATTERN, $effectiveFrom) || ! preg_match(self::ISO_TIMESTAMP_PATTERN, $effectiveTo)) {
            throw new LicensingValidationException('EFFECTIVE_RANGE_INVALID', 'effective_from/effective_to must be ISO UTC timestamps.');
        }
        if (strtotime($effectiveTo) <= strtotime($effectiveFrom)) {
            throw new LicensingValidationException('EFFECTIVE_RANGE_INVALID', 'effective_to must be after effective_from.');
        }
        $reason = trim((string) preg_replace('/\s+/', ' ', (string) ($input['reason'] ?? '')));
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 240) {
            throw new LicensingValidationException('REASON_REQUIRED', 'Provide a 5 to 240 character delegation reason.');
        }

        return ['delegatorUserId' => $delegatorUserId, 'delegateUserId' => $delegateUserId, 'workflowId' => $workflowId, 'scope' => $scope, 'effectiveFrom' => $effectiveFrom, 'effectiveTo' => $effectiveTo, 'reason' => $reason];
    }

    private static function cleanLabel(mixed $value, string $field, int $max = 100): string
    {
        if (! is_string($value)) {
            throw new LicensingValidationException('FIELD_REQUIRED', "{$field} is required.");
        }
        $result = trim((string) preg_replace('/\s+/', ' ', $value));
        if (mb_strlen($result) < 2 || mb_strlen($result) > $max) {
            throw new LicensingValidationException('FIELD_INVALID', "{$field} must contain 2 to {$max} characters.");
        }
        if (preg_match('/[<>]/', $result) || preg_match('/[\x00-\x1F]/', $result)) {
            throw new LicensingValidationException('FIELD_INVALID', "{$field} contains unsupported characters.");
        }

        return $result;
    }
}
