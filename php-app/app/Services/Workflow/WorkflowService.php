<?php

namespace App\Services\Workflow;

use App\Domain\Workflow\WorkflowValidator;
use App\Exceptions\LicensingValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Models\Organisation;
use App\Models\OrganisationRole;
use App\Models\OutboxEvent;
use App\Models\SodRule;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Services\Audit\AuditService;
use App\Support\Access\DynamicPermissions;
use App\Support\Licensing\EntitlementGate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/control-plane-repository.ts's createWorkflowDraft/
 * publishWorkflowVersion/assignWorkflow/decideWorkflowTask/
 * testWorkflowVersion/createDelegation/listDelegations/revokeDelegation
 * -- Phase 12's workflow-engine slice (Module 8 Phase C), the last of
 * `control-plane-repository.ts`'s sub-domains besides the
 * `getAdministrationSnapshot` dashboard aggregate. `resolveNextNode`/
 * `redirectThroughDelegation`/`resolveAssignee`/the outbox-event helper
 * are the source's own shared internal helpers, private methods here too.
 */
class WorkflowService
{
    /** @return array<string, mixed> */
    public function createWorkflowDraft(array $payload, User $actor, ?string $requestedOrganisationId): array
    {
        $definition = WorkflowValidator::definition($payload);
        ['organisation' => $organisation, 'license' => $license] = EntitlementGate::assert($actor, 'ADVANCED_WORKFLOW', 'ADMIN_WRITE', 1, $requestedOrganisationId);

        $duplicate = Workflow::where('organisation_id', $organisation->id)->where('name', $definition['name'])->exists();
        if ($duplicate) {
            throw new RepositoryConflictException('A workflow with this name already exists.');
        }

        $workflowId = (string) Str::uuid();
        $versionId = (string) Str::uuid();
        $now = now();
        $canonical = AuditService::canonicalJson($definition);
        $hash = hash('sha256', $canonical);

        DB::transaction(function () use ($workflowId, $versionId, $organisation, $license, $actor, $definition, $canonical, $hash, $now) {
            Workflow::create([
                'id' => $workflowId, 'organisation_id' => $organisation->id, 'name' => $definition['name'],
                'domain_action' => $definition['domainAction'], 'status' => 'DRAFT', 'created_by' => $actor->id,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            WorkflowVersion::create([
                'id' => $versionId, 'workflow_id' => $workflowId, 'organisation_id' => $organisation->id, 'version_number' => 1,
                'status' => 'DRAFT', 'definition_hash' => $hash, 'definition' => $canonical, 'effective_from' => null,
                'published_by' => null, 'approved_by' => null, 'published_at' => null, 'retired_at' => null, 'created_at' => $now,
            ]);
            foreach ($definition['nodes'] as $index => $node) {
                DB::table('workflow_nodes')->insert([
                    'id' => (string) Str::uuid(), 'workflow_version_id' => $versionId, 'node_key' => $node['id'], 'node_type' => $node['type'],
                    'label' => $node['label'], 'assignee_type' => $node['assigneeType'], 'assignee_reference' => $node['assigneeRef'], 'sequence' => $index + 1,
                ]);
            }
            foreach ($definition['transitions'] as $index => $transition) {
                $transitionId = (string) Str::uuid();
                DB::table('workflow_transitions')->insert([
                    'id' => $transitionId, 'workflow_version_id' => $versionId, 'from_node_key' => $transition['from'],
                    'to_node_key' => $transition['to'], 'sequence' => $index + 1,
                ]);
                if ($transition['condition']) {
                    DB::table('workflow_conditions')->insert([
                        'id' => (string) Str::uuid(), 'workflow_transition_id' => $transitionId, 'field' => $transition['condition']['field'],
                        'operator' => $transition['condition']['operator'], 'comparison_value' => (string) $transition['condition']['value'],
                    ]);
                }
            }
            DB::table('license_usage')->where('organisation_license_id', $license['id'])->where('metric_key', 'WORKFLOWS')
                ->update(['reserved_value' => DB::raw('reserved_value + 1'), 'version' => DB::raw('version + 1'), 'updated_at' => $now]);
            AuditService::append($actor, 'WORKFLOW_DRAFT_CREATED', 'WORKFLOW_VERSION', $versionId, ['organisationId' => $organisation->id, 'workflowId' => $workflowId, 'domainAction' => $definition['domainAction']], $now);
        });

        return ['workflowId' => $workflowId, 'versionId' => $versionId, 'version' => 1, 'status' => 'DRAFT', 'definition' => $definition];
    }

    /** @return array<string, mixed> */
    public function publishWorkflowVersion(string $versionId, User $actor, ?string $requestedOrganisationId): array
    {
        ['organisation' => $organisation, 'license' => $license] = EntitlementGate::assert($actor, 'ADVANCED_WORKFLOW', 'ADMIN_WRITE', 0, $requestedOrganisationId);

        $version = DB::table('workflow_versions as v')->join('workflows as w', 'w.id', '=', 'v.workflow_id')
            ->where('v.id', $versionId)->where('v.organisation_id', $organisation->id)
            ->select('v.id', 'v.status', 'v.workflow_id', 'w.created_by')->first();
        if (! $version) {
            throw new LicensingValidationException('WORKFLOW_VERSION_NOT_FOUND', 'The workflow version is outside the active organisation scope.');
        }
        if ($version->status !== 'DRAFT') {
            throw new RepositoryConflictException('Only a draft workflow version can be published.');
        }
        WorkflowValidator::assertDecision($actor->id, $version->created_by, null, 'APPROVE', false);

        $now = now();
        DB::transaction(function () use ($version, $organisation, $license, $actor, $now) {
            DB::table('workflow_versions')->where('id', $version->id)->where('status', 'DRAFT')
                ->update(['status' => 'PUBLISHED', 'effective_from' => $now, 'published_by' => $actor->id, 'approved_by' => $actor->id, 'published_at' => $now]);
            DB::table('workflows')->where('id', $version->workflow_id)->update(['status' => 'ACTIVE', 'updated_at' => $now]);
            DB::table('license_usage')->where('organisation_license_id', $license['id'])->where('metric_key', 'WORKFLOWS')
                ->update(['used_value' => DB::raw('used_value + 1'), 'reserved_value' => DB::raw('GREATEST(0, reserved_value - 1)'), 'version' => DB::raw('version + 1'), 'updated_at' => $now]);
            AuditService::append($actor, 'WORKFLOW_VERSION_PUBLISHED', 'WORKFLOW_VERSION', $version->id, ['organisationId' => $organisation->id, 'creator' => $version->created_by, 'approver' => $actor->id], $now);
        });

        return ['id' => $version->id, 'status' => 'PUBLISHED', 'approvedBy' => $actor->id, 'publishedAt' => $now->toISOString()];
    }

    /**
     * Shared transition-graph traversal -- the one place a next node is
     * ever resolved, used identically by Assign (advancing off START),
     * Decide (advancing off the just-decided APPROVAL node) and Test (a
     * full dry-run path). Picks the first transition (by `sequence`)
     * whose condition, if any, matches the supplied context; a
     * transition with no condition always matches.
     *
     * @return array{nodeKey: string, nodeType: string, label: string, assigneeType: ?string, assigneeReference: ?string}|null
     */
    private function resolveNextNode(string $versionId, string $fromNodeKey, array $context): ?array
    {
        $transitions = DB::table('workflow_transitions')->where('workflow_version_id', $versionId)->where('from_node_key', $fromNodeKey)
            ->orderBy('sequence')->get(['id', 'to_node_key']);
        foreach ($transitions as $transition) {
            $conditions = DB::table('workflow_conditions')->where('workflow_transition_id', $transition->id)->get(['field', 'operator', 'comparison_value']);
            $allMatch = true;
            foreach ($conditions as $condition) {
                if (! $this->evaluateCondition($condition, $context)) {
                    $allMatch = false;
                    break;
                }
            }
            if (! $allMatch) {
                continue;
            }
            $node = DB::table('workflow_nodes')->where('workflow_version_id', $versionId)->where('node_key', $transition->to_node_key)
                ->first(['node_key', 'node_type', 'label', 'assignee_type', 'assignee_reference']);
            if (! $node) {
                continue;
            }

            return ['nodeKey' => $node->node_key, 'nodeType' => $node->node_type, 'label' => $node->label, 'assigneeType' => $node->assignee_type, 'assigneeReference' => $node->assignee_reference];
        }

        return null;
    }

    /** Shared by Assign/Decide's path resolution and Test's dry run -- the one place a transition condition is actually evaluated. */
    private function evaluateCondition(object $condition, array $context): bool
    {
        $raw = $context[$condition->field] ?? null;
        $numeric = is_numeric($raw) ? (float) $raw : null;
        $comparison = is_numeric($condition->comparison_value) ? (float) $condition->comparison_value : null;
        if ($numeric !== null && $comparison !== null) {
            return match ($condition->operator) {
                'LTE' => $numeric <= $comparison,
                'GT' => $numeric > $comparison,
                'EQ' => $numeric === $comparison,
                default => false,
            };
        }
        if ($condition->operator === 'EQ') {
            return (string) ($raw ?? '') === $condition->comparison_value;
        }

        return false;
    }

    /**
     * Redirects a resolved assignee through an ACTIVE delegation, if one
     * covers this workflow (or ALL workflows) and is currently in its
     * effective window. A workflow-specific delegation takes precedence
     * over a general ALL delegation when both exist.
     */
    private function redirectThroughDelegation(string $organisationId, string $userId, string $workflowId): string
    {
        $now = now();
        $delegateUserId = DB::table('workflow_delegations')
            ->where('organisation_id', $organisationId)->where('delegator_user_id', $userId)->where('status', 'ACTIVE')
            ->where('effective_from', '<=', $now)->where('effective_to', '>=', $now)
            ->where(fn ($q) => $q->whereNull('workflow_id')->orWhere('workflow_id', $workflowId))
            ->orderByRaw('workflow_id IS NULL')
            ->limit(1)->value('delegate_user_id');

        return $delegateUserId ?? $userId;
    }

    /**
     * Resolves a workflow node's ROLE/USER/MANAGER assignee into a
     * concrete user or role to assign the next task to.
     *
     * @return array{assignedUserId: ?string, assignedRoleId: ?string}
     */
    private function resolveAssignee(Organisation $organisation, string $initiatedBy, string $workflowId, ?string $assigneeType, ?string $assigneeReference): array
    {
        if ($assigneeType === 'USER') {
            if (! $assigneeReference) {
                throw new LicensingValidationException('ASSIGNEE_INVALID', 'The workflow node has no assigned user reference.');
            }
            $exists = User::where('id', $assigneeReference)->where('status', 'ACTIVE')->exists();
            if (! $exists) {
                throw new LicensingValidationException('ASSIGNEE_NOT_FOUND', "The workflow node's assigned user could not be found.");
            }

            return ['assignedUserId' => $this->redirectThroughDelegation($organisation->id, $assigneeReference, $workflowId), 'assignedRoleId' => null];
        }
        if ($assigneeType === 'ROLE') {
            if (! $assigneeReference) {
                throw new LicensingValidationException('ASSIGNEE_INVALID', 'The workflow node has no assigned role reference.');
            }
            $role = OrganisationRole::where('id', $assigneeReference)->where('organisation_id', $organisation->id)->where('status', 'ACTIVE')->first();
            if (! $role) {
                throw new LicensingValidationException('ASSIGNEE_NOT_FOUND', "The workflow node's assigned role could not be found.");
            }

            return ['assignedUserId' => null, 'assignedRoleId' => $role->id];
        }
        $employee = DB::table('employees')->where('user_id', $initiatedBy)->where('organisation_id', $organisation->id)->first(['manager_employee_id']);
        if (! $employee || ! $employee->manager_employee_id) {
            throw new RepositoryConflictException('The initiator has no manager on record to approve this workflow.');
        }
        $manager = DB::table('employees')->where('id', $employee->manager_employee_id)->where('organisation_id', $organisation->id)->first(['user_id']);
        if (! $manager || ! $manager->user_id) {
            throw new RepositoryConflictException("The initiator's manager has no linked user account.");
        }

        return ['assignedUserId' => $this->redirectThroughDelegation($organisation->id, $manager->user_id, $workflowId), 'assignedRoleId' => null];
    }

    private function outboxInsert(string $aggregateId, string $eventType, string $partitionKey, array $payload, $now): void
    {
        OutboxEvent::create([
            'id' => (string) Str::uuid(), 'aggregate_type' => 'WORKFLOW_INSTANCE', 'aggregate_id' => $aggregateId,
            'event_type' => $eventType, 'event_version' => 1, 'partition_key' => $partitionKey,
            'payload' => AuditService::canonicalJson($payload), 'status' => 'PENDING', 'publish_attempts' => 0,
            'occurred_at' => $now, 'available_at' => $now,
        ]);
    }

    /**
     * The previously entirely-missing command that makes the
     * Create/Publish/Decide pipeline reachable at all. Looks up the
     * organisation's current ACTIVE workflow for `domainAction` and its
     * latest PUBLISHED version, then advances from START using the same
     * transition resolution Decide and Test share. A path that reaches
     * END immediately (a workflow with no approval nodes at all -- valid
     * per `WorkflowValidator::definition()`, which requires only one
     * START and one END) completes the instance immediately with no
     * assignment created.
     *
     * @return array<string, mixed>
     */
    public function assignWorkflow(array $payload, User $actor, ?string $requestedOrganisationId): array
    {
        $assignment = WorkflowValidator::assignment($payload);
        ['organisation' => $organisation] = EntitlementGate::assert($actor, 'ADVANCED_WORKFLOW', 'BUSINESS_WRITE', 0, $requestedOrganisationId);

        $workflow = Workflow::where('organisation_id', $organisation->id)->where('domain_action', $assignment['domainAction'])->where('status', 'ACTIVE')->first();
        if (! $workflow) {
            throw new LicensingValidationException('WORKFLOW_NOT_CONFIGURED', "No active workflow is configured for {$assignment['domainAction']} in this organisation.");
        }
        $version = WorkflowVersion::where('workflow_id', $workflow->id)->where('status', 'PUBLISHED')->orderByDesc('version_number')->first();
        if (! $version) {
            throw new LicensingValidationException('WORKFLOW_NOT_CONFIGURED', "The {$assignment['domainAction']} workflow has no published version.");
        }
        $startNode = DB::table('workflow_nodes')->where('workflow_version_id', $version->id)->where('node_type', 'START')->first(['node_key']);
        if (! $startNode) {
            throw new LicensingValidationException('WORKFLOW_MALFORMED', 'The published workflow version has no start node.');
        }
        $next = $this->resolveNextNode($version->id, $startNode->node_key, $assignment['context']);
        if (! $next) {
            throw new LicensingValidationException('WORKFLOW_NO_MATCHING_PATH', 'No workflow transition matches the supplied context.');
        }

        $instanceId = (string) Str::uuid();
        $now = now();

        if ($next['nodeType'] === 'END') {
            DB::transaction(function () use ($instanceId, $organisation, $version, $assignment, $actor, $next, $now) {
                DB::table('workflow_instances')->insert([
                    'id' => $instanceId, 'organisation_id' => $organisation->id, 'workflow_version_id' => $version->id,
                    'resource_type' => $assignment['resourceType'], 'resource_id' => $assignment['resourceId'], 'initiated_by' => $actor->id,
                    'status' => 'COMPLETED', 'current_node_key' => $next['nodeKey'], 'context_snapshot' => json_encode($assignment['context']),
                    'started_at' => $now, 'completed_at' => $now,
                ]);
                $this->outboxInsert($instanceId, 'WorkflowInstanceCompleted', $organisation->id, ['instance_id' => $instanceId, 'domain_action' => $assignment['domainAction'], 'resource_type' => $assignment['resourceType'], 'resource_id' => $assignment['resourceId']], $now);
                AuditService::append($actor, 'WORKFLOW_INSTANCE_COMPLETED', 'WORKFLOW_INSTANCE', $instanceId, ['organisationId' => $organisation->id, 'domainAction' => $assignment['domainAction']], $now);
            });

            return ['id' => $instanceId, 'status' => 'COMPLETED', 'currentNode' => $next['nodeKey'], 'assignmentId' => null];
        }

        $assignee = $this->resolveAssignee($organisation, $actor->id, $workflow->id, $next['assigneeType'], $next['assigneeReference']);
        $assignmentId = (string) Str::uuid();
        DB::transaction(function () use ($instanceId, $assignmentId, $organisation, $version, $assignment, $actor, $next, $assignee, $now) {
            DB::table('workflow_instances')->insert([
                'id' => $instanceId, 'organisation_id' => $organisation->id, 'workflow_version_id' => $version->id,
                'resource_type' => $assignment['resourceType'], 'resource_id' => $assignment['resourceId'], 'initiated_by' => $actor->id,
                'status' => 'IN_PROGRESS', 'current_node_key' => $next['nodeKey'], 'context_snapshot' => json_encode($assignment['context']),
                'started_at' => $now, 'completed_at' => null,
            ]);
            DB::table('workflow_assignments')->insert([
                'id' => $assignmentId, 'workflow_instance_id' => $instanceId, 'node_key' => $next['nodeKey'],
                'assigned_user_id' => $assignee['assignedUserId'], 'assigned_role_id' => $assignee['assignedRoleId'],
                'status' => 'PENDING', 'due_at' => null, 'assigned_at' => $now,
            ]);
            $this->outboxInsert($instanceId, 'WorkflowInstanceAssigned', $organisation->id, ['instance_id' => $instanceId, 'assignment_id' => $assignmentId, 'domain_action' => $assignment['domainAction'], 'resource_type' => $assignment['resourceType'], 'resource_id' => $assignment['resourceId'], 'node_key' => $next['nodeKey']], $now);
            AuditService::append($actor, 'WORKFLOW_INSTANCE_ASSIGNED', 'WORKFLOW_INSTANCE', $instanceId, ['organisationId' => $organisation->id, 'domainAction' => $assignment['domainAction'], 'nodeKey' => $next['nodeKey']], $now);
        });

        return ['id' => $instanceId, 'status' => 'IN_PROGRESS', 'currentNode' => $next['nodeKey'], 'assignmentId' => $assignmentId];
    }

    /**
     * Traverses the transition graph on APPROVE instead of always
     * completing the instance on the first decision -- a multi-APPROVAL-
     * node workflow needs every node past the first to still be reached.
     * REJECT still terminates the whole instance immediately. Checks
     * role-based assignment (`assigned_role_id`) as well as
     * `assigned_user_id`, since Assign can create either.
     *
     * @return array<string, mixed>
     */
    public function decideWorkflowTask(string $assignmentId, array $payload, User $actor, ?string $requestedOrganisationId): array
    {
        ['organisation' => $organisation] = EntitlementGate::assert($actor, 'ADVANCED_WORKFLOW', 'BUSINESS_WRITE', 0, $requestedOrganisationId);

        $decision = mb_strtoupper(trim((string) ($payload['decision'] ?? '')));
        $reason = trim((string) ($payload['reason'] ?? ''));
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 240) {
            throw new LicensingValidationException('REASON_REQUIRED', 'Provide a 5 to 240 character decision reason.');
        }

        $task = DB::table('workflow_assignments as a')->join('workflow_instances as i', 'i.id', '=', 'a.workflow_instance_id')
            ->where('a.id', $assignmentId)->where('i.organisation_id', $organisation->id)
            ->select('a.id', 'a.status', 'a.assigned_user_id', 'a.assigned_role_id', 'a.node_key', 'i.id as instance_id', 'i.initiated_by', 'i.workflow_version_id', 'i.context_snapshot')
            ->first();
        if (! $task) {
            throw new LicensingValidationException('WORKFLOW_TASK_NOT_FOUND', 'The workflow task is outside the active organisation scope.');
        }
        if ($task->status !== 'PENDING') {
            throw new RepositoryConflictException('The workflow task has already been decided.');
        }
        if ($task->assigned_role_id) {
            $holdsRole = DB::table('user_role_assignments')->where('user_id', $actor->id)->where('organisation_role_id', $task->assigned_role_id)
                ->where('organisation_id', $organisation->id)->where('status', 'ACTIVE')->exists();
            if (! $holdsRole) {
                throw new LicensingValidationException('TASK_NOT_ASSIGNED', 'You do not hold the role assigned to this workflow task.');
            }
        }

        $now = now();
        try {
            WorkflowValidator::assertDecision($actor->id, $task->initiated_by, $task->assigned_user_id, $decision, ($payload['emergency_override'] ?? null) === true);
        } catch (LicensingValidationException $e) {
            if (in_array($e->errorCode(), ['SELF_APPROVAL_DENIED', 'EMERGENCY_OVERRIDE_DISABLED'], true)) {
                $rule = SodRule::where('organisation_id', $organisation->id)->where('code', 'NO_SELF_APPROVAL')->where('status', 'ACTIVE')->first();
                if ($rule) {
                    $violationId = (string) Str::uuid();
                    DB::table('sod_violations')->insert([
                        'id' => $violationId, 'organisation_id' => $organisation->id, 'sod_rule_id' => $rule->id, 'actor_id' => $actor->id,
                        'resource_type' => 'WORKFLOW_ASSIGNMENT', 'resource_id' => $assignmentId, 'status' => 'OPEN',
                        'evidence' => AuditService::canonicalJson(['code' => $e->errorCode()]), 'detected_at' => $now, 'resolved_at' => null,
                    ]);
                    $this->outboxInsert($task->instance_id, 'SoDViolationDetected', $organisation->id, ['sod_violation_id' => $violationId, 'rule_code' => 'NO_SELF_APPROVAL', 'actor_id' => $actor->id, 'resource_type' => 'WORKFLOW_ASSIGNMENT', 'resource_id' => $assignmentId], $now);
                }
            }
            throw $e;
        }

        $instanceStatus = null;
        $nextAssignmentId = null;
        DB::transaction(function () use ($task, $actor, $decision, $reason, $organisation, $now, &$instanceStatus, &$nextAssignmentId) {
            DB::table('workflow_approvals')->insert([
                'id' => (string) Str::uuid(), 'workflow_instance_id' => $task->instance_id, 'workflow_assignment_id' => $task->id,
                'workflow_version_id' => $task->workflow_version_id, 'actor_id' => $actor->id, 'decision' => $decision, 'reason' => $reason,
                'authority_snapshot' => AuditService::canonicalJson(['role' => $actor->role, 'permissions' => DynamicPermissions::forUser($actor)]),
                'decided_at' => $now,
            ]);
            DB::table('workflow_assignments')->where('id', $task->id)->where('status', 'PENDING')
                ->update(['status' => $decision === 'APPROVE' ? 'APPROVED' : 'REJECTED']);

            if ($decision === 'REJECT') {
                $instanceStatus = 'REJECTED';
                DB::table('workflow_instances')->where('id', $task->instance_id)->update(['status' => 'REJECTED', 'completed_at' => $now]);
            } else {
                $context = json_decode($task->context_snapshot, true) ?? [];
                $next = $this->resolveNextNode($task->workflow_version_id, $task->node_key, $context);
                if (! $next || $next['nodeType'] === 'END') {
                    $instanceStatus = 'COMPLETED';
                    DB::table('workflow_instances')->where('id', $task->instance_id)->update(['status' => 'COMPLETED', 'completed_at' => $now]);
                    $this->outboxInsert($task->instance_id, 'WorkflowInstanceCompleted', $organisation->id, ['instance_id' => $task->instance_id], $now);
                } else {
                    $instanceStatus = 'IN_PROGRESS';
                    $workflowId = DB::table('workflow_versions')->where('id', $task->workflow_version_id)->value('workflow_id');
                    $assignee = $this->resolveAssignee($organisation, $task->initiated_by, $workflowId ?? '', $next['assigneeType'], $next['assigneeReference']);
                    $nextAssignmentId = (string) Str::uuid();
                    DB::table('workflow_assignments')->insert([
                        'id' => $nextAssignmentId, 'workflow_instance_id' => $task->instance_id, 'node_key' => $next['nodeKey'],
                        'assigned_user_id' => $assignee['assignedUserId'], 'assigned_role_id' => $assignee['assignedRoleId'],
                        'status' => 'PENDING', 'due_at' => null, 'assigned_at' => $now,
                    ]);
                    DB::table('workflow_instances')->where('id', $task->instance_id)->update(['current_node_key' => $next['nodeKey']]);
                    $this->outboxInsert($task->instance_id, 'WorkflowInstanceAssigned', $organisation->id, ['instance_id' => $task->instance_id, 'assignment_id' => $nextAssignmentId, 'node_key' => $next['nodeKey']], $now);
                }
            }
            AuditService::append($actor, "WORKFLOW_{$decision}", 'WORKFLOW_ASSIGNMENT', $task->id, ['organisationId' => $organisation->id, 'reason' => $reason, 'instanceStatus' => $instanceStatus], $now);
        });

        return ['id' => $task->id, 'decision' => $decision, 'decidedAt' => $now->toISOString(), 'instanceStatus' => $instanceStatus, 'nextAssignmentId' => $nextAssignmentId];
    }

    /**
     * A pure dry-run -- walks the same transition graph Assign/Decide
     * use, with a bounded hop count guarding against a hand-crafted
     * cyclical definition (`WorkflowValidator::definition()` already
     * forbids a transition back to its own source node, but not a longer
     * cycle across several nodes). Works against a version in any
     * status, including DRAFT, since validating routing before publish
     * is the point.
     *
     * @return array<string, mixed>
     */
    public function testWorkflowVersion(string $versionId, array $payload, User $actor, ?string $requestedOrganisationId): array
    {
        ['organisation' => $organisation] = EntitlementGate::assert($actor, 'ADVANCED_WORKFLOW', 'READ', 0, $requestedOrganisationId);
        $context = WorkflowValidator::testContext($payload);

        $version = WorkflowVersion::where('id', $versionId)->where('organisation_id', $organisation->id)->first();
        if (! $version) {
            throw new LicensingValidationException('WORKFLOW_VERSION_NOT_FOUND', 'The workflow version is outside the active organisation scope.');
        }
        $startNode = DB::table('workflow_nodes')->where('workflow_version_id', $versionId)->where('node_type', 'START')
            ->first(['node_key', 'node_type', 'label', 'assignee_type', 'assignee_reference']);
        if (! $startNode) {
            throw new LicensingValidationException('WORKFLOW_MALFORMED', 'The workflow version has no start node.');
        }

        $path = [['nodeKey' => $startNode->node_key, 'nodeType' => $startNode->node_type, 'label' => $startNode->label, 'assigneeType' => $startNode->assignee_type, 'assigneeReference' => $startNode->assignee_reference]];
        $cursor = $startNode->node_key;
        $terminal = 'NO_MATCHING_PATH';
        for ($hop = 0; $hop < 30; $hop++) {
            $next = $this->resolveNextNode($versionId, $cursor, $context);
            if (! $next) {
                break;
            }
            $path[] = $next;
            if ($next['nodeType'] === 'END') {
                $terminal = 'COMPLETED';
                break;
            }
            $cursor = $next['nodeKey'];
        }

        return ['versionId' => $versionId, 'context' => $context, 'path' => $path, 'terminal' => $terminal];
    }

    /** @return array<string, mixed> */
    public function createDelegation(array $payload, User $actor, ?string $requestedOrganisationId): array
    {
        $delegation = WorkflowValidator::delegation($payload);
        ['organisation' => $organisation] = EntitlementGate::assert($actor, 'ADVANCED_WORKFLOW', 'ADMIN_WRITE', 0, $requestedOrganisationId);

        foreach ([$delegation['delegatorUserId'], $delegation['delegateUserId']] as $userId) {
            $exists = User::where('id', $userId)->where('status', 'ACTIVE')->exists();
            if (! $exists) {
                throw new LicensingValidationException('DELEGATION_USER_NOT_FOUND', 'The delegator or delegate account could not be found.');
            }
        }
        if ($delegation['workflowId']) {
            $workflowExists = Workflow::where('id', $delegation['workflowId'])->where('organisation_id', $organisation->id)->exists();
            if (! $workflowExists) {
                throw new LicensingValidationException('WORKFLOW_NOT_FOUND', 'The referenced workflow is outside the active organisation scope.');
            }
        }

        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $organisation, $delegation, $actor) {
            DB::table('workflow_delegations')->insert([
                'id' => $id, 'organisation_id' => $organisation->id, 'delegator_user_id' => $delegation['delegatorUserId'],
                'delegate_user_id' => $delegation['delegateUserId'], 'workflow_id' => $delegation['workflowId'], 'scope' => $delegation['scope'],
                'status' => 'ACTIVE', 'effective_from' => Carbon::parse($delegation['effectiveFrom']), 'effective_to' => Carbon::parse($delegation['effectiveTo']),
                'approved_by' => $actor->id, 'reason' => $delegation['reason'], 'revoked_reason' => null,
            ]);
            AuditService::append($actor, 'WORKFLOW_DELEGATION_CREATED', 'WORKFLOW_DELEGATION', $id, ['organisationId' => $organisation->id, 'delegatorUserId' => $delegation['delegatorUserId'], 'delegateUserId' => $delegation['delegateUserId'], 'reason' => $delegation['reason']], now());
        });

