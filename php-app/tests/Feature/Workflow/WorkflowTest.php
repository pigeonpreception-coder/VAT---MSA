<?php

namespace Tests\Feature\Workflow;

use App\Models\LicenseUsage;
use App\Models\Organisation;
use App\Models\OrganisationLicense;
use App\Models\OrganisationRole;
use App\Models\SodRule;
use App\Models\Subscription;
use App\Models\Taxpayer;
use App\Models\User;
use App\Models\UserRoleAssignment;
use Database\Seeders\LicensePlanSeeder;
use Database\Seeders\OrganisationAdministratorRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers App\Services\Workflow\WorkflowService (ported from
 * lib/data/control-plane-repository.ts's createWorkflowDraft/
 * publishWorkflowVersion/assignWorkflow/decideWorkflowTask/
 * testWorkflowVersion/createDelegation/listDelegations/revokeDelegation)
 * -- Phase 12's workflow-engine slice (Module 8 Phase C).
 */
class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(LicensePlanSeeder::class);
        $this->seed(OrganisationAdministratorRoleSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, owner: User, license: OrganisationLicense} */
    private function makeLicensedOrganisation(string $vatNumber): array
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => strtolower($vatNumber).'@test.test',
        ]);
        $organisation = Organisation::create([
            'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE',
        ]);
        $subscription = Subscription::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'provider' => 'LOCAL_SYNTHETIC',
            'provider_reference' => 'synthetic-'.Str::random(12), 'status' => 'ACTIVE', 'activated_at' => now()->subMonth(),
            'current_period_start' => now()->subMonth()->toDateString(), 'current_period_end' => now()->addMonths(2)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $license = OrganisationLicense::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'subscription_id' => $subscription->id,
            'license_plan_id' => 'plan-pilot-professional-v1', 'state' => 'ACTIVE', 'state_version' => 1,
            'effective_from' => now()->subMonth(), 'effective_to' => null, 'retention_policy' => 'NON_DESTRUCTIVE_TAX_RETENTION', 'updated_at' => now(),
        ]);
        LicenseUsage::create([
            'id' => (string) Str::uuid(), 'organisation_license_id' => $license->id, 'organisation_id' => $organisation->id,
            'metric_key' => 'WORKFLOWS', 'period_key' => '2026-Q3', 'used_value' => 0, 'reserved_value' => 0, 'version' => 1, 'updated_at' => now(),
        ]);
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner', 'license');
    }

    private function makeUser(Taxpayer $taxpayer, string $email, string $role = 'TAXPAYER_OWNER'): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => $email, 'email' => $email, 'password' => bcrypt('password'),
            'role' => $role, 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);
    }

    private function openReview(User $actor): void
    {
        $this->actingAs($actor)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/access-reviews')
            ->assertStatus(201);
    }

    /**
     * WorkflowValidator::delegation() requires a JS-style ISO UTC
     * timestamp (exactly 3-digit milliseconds, matching the regex the
     * source's own `ISO_TIMESTAMP_PATTERN` uses) -- Carbon's own
     * toISOString()/toJSON() output 6-digit microseconds instead, so
     * fixtures need this explicit format.
     */
    private function isoMillis(\Illuminate\Support\Carbon $date): string
    {
        return $date->format('Y-m-d\TH:i:s.v\Z');
    }

    private function grantRole(Organisation $organisation, OrganisationRole $role, User $user, User $assignedBy): void
    {
        UserRoleAssignment::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'user_id' => $user->id,
            'employee_id' => null, 'organisation_role_id' => $role->id, 'status' => 'ACTIVE',
            'effective_from' => now(), 'effective_to' => null, 'assigned_by' => $assignedBy->id, 'created_at' => now(),
        ]);
    }

    public function test_creating_and_publishing_a_workflow_draft_reserves_then_converts_a_licence_seat(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-WF-0001');
        $this->openReview($ctx['owner']);
        // publishWorkflowVersion's own assertWorkflowDecision (maker-checker)
        // refuses the draft's own creator as its publisher/approver -- a
        // separate user is required here, matching the source exactly.
        $publisher = $this->makeUser($ctx['taxpayer'], 'publisher-0001@test.test', 'TAXPAYER_ADMIN');
        $approverRole = OrganisationRole::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'name' => 'Purchase Approver',
            'description' => 'Approves purchase requests.', 'version' => 1, 'branch_scope' => '[]', 'approval_limit_cents' => null,
            'status' => 'ACTIVE', 'created_by' => $ctx['owner']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $definition = [
            'domain_action' => 'purchase_request',
            'nodes' => [
                ['id' => 'start', 'type' => 'START', 'label' => 'Start'],
                ['id' => 'approve', 'type' => 'APPROVAL', 'assignee_type' => 'role', 'assignee_ref' => $approverRole->id, 'label' => 'Manager Approval'],
                ['id' => 'end', 'type' => 'END', 'label' => 'Complete'],
            ],
            'transitions' => [
                ['from' => 'start', 'to' => 'approve'],
                ['from' => 'approve', 'to' => 'end'],
            ],
        ];

        $created = $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/workflows', array_merge($definition, ['name' => 'Purchase Approval']));
        $created->assertStatus(201)->assertJsonPath('workflow.status', 'DRAFT')->assertJsonPath('workflow.version', 1);
        $this->assertDatabaseHas('license_usage', ['organisation_license_id' => $ctx['license']->id, 'metric_key' => 'WORKFLOWS', 'reserved_value' => 1]);

        // A duplicate name is a real conflict.
        $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/workflows', array_merge($definition, ['name' => 'Purchase Approval']))
            ->assertStatus(409);

        $versionId = $created->json('workflow.versionId');
        $published = $this->actingAs($publisher)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/workflows/versions/{$versionId}/publication");
        $published->assertStatus(200)->assertJsonPath('workflowVersion.status', 'PUBLISHED');
        $this->assertDatabaseHas('workflows', ['name' => 'Purchase Approval', 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('license_usage', ['organisation_license_id' => $ctx['license']->id, 'metric_key' => 'WORKFLOWS', 'used_value' => 1, 'reserved_value' => 0]);

        // Already published -- a second publish attempt is a conflict.
        $this->actingAs($publisher)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/workflows/versions/{$versionId}/publication")
            ->assertStatus(409);
    }

    public function test_a_malformed_workflow_definition_is_rejected_with_specific_codes(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-WF-0002');
        $this->openReview($ctx['owner']);

        $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/workflows', ['name' => 'Bad Domain', 'domain_action' => 'NOT_A_REAL_ACTION', 'nodes' => [], 'transitions' => []])
            ->assertStatus(422)->assertJsonPath('code', 'WORKFLOW_DOMAIN_UNSUPPORTED');

        $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/workflows', [
                'name' => 'No Terminals', 'domain_action' => 'expense',
                'nodes' => [['id' => 'a', 'type' => 'START', 'label' => 'Node A'], ['id' => 'b', 'type' => 'START', 'label' => 'Node B']],
                'transitions' => [['from' => 'a', 'to' => 'b']],
            ])
            ->assertStatus(422)->assertJsonPath('code', 'WORKFLOW_TERMINALS_INVALID');

        $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/workflows', [
                'name' => 'Unassigned Approval', 'domain_action' => 'expense',
                'nodes' => [['id' => 'start', 'type' => 'START', 'label' => 'Start'], ['id' => 'approve', 'type' => 'APPROVAL', 'label' => 'Approve'], ['id' => 'end', 'type' => 'END', 'label' => 'End']],
                'transitions' => [['from' => 'start', 'to' => 'approve'], ['from' => 'approve', 'to' => 'end']],
            ])
            ->assertStatus(422)->assertJsonPath('code', 'WORKFLOW_ASSIGNEE_REQUIRED');
    }

    public function test_assigning_a_workflow_routes_conditionally_and_completes_immediately_when_no_approval_is_reached(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-WF-0003');
        $this->openReview($ctx['owner']);
        $approverRole = OrganisationRole::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'name' => 'Expense Approver',
            'description' => 'Approves expenses over the auto-approval threshold.', 'version' => 1, 'branch_scope' => '[]',
            'approval_limit_cents' => null, 'status' => 'ACTIVE', 'created_by' => $ctx['owner']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $approver = $this->makeUser($ctx['taxpayer'], 'approver-0003@test.test');
        $this->grantRole($ctx['organisation'], $approverRole, $approver, $ctx['owner']);

        // Low amounts route straight to END (the first matching transition,
        // by sequence); anything else falls through to the unconditional
        // second transition, which requires the role-assigned approval.
        $definition = [
            'name' => 'Expense Approval', 'domain_action' => 'expense',
            'nodes' => [
                ['id' => 'start', 'type' => 'START', 'label' => 'Start'],
                ['id' => 'approve', 'type' => 'APPROVAL', 'assignee_type' => 'role', 'assignee_ref' => $approverRole->id, 'label' => 'Approval'],
                ['id' => 'end', 'type' => 'END', 'label' => 'Complete'],
            ],
            'transitions' => [
                ['from' => 'start', 'to' => 'end', 'condition' => ['field' => 'amount_cents', 'operator' => 'LTE', 'value' => 1000]],
                ['from' => 'start', 'to' => 'approve'],
                ['from' => 'approve', 'to' => 'end'],
            ],
        ];
        $created = $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])->postJson('/api/v1/workflows', $definition);
        $created->assertStatus(201);
        $versionId = $created->json('workflow.versionId');
        // A different user (the approver) publishes -- publishWorkflowVersion's
        // own maker-checker refuses the draft's own creator as publisher.
        $this->actingAs($approver)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/workflows/versions/{$versionId}/publication")->assertStatus(200);

        // Below the threshold: completes immediately, no assignment.
        $small = $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/workflows/instances', ['domain_action' => 'expense', 'resource_type' => 'EXPENSE', 'resource_id' => 'exp-0001', 'context' => ['amount_cents' => 500]]);
        $small->assertStatus(201)->assertJsonPath('instance.status', 'COMPLETED')->assertJsonPath('instance.assignmentId', null);

        // Above the threshold: an approval task is created and assigned to the role.
        $large = $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/workflows/instances', ['domain_action' => 'expense', 'resource_type' => 'EXPENSE', 'resource_id' => 'exp-0002', 'context' => ['amount_cents' => 50000]]);
        $large->assertStatus(201)->assertJsonPath('instance.status', 'IN_PROGRESS');
        $assignmentId = $large->json('instance.assignmentId');
        $this->assertNotNull($assignmentId);
        $this->assertDatabaseHas('workflow_assignments', ['id' => $assignmentId, 'assigned_role_id' => $approverRole->id, 'status' => 'PENDING']);

        // A user who does not hold the role cannot decide the task.
        $outsider = $this->makeUser($ctx['taxpayer'], 'outsider-0003@test.test');
        $this->actingAs($outsider)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/workflow-tasks/{$assignmentId}/decision", ['decision' => 'approve', 'reason' => 'Attempting without the role.'])
            ->assertStatus(422)->assertJsonPath('code', 'TASK_NOT_ASSIGNED');

        // The role holder approves -- the graph advances to END and the instance completes.
        $decided = $this->actingAs($approver)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/workflow-tasks/{$assignmentId}/decision", ['decision' => 'approve', 'reason' => 'Verified against budget.']);
        $decided->assertStatus(200)->assertJsonPath('decision.instanceStatus', 'COMPLETED')->assertJsonPath('decision.nextAssignmentId', null);

        // No active workflow at all for a domain action is a clean error, not a crash.
        $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/workflows/instances', ['domain_action' => 'journal', 'resource_type' => 'JOURNAL', 'resource_id' => 'j-1'])
            ->assertStatus(422)->assertJsonPath('code', 'WORKFLOW_NOT_CONFIGURED');
    }

    public function test_rejecting_a_task_terminates_the_instance_immediately(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-WF-0004');
        $this->openReview($ctx['owner']);
        $role = OrganisationRole::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'name' => 'Journal Approver',
            'description' => 'Approves journals.', 'version' => 1, 'branch_scope' => '[]', 'approval_limit_cents' => null,
            'status' => 'ACTIVE', 'created_by' => $ctx['owner']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $approver = $this->makeUser($ctx['taxpayer'], 'approver-0004@test.test');
        $this->grantRole($ctx['organisation'], $role, $approver, $ctx['owner']);
        $definition = [
            'name' => 'Journal Approval', 'domain_action' => 'journal',
            'nodes' => [['id' => 'start', 'type' => 'START', 'label' => 'Start'], ['id' => 'approve', 'type' => 'APPROVAL', 'assignee_type' => 'role', 'assignee_ref' => $role->id, 'label' => 'Approval'], ['id' => 'end', 'type' => 'END', 'label' => 'End']],
            'transitions' => [['from' => 'start', 'to' => 'approve'], ['from' => 'approve', 'to' => 'end']],
        ];
        $created = $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])->postJson('/api/v1/workflows', $definition);
        $versionId = $created->json('workflow.versionId');
        $this->actingAs($approver)->withSession(['auth.password_confirmed_at' => time()])->postJson("/api/v1/workflows/versions/{$versionId}/publication")->assertStatus(200);
        $instance = $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/workflows/instances', ['domain_action' => 'journal', 'resource_type' => 'JOURNAL', 'resource_id' => 'j-100']);
        $assignmentId = $instance->json('instance.assignmentId');

        $rejected = $this->actingAs($approver)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/workflow-tasks/{$assignmentId}/decision", ['decision' => 'reject', 'reason' => 'Budget exceeded, journal rejected.']);
        $rejected->assertStatus(200)->assertJsonPath('decision.instanceStatus', 'REJECTED');
        $this->assertDatabaseHas('workflow_instances', ['id' => $instance->json('instance.id'), 'status' => 'REJECTED']);

        // Already decided -- a second decision is a conflict.
        $this->actingAs($approver)->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/workflow-tasks/{$assignmentId}/decision", ['decision' => 'approve', 'reason' => 'Too late, already decided.'])
            ->assertStatus(409);
    }

    public function test_self_approval_is_denied_and_recorded_as_a_segregation_of_duties_violation(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-WF-0005');
        $this->openReview($ctx['owner']);
        SodRule::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'code' => 'NO_SELF_APPROVAL',
            'name' => 'No self approval', 'action_set' => json_encode(['CREATE', 'APPROVE']), 'scope' => 'ALL_PROTECTED_WORKFLOWS',
            'mandatory' => true, 'status' => 'ACTIVE', 'effective_from' => now()->subDay(), 'created_at' => now(),
        ]);
        $role = OrganisationRole::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'name' => 'Role Change Approver',
            'description' => 'Approves role changes.', 'version' => 1, 'branch_scope' => '[]', 'approval_limit_cents' => null,
            'status' => 'ACTIVE', 'created_by' => $ctx['owner']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // The owner both initiates the workflow AND holds the approving role.
        $this->grantRole($ctx['organisation'], $role, $ctx['owner'], $ctx['owner']);
        // A separate user publishes -- the creator can't be its own approver.
        $publisher = $this->makeUser($ctx['taxpayer'], 'publisher-0005@test.test', 'TAXPAYER_ADMIN');
        $definition = [
            'name' => 'Role Change Approval', 'domain_action' => 'role_change',
            'nodes' => [['id' => 'start', 'type' => 'START', 'label' => 'Start'], ['id' => 'approve', 'type' => 'APPROVAL', 'assignee_type' => 'role', 'assignee_ref' => $role->id, 'label' => 'Approval'], ['id' => 'end', 'type' => 'END', 'label' => 'End']],
            'transitions' => [['from' => 'start', 'to' => 'approve'], ['from' => 'approve', 'to' => 'end']],
        ];
        $created = $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])->postJson('/api/v1/workflows', $definition);
        $versionId = $created->json('workflow.versionId');
        $this->actingAs($publisher)->withSession(['auth.password_confirmed_at' => time()])->postJson("/api/v1/workflows/versions/{$versionId}/publication")->assertStatus(200);
        $instance = $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/workflows/instances', ['domain_action' => 'role_change', 'resource_type' => 'ROLE_CHANGE', 'resource_id' => 'rc-1']);
        $assignmentId = $instance->json('instance.assignmentId');

        $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/workflow-tasks/{$assignmentId}/decision", ['decision' => 'approve', 'reason' => 'Approving my own initiated workflow.'])
            ->assertStatus(422)->assertJsonPath('code', 'SELF_APPROVAL_DENIED');

        $this->assertDatabaseHas('sod_violations', [
            'organisation_id' => $ctx['organisation']->id, 'actor_id' => $ctx['owner']->id,
            'resource_type' => 'WORKFLOW_ASSIGNMENT', 'resource_id' => $assignmentId, 'status' => 'OPEN',
        ]);
        // The task itself is untouched -- still pending, not silently decided.
        $this->assertDatabaseHas('workflow_assignments', ['id' => $assignmentId, 'status' => 'PENDING']);
    }

    public function test_testing_a_workflow_version_walks_the_path_without_any_side_effects(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-WF-0006');
        $this->openReview($ctx['owner']);
        $role = OrganisationRole::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'name' => 'API Credential Approver',
            'description' => 'Approves API credential issuance.', 'version' => 1, 'branch_scope' => '[]', 'approval_limit_cents' => null,
            'status' => 'ACTIVE', 'created_by' => $ctx['owner']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // 'approve' is deliberately unreachable -- the single transition
        // out of 'start' is conditional and there is no unconditional
        // fallback, so an unmatched context genuinely dead-ends at
        // 'start' rather than falling through anywhere else.
        $definition = [
            'name' => 'API Credential Approval', 'domain_action' => 'api_credential',
            'nodes' => [
                ['id' => 'start', 'type' => 'START', 'label' => 'Start'],
                ['id' => 'approve', 'type' => 'APPROVAL', 'assignee_type' => 'role', 'assignee_ref' => $role->id, 'label' => 'Approval'],
                ['id' => 'end', 'type' => 'END', 'label' => 'End'],
            ],
            'transitions' => [
                ['from' => 'start', 'to' => 'end', 'condition' => ['field' => 'amount_cents', 'operator' => 'EQ', 'value' => 0]],
            ],
        ];
        // Deliberately left in DRAFT -- Test must work before publish.
        $created = $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])->postJson('/api/v1/workflows', $definition);
        $versionId = $created->json('workflow.versionId');

        // ['context' => null], not [] -- Laravel's postJson serializes an
        // empty PHP array as a JSON array ([]), not an object, which
        // WorkflowValidator::testContext() correctly rejects as
        // PAYLOAD_INVALID; a real client omitting context sends an
        // object body, reproduced here as {"context":null}.
        $unmatched = $this->actingAs($ctx['owner'])->postJson("/api/v1/workflows/versions/{$versionId}/test", ['context' => null]);
        $unmatched->assertStatus(200)->assertJsonPath('test.terminal', 'NO_MATCHING_PATH');
        $this->assertCount(1, $unmatched->json('test.path'));

        $walked = $this->actingAs($ctx['owner'])->postJson("/api/v1/workflows/versions/{$versionId}/test", ['context' => ['amount_cents' => 0]]);
        $walked->assertStatus(200)->assertJsonPath('test.terminal', 'COMPLETED');
        $this->assertCount(2, $walked->json('test.path'));

        // Still DRAFT -- Test has no side effects.
        $this->assertDatabaseHas('workflow_versions', ['id' => $versionId, 'status' => 'DRAFT']);
    }

    public function test_delegation_lifecycle_redirects_a_user_assignee_and_can_be_revoked(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-WF-0007');
        $this->openReview($ctx['owner']);
        $target = $this->makeUser($ctx['taxpayer'], 'target-0007@test.test');
        $delegate = $this->makeUser($ctx['taxpayer'], 'delegate-0007@test.test');

        $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/workflows/delegations', [
                'delegator_user_id' => $target->id, 'delegate_user_id' => $target->id,
                'effective_from' => $this->isoMillis(now()->subDay()), 'effective_to' => $this->isoMillis(now()->addDay()), 'reason' => 'Self delegation attempt.',
            ])->assertStatus(422)->assertJsonPath('code', 'DELEGATION_SELF');

        $delegation = $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/workflows/delegations', [
                'delegator_user_id' => $target->id, 'delegate_user_id' => $delegate->id,
                'effective_from' => $this->isoMillis(now()->subDay()), 'effective_to' => $this->isoMillis(now()->addDays(7)), 'reason' => 'Annual leave cover.',
            ]);
        $delegation->assertStatus(201)->assertJsonPath('delegation.status', 'ACTIVE');
        $delegationId = $delegation->json('delegation.id');

        $listed = $this->actingAs($ctx['owner'])->getJson('/api/v1/workflows/delegations');
        $listed->assertStatus(200)->assertJsonCount(1, 'delegations');

        $definition = [
            'name' => 'Primary Admin Change Approval', 'domain_action' => 'primary_admin_change',
            'nodes' => [['id' => 'start', 'type' => 'START', 'label' => 'Start'], ['id' => 'approve', 'type' => 'APPROVAL', 'assignee_type' => 'user', 'assignee_ref' => $target->id, 'label' => 'Approval'], ['id' => 'end', 'type' => 'END', 'label' => 'End']],
            'transitions' => [['from' => 'start', 'to' => 'approve'], ['from' => 'approve', 'to' => 'end']],
        ];
        $created = $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])->postJson('/api/v1/workflows', $definition);
        $versionId = $created->json('workflow.versionId');
        // A different user publishes -- the creator can't be its own approver.
        $this->actingAs($delegate)->withSession(['auth.password_confirmed_at' => time()])->postJson("/api/v1/workflows/versions/{$versionId}/publication")->assertStatus(200);
        $instance = $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/workflows/instances', ['domain_action' => 'primary_admin_change', 'resource_type' => 'ADMINISTRATOR', 'resource_id' => 'admin-1']);
        $instance->assertStatus(201);
        $this->assertDatabaseHas('workflow_assignments', ['id' => $instance->json('instance.assignmentId'), 'assigned_user_id' => $delegate->id]);

        $revoked = $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/workflows/delegations/{$delegationId}/revocation", ['reason' => 'Cover period ended early.']);
        $revoked->assertStatus(200)->assertJsonPath('delegation.status', 'REVOKED');

        // A second delegation assigned after the revocation goes to the
        // real target, not the now-revoked delegate.
        $secondInstance = $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/workflows/instances', ['domain_action' => 'primary_admin_change', 'resource_type' => 'ADMINISTRATOR', 'resource_id' => 'admin-2']);
        $this->assertDatabaseHas('workflow_assignments', ['id' => $secondInstance->json('instance.assignmentId'), 'assigned_user_id' => $target->id]);

        // Already revoked -- revoking it again is a conflict.
        $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/workflows/delegations/{$delegationId}/revocation", ['reason' => 'Repeat revocation attempt.'])
            ->assertStatus(409);
    }
}