        return [
            'id' => $id, 'status' => 'ACTIVE', 'delegatorUserId' => $delegation['delegatorUserId'], 'delegateUserId' => $delegation['delegateUserId'],
            'workflowId' => $delegation['workflowId'], 'scope' => $delegation['scope'], 'effectiveFrom' => $delegation['effectiveFrom'], 'effectiveTo' => $delegation['effectiveTo'],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function listDelegations(User $actor, ?string $requestedOrganisationId): array
    {
        ['organisation' => $organisation] = EntitlementGate::assert($actor, 'ADVANCED_WORKFLOW', 'READ', 0, $requestedOrganisationId);

        return DB::table('workflow_delegations as d')
            ->join('users as delegator', 'delegator.id', '=', 'd.delegator_user_id')
            ->join('users as delegate', 'delegate.id', '=', 'd.delegate_user_id')
            ->leftJoin('workflows as w', 'w.id', '=', 'd.workflow_id')
            ->where('d.organisation_id', $organisation->id)->orderByDesc('d.effective_from')
            ->select([
                'd.id', 'd.delegator_user_id', 'delegator.name as delegator_name', 'd.delegate_user_id', 'delegate.name as delegate_name',
                'd.workflow_id', 'w.name as workflow_name', 'd.scope', 'd.status', 'd.effective_from', 'd.effective_to', 'd.reason',
            ])->get()->map(fn ($row) => (array) $row)->all();
    }

    /** @return array<string, mixed> */
    public function revokeDelegation(string $delegationId, array $payload, User $actor, ?string $requestedOrganisationId): array
    {
        ['organisation' => $organisation] = EntitlementGate::assert($actor, 'ADVANCED_WORKFLOW', 'ADMIN_WRITE', 0, $requestedOrganisationId);

        $reason = trim((string) preg_replace('/\s+/', ' ', (string) ($payload['reason'] ?? '')));
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 240) {
            throw new LicensingValidationException('REASON_REQUIRED', 'Provide a 5 to 240 character revocation reason.');
        }

        $row = DB::table('workflow_delegations')->where('id', $delegationId)->where('organisation_id', $organisation->id)->first(['id', 'status']);
        if (! $row) {
            throw new LicensingValidationException('DELEGATION_NOT_FOUND', 'The delegation is outside the active organisation scope.');
        }
        if ($row->status !== 'ACTIVE') {
            throw new RepositoryConflictException('Only an active delegation can be revoked.');
        }

        DB::transaction(function () use ($delegationId, $organisation, $reason, $actor) {
            DB::table('workflow_delegations')->where('id', $delegationId)->where('status', 'ACTIVE')
                ->update(['status' => 'REVOKED', 'revoked_reason' => $reason]);
            AuditService::append($actor, 'WORKFLOW_DELEGATION_REVOKED', 'WORKFLOW_DELEGATION', $delegationId, ['organisationId' => $organisation->id, 'reason' => $reason], now());
        });

        return ['id' => $delegationId, 'status' => 'REVOKED'];
    }
}
