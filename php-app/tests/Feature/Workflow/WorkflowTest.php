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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithStepUp;
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
    use InteractsWithStepUp;

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
            ->withFreshStepUp()
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

        $created = $this->actingAs($ctx['owner'])->withFreshStepUp()
            ->postJson('/api/v1/workflows', array_merge($definition, ['name' => 'Purchase Approval']));
        $created->assertStatus(201)->assertJsonPath('workflow.status', 'DRAFT')->assertJsonPath('workflow.version', 1);
        $this->assertDatabaseHas('license_usage', ['organisation_license_id' => $ctx['license']->id, 'metric_key' => 'WORKFLOWS', 'reserved_value' => 1]);

        // A duplicate name is a real conflict.
        $this->actingAs($ctx['owner'])->withFreshStepUp()
            ->postJson('/api/v1/workflows', array_merge($definition, ['name' => 'Purchase Approval']))
            ->assertStatus(409);

        $versionId = $created->json('workflow.versionId');
        $published = $this->actingAs($publisher)->withFreshStepUp()
            ->postJson("/api/v1/workflows/versions/{$versionId}/publication");
        $published->assertStatus(200)->assertJsonPath('workflowVersion.status', 'PUBLISHED');
        $this->assertDatabaseHas('workflows', ['name' => 'Purchase Approval', 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('license_usage', ['organisation_license_id' => $ctx['license']->id, 'metric_key' => 'WORKFLOWS', 'used_value' => 1, 'reserved_value' => 0]);

        // Already published -- a second publish attempt is a conflict.
        $this->actingAs($publisher)->withFreshStepUp()
            ->postJson("/api/v1/workflows/versions/{$versionId}/publication")
            ->assertStatus(409);
    }

    public function test_a_malformed_workflow_definition_is_rejected_with_specific_codes(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-WF-0002');
        $this->openReview($ctx['owner']);

        $this->actingAs($ctx['owner'])->withFreshStepUp()
            ->postJson('/api/v1/workflows', ['name' => 'Bad Domain', 'domain_action' => 'NOT_A_REAL_ACTION', 'nodes' => [], 'transitions' => []])
            ->assertStatus(422)->assertJsonPath('code', 'WORKFLOW_DOMAIN_UNSUPPORTED');

        $this->actingAs($ctx['owner'])->withFreshStepUp()
            ->postJson('/api/v1/workflows', [
                'name' => 'No Terminals', 'domain_action' => 'expense',
                'nodes' => [['id' => 'a', 'type' => 'START', 'label' => 'Node A'], ['id' => 'b', 'type' => 'START', 'label' => 'Node B']],
                'transitions' => [['from' => 'a', 'to' => 'b']],
            ])
            ->assertStatus(422)->assertJsonPath('code', 'WORKFLOW_TERMINALS_INVALID');

        $this->actingAs($ctx['owner'])->withFreshStepUp()
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
        $created = $this->actingAs($ctx['owner'])->withFreshStepUp()->postJson('/api/v1/workflows', $definition);
        $created->assertStatus(201);
        $versionId = $created->json('workflow.versionId');
        // A different user (the approver) publishes -- publishWorkflowVersion's
        // own maker-checker refuses the draft's own creator as publisher.
        $this->actingAs($approver)->withFreshStepUp()
            ->postJson("/api/v1/workflows/versions/{$versionId}/publication")->assertStatus(200);

        // Below the threshold: completes immediately, no assignment.
        $small = $this->actingAs($ctx['owner'])->withFreshStepUp()
            ->postJson('/api/v1/workflows/instances', ['domain_action' => 'expense', 'resource_type' => 'EXPENSE', 'resource_id' => 'exp-0001', 'context' => ['amount_cents' => 500]], ['Idempotency-Key' => (string) Str::uuid()]);
        $small->assertStatus(201)->assertJsonPath('instance.status', 'COMPLETED')->assertJsonPath('instance.assignmentId', null);

        // Above the threshold: an approval task is created and assigned to the role.
        $large = $this->actingAs($ctx['owner'])->withFreshStepUp()
            ->postJson('/api/v1/workflows/instances', ['domain_action' => 'expense', 'resource_type' => 'EXPENSE', 'resource_id' => 'exp-0002', 'context' => ['amount_cents' => 50000]], ['Idempotency-Key' => (string) Str::uuid()]);
        $large->assertStatus(201)->assertJsonPath('instance.status', 'IN_PROGRESS');
        $assignmentId = $large->json('instance.assignmentId');
        $this->assertNotNull($assignmentId);
        $this->assertDatabaseHas('workflow_assignments', ['id' => $assignmentId, 'assigned_role_id' => $approverRole->id, 'status' => 'PENDING']);

        // A user who does not hold the role cannot decide the task.
        $outsider = $this->makeUser($ctx['taxpayer'], 'outsider-0003@test.test');
        $this->actingAs($outsider)->withFreshStepUp()
            ->postJson("/api/v1/workflow-tasks/{$assignmentId}/decision", ['decision' => 'approve', 'reason' => 'Attempting without the role.'])
            ->assertStatus(422)->assertJsonPath('code', 'TASK_NOT_ASSIGNED');

        // The role holder approves -- the graph advances to END and the instance completes.
        $decided = $this->actingAs($approver)->withFreshStepUp()
            ->postJson("/api/v1/workflow-tasks/{$assignmentId}/decision", ['decision' => 'approve', 'reason' => 'Verified against budget.']);
        $decided->assertStatus(200)->assertJsonPath('decision.instanceStatus', 'COMPLETED')->assertJsonPath('decision.nextAssignmentId', null);

        // No active workflow at all for a domain action is a clean error, not a crash.
        $this->actingAs($ctx['owner'])->withFreshStepUp()
            ->postJson('/api/v1/workflows/instances', ['domain_action' => 'journal', 'resource_type' => 'JOURNAL', 'resource_id' => 'j-1'], ['Idempotency-Key' => (string) Str::uuid()])
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
        $created = $this->actingAs($ctx['owner'])->withFreshStepUp()->postJson('/api/v1/workflows', $definition);
        $versionId = $created->json('workflow.versionId');
        $this->actingAs($approver)->withFreshStepUp()->postJson("/api/v1/workflows/versions/{$versionId}/publication")->assertStatus(200);
        $instance = $this->actingAs($ctx['owner'])->withFreshStepUp()
            ->postJson('/api/v1/workflows/instances', ['domain_action' => 'journal', 'resource_type' => 'JOURNAL', 'resource_id' => 'j-100'], ['Idempotency-Key' => (string) Str::uuid()]);
        $assignmentId = $instance->json('instance.assignmentId');

        $rejected = $this->actingAs($approver)->withFreshStepUp()
            ->postJson("/api/v1/workflow-tasks/{$assignmentId}/decision", ['decision' => 'reject', 'reason' => 'Budget exceeded, journal rejected.']);
        $rejected->assertStatus(200)->assertJsonPath('decision.instanceStatus', 'REJECTED');
        $this->assertDatabaseHas('workflow_instances', ['id' => $instance->json('instance.id'), 'status' => 'REJECTED']);

        // Already decided -- a second decision is a conflict.
        $this->actingAs($approver)->withFreshStepUp()
            ->postJson("/api/v1/workflow-tasks/{$assignmentId}/decision", ['decision' => 'approve', 'reason' => 'Too late, already decided.'])
            ->assertStatus(409);
    }

    /**
     * Red-team follow-up (Duplicate-Submission Sweep, 2026-09-13): the
     * `workflow_approvals` audit row used to be inserted before the
     * `workflow_assignments` UPDATE's own affected-row count was checked,
     * so a genuine concurrent double-decide (two requests both reading
     * PENDING before either commits) could still log two approval rows
     * even though only one assignment-status change ever won. Simulated
     * the same way `ComplianceCaseTest`'s own race regressions do: a
     * `DB::listen` hook injects the concurrent decision between this
     * request's own read of the task and its guarded UPDATE.
     */
    public function test_a_workflow_task_decision_that_races_a_concurrent_decision_is_rejected_not_silently_applied(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-WF-RACE-0001');
        $this->openReview($ctx['owner']);
        $role = OrganisationRole::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'name' => 'Race Approver',
            'description' => 'Approves for the race regression test.', 'version' => 1, 'branch_scope' => '[]', 'approval_limit_cents' => null,
            'status' => 'ACTIVE', 'created_by' => $ctx['owner']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $approver = $this->makeUser($ctx['taxpayer'], 'approver-race-0001@test.test');
        $this->grantRole($ctx['organisation'], $role, $approver, $ctx['owner']);
        $definition = [
            'name' => 'Race Approval', 'domain_action' => 'journal',
            'nodes' => [['id' => 'start', 'type' => 'START', 'label' => 'Start'], ['id' => 'approve', 'type' => 'APPROVAL', 'assignee_type' => 'role', 'assignee_ref' => $role->id, 'label' => 'Approval'], ['id' => 'end', 'type' => 'END', 'label' => 'End']],
            'transitions' => [['from' => 'start', 'to' => 'approve'], ['from' => 'approve', 'to' => 'end']],
        ];
        $created = $this->actingAs($ctx['owner'])->withFreshStepUp()->postJson('/api/v1/workflows', $definition);
        $versionId = $created->json('workflow.versionId');
        $this->actingAs($approver)->withFreshStepUp()->postJson("/api/v1/workflows/versions/{$versionId}/publication")->assertStatus(200);
        $instance = $this->actingAs($ctx['owner'])->withFreshStepUp()
            ->postJson('/api/v1/workflows/instances', ['domain_action' => 'journal', 'resource_type' => 'JOURNAL', 'resource_id' => 'j-race-1'], ['Idempotency-Key' => (string) Str::uuid()]);
        $assignmentId = $instance->json('instance.assignmentId');
        $this->assertDatabaseHas('workflow_assignments', ['id' => $assignmentId, 'status' => 'PENDING']);

        $sabotaged = false;
        DB::listen(function ($query) use (&$sabotaged, $assignmentId) {
            if ($sabotaged || ! str_contains($query->sql, 'from `workflow_assignments` as `a`')) {
                return;
            }
            $sabotaged = true;
            DB::table('workflow_assignments')->where('id', $assignmentId)->update(['status' => 'REJECTED']);
        });

        $response = $this->actingAs($approver)->withFreshStepUp()
            ->postJson("/api/v1/workflow-tasks/{$assignmentId}/decision", ['decision' => 'approve', 'reason' => 'Verified against budget.']);

        $response->assertStatus(409);
        $this->assertSame('REJECTED', DB::table('workflow_assignments')->where('id', $assignmentId)->value('status'), "The concurrent winner's status must survive untouched.");
        $this->assertSame(0, DB::table('workflow_approvals')->where('workflow_assignment_id', $assignmentId)->count(), 'The loser of the race must never log an approval row.');
    }

    /**
     * Red-team punch list #9 (docs/RED_TEAM_OPEN_ITEMS_CONSOLIDATED_
     * 2026-09-15.md): a malformed JSON `reason` (an array, not a string)
     * must be cleanly rejected, not silently coerced to the literal
     * string "Array" by a bare (string) cast -- which happens to be
     * exactly 5 characters, the same as this field's own minimum length,
     * so it would otherwise slide straight through unnoticed and get
     * stored as this approval decision's audit-trail reason.
     */
    public function test_a_non_string_decision_reason_is_rejected_not_silently_coerced(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-WF-FUZZ-0001');
        $this->openReview($ctx['owner']);
        $role = OrganisationRole::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'name' => 'Fuzz Approver',
            'description' => 'Approves for the fuzz regression test.', 'version' => 1, 'branch_scope' => '[]', 'approval_limit_cents' => null,
            'status' => 'ACTIVE', 'created_by' => $ctx['owner']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $approver = $this->makeUser($ctx['taxpayer'], 'approver-fuzz-0001@test.test');
        $this->grantRole($ctx['organisation'], $role, $approver, $ctx['owner']);
        $definition = [
            'name' => 'Fuzz Approval', 'domain_action' => 'journal',
            'nodes' => [['id' => 'start', 'type' => 'START', 'label' => 'Start'], ['id' => 'approve', 'type' => 'APPROVAL', 'assignee_type' => 'role', 'assignee_ref' => $role->id, 'label' => 'Approval'], ['id' => 'end', 'type' => 'END', 'label' => 'End']],
            'transitions' => [['from' => 'start', 'to' => 'approve'], ['from' => 'approve', 'to' => 'end']],
        ];
        $created = $this->actingAs($ctx['owner'])->withFreshStepUp()->postJson('/api/v1/workflows', $definition);
        $versionId = $created->json('workflow.versionId');
        $this->actingAs($approver)->withFreshStepUp()->postJson("/api/v1/workflows/versions/{$versionId}/publication")->assertStatus(200);
        $instance = $this->actingAs($ctx['owner'])->withFreshStepUp()
            ->postJson('/api/v1/workflows/instances', ['domain_action' => 'journal', 'resource_type' => 'JOURNAL', 'resource_id' => 'j-fuzz-1'], ['Idempotency-Key' => (string) Str::uuid()]);
        $assignmentId = $instance->json('instance.assignmentId');

        $response = $this->actingAs($approver)->withFreshStepUp()
            ->postJson("/api/v1/workflow-tasks/{$assignmentId}/decision", ['decision' => 'approve', 'reason' => ['not', 'a', 'string']]);

        $response->assertStatus(422)->assertJsonPath('code', 'REASON_REQUIRED');
        $this->assertDatabaseHas('workflow_assignments', ['id' => $assignmentId, 'status' => 'PENDING']);
        $this->assertDatabaseMissing('workflow_approvals', ['workflow_assignment_id' => $assignmentId]);
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
        $created = $this->actingAs($ctx['owner'])->withFreshStepUp()->postJson('/api/v1/workflows', $definition);
        $versionId = $created->json('workflow.versionId');
        $this->actingAs($publisher)->withFreshStepUp()->postJson("/api/v1/workflows/versions/{$versionId}/publication")->assertStatus(200);
        $instance = $this->actingAs($ctx['owner'])->withFreshStepUp()
            ->postJson('/api/v1/workflows/instances', ['domain_action' => 'role_change', 'resource_type' => 'ROLE_CHANGE', 'resource_id' => 'rc-1'], ['Idempotency-Key' => (string) Str::uuid()]);
        $assignmentId = $instance->json('instance.assignmentId');

        $this->actingAs($ctx['owner'])->withFreshStepUp()
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
        $created = $this->actingAs($ctx['owner'])->withFreshStepUp()->postJson('/api/v1/workflows', $definition);
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

        $this->actingAs($ctx['owner'])->withFreshStepUp()
            ->postJson('/api/v1/workflows/delegations', [
                'delegator_user_id' => $target->id, 'delegate_user_id' => $target->id,
                'effective_from' => $this->isoMillis(now()->subDay()), 'effective_to' => $this->isoMillis(now()->addDay()), 'reason' => 'Self delegation attempt.',
            ], ['Idempotency-Key' => 'test-idem-delegation-self-0001'])->assertStatus(422)->assertJsonPath('code', 'DELEGATION_SELF');

        $createKey = 'test-idem-delegation-create-0001';
        $createPayload = [
            'delegator_user_id' => $target->id, 'delegate_user_id' => $delegate->id,
            'effective_from' => $this->isoMillis(now()->subDay()), 'effective_to' => $this->isoMillis(now()->addDays(7)), 'reason' => 'Annual leave cover.',
        ];
        $delegation = $this->actingAs($ctx['owner'])->withFreshStepUp()
            ->postJson('/api/v1/workflows/delegations', $createPayload, ['Idempotency-Key' => $createKey]);
        $delegation->assertStatus(201)->assertJsonPath('delegation.status', 'ACTIVE');
        $delegationId = $delegation->json('delegation.id');

        // Duplicate-Submission Sweep follow-up (2026-09-15): a double-click
        // (the same key) replays the same delegation, not a second row.
        $replay = $this->actingAs($ctx['owner'])->withFreshStepUp()
            ->postJson('/api/v1/workflows/delegations', $createPayload, ['Idempotency-Key' => $createKey]);
        $replay->assertStatus(201)->assertJsonPath('delegation.id', $delegationId);
        $this->assertSame(1, DB::table('workflow_delegations')->where('delegator_user_id', $target->id)->where('delegate_user_id', $delegate->id)->count());

        $listed = $this->actingAs($ctx['owner'])->getJson('/api/v1/workflows/delegations');
        $listed->assertStatus(200)->assertJsonCount(1, 'delegations');

        $definition = [
            'name' => 'Primary Admin Change Approval', 'domain_action' => 'primary_admin_change',
            'nodes' => [['id' => 'start', 'type' => 'START', 'label' => 'Start'], ['id' => 'approve', 'type' => 'APPROVAL', 'assignee_type' => 'user', 'assignee_ref' => $target->id, 'label' => 'Approval'], ['id' => 'end', 'type' => 'END', 'label' => 'End']],
            'transitions' => [['from' => 'start', 'to' => 'approve'], ['from' => 'approve', 'to' => 'end']],
        ];
        $created = $this->actingAs($ctx['owner'])->withFreshStepUp()->postJson('/api/v1/workflows', $definition);
        $versionId = $created->json('workflow.versionId');
        // A different user publishes -- the creator can't be its own approver.
        $this->actingAs($delegate)->withFreshStepUp()->postJson("/api/v1/workflows/versions/{$versionId}/publication")->assertStatus(200);
        $instance = $this->actingAs($ctx['owner'])->withFreshStepUp()
            ->postJson('/api/v1/workflows/instances', ['domain_action' => 'primary_admin_change', 'resource_type' => 'ADMINISTRATOR', 'resource_id' => 'admin-1'], ['Idempotency-Key' => (string) Str::uuid()]);
        $instance->assertStatus(201);
        $this->assertDatabaseHas('workflow_assignments', ['id' => $instance->json('instance.assignmentId'), 'assigned_user_id' => $delegate->id]);

        $revoked = $this->actingAs($ctx['owner'])->withFreshStepUp()
            ->postJson("/api/v1/workflows/delegations/{$delegationId}/revocation", ['reason' => 'Cover period ended early.'], ['Idempotency-Key' => 'test-idem-delegation-revoke-0001']);
        $revoked->assertStatus(200)->assertJsonPath('delegation.status', 'REVOKED');

        // A second delegation assigned after the revocation goes to the
        // real target, not the now-revoked delegate.
        $secondInstance = $this->actingAs($ctx['owner'])->withFreshStepUp()
            ->postJson('/api/v1/workflows/instances', ['domain_action' => 'primary_admin_change', 'resource_type' => 'ADMINISTRATOR', 'resource_id' => 'admin-2'], ['Idempotency-Key' => (string) Str::uuid()]);
        $this->assertDatabaseHas('workflow_assignments', ['id' => $secondInstance->json('instance.assignmentId'), 'assigned_user_id' => $target->id]);

        // Already revoked -- a genuinely new attempt (a different key) is a conflict.
        $this->actingAs($ctx['owner'])->withFreshStepUp()
            ->postJson("/api/v1/workflows/delegations/{$delegationId}/revocation", ['reason' => 'Repeat revocation attempt.'], ['Idempotency-Key' => 'test-idem-delegation-revoke-0002'])
            ->assertStatus(409);
    }

    /**
     * Red-team punch list #9 (docs/RED_TEAM_OPEN_ITEMS_CONSOLIDATED_
     * 2026-09-15.md): a malformed JSON `reason` (an array) must be
     * rejected cleanly on both createDelegation() and revokeDelegation()
     * -- see test_a_non_string_decision_reason_is_rejected_not_silently_
     * coerced()'s own doc comment for why "Array" specifically slides
     * past a naive `< 5` minimum-length check.
     */
    public function test_a_non_string_delegation_reason_is_rejected_not_silently_coerced_on_create_and_revoke(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-WF-FUZZ-0002');
        $this->openReview($ctx['owner']);
        $target = $this->makeUser($ctx['taxpayer'], 'target-fuzz-0002@test.test');
        $delegate = $this->makeUser($ctx['taxpayer'], 'delegate-fuzz-0002@test.test');

        $badCreate = $this->actingAs($ctx['owner'])->withFreshStepUp()
            ->postJson('/api/v1/workflows/delegations', [
                'delegator_user_id' => $target->id, 'delegate_user_id' => $delegate->id,
                'effective_from' => $this->isoMillis(now()->subDay()), 'effective_to' => $this->isoMillis(now()->addDays(7)),
                'reason' => ['not', 'a', 'string'],
            ], ['Idempotency-Key' => 'test-idem-delegation-fuzz-create-0001']);
        $badCreate->assertStatus(422)->assertJsonPath('code', 'REASON_REQUIRED');
        $this->assertSame(0, DB::table('workflow_delegations')->where('delegator_user_id', $target->id)->count());

        $delegation = $this->actingAs($ctx['owner'])->withFreshStepUp()
            ->postJson('/api/v1/workflows/delegations', [
                'delegator_user_id' => $target->id, 'delegate_user_id' => $delegate->id,
                'effective_from' => $this->isoMillis(now()->subDay()), 'effective_to' => $this->isoMillis(now()->addDays(7)), 'reason' => 'Annual leave cover.',
            ], ['Idempotency-Key' => 'test-idem-delegation-fuzz-create-0002']);
        $delegationId = $delegation->json('delegation.id');

        $badRevoke = $this->actingAs($ctx['owner'])->withFreshStepUp()
            ->postJson("/api/v1/workflows/delegations/{$delegationId}/revocation", ['reason' => ['not', 'a', 'string']], ['Idempotency-Key' => 'test-idem-delegation-fuzz-revoke-0001']);
        $badRevoke->assertStatus(422)->assertJsonPath('code', 'REASON_REQUIRED');
        $this->assertDatabaseHas('workflow_delegations', ['id' => $delegationId, 'status' => 'ACTIVE']);
    }

    /**
     * Red-team punch list #7 (docs/RED_TEAM_OPEN_ITEMS_CONSOLIDATED_
     * 2026-09-15.md): a task already assigned through an ACTIVE delegation
     * must not stay decidable once that delegation is revoked --
     * `resolveAssignee()` resolves and freezes the redirect into
     * `assigned_user_id` at assign time, so without a live recheck the
     * delegate could still decide it, and the original delegator never
     * could (their id was overwritten, not preserved). Distinct from the
     * concurrent-decision race test above: this gap was open for the
     * task's whole pending lifetime, not a narrow simultaneous-request
     * window.
     */
    public function test_a_task_assigned_through_a_delegation_can_no_longer_be_decided_once_that_delegation_is_revoked(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-WF-0008');
        $this->openReview($ctx['owner']);
        $target = $this->makeUser($ctx['taxpayer'], 'target-0008@test.test');
        $delegate = $this->makeUser($ctx['taxpayer'], 'delegate-0008@test.test');

        $delegation = $this->actingAs($ctx['owner'])->withFreshStepUp()
            ->postJson('/api/v1/workflows/delegations', [
                'delegator_user_id' => $target->id, 'delegate_user_id' => $delegate->id,
                'effective_from' => $this->isoMillis(now()->subDay()), 'effective_to' => $this->isoMillis(now()->addDays(7)), 'reason' => 'Annual leave cover.',
            ], ['Idempotency-Key' => 'test-idem-delegation-toctou-create-0001']);
        $delegationId = $delegation->json('delegation.id');

        $definition = [
            'name' => 'TOCTOU Delegation Approval', 'domain_action' => 'primary_admin_change',
            'nodes' => [['id' => 'start', 'type' => 'START', 'label' => 'Start'], ['id' => 'approve', 'type' => 'APPROVAL', 'assignee_type' => 'user', 'assignee_ref' => $target->id, 'label' => 'Approval'], ['id' => 'end', 'type' => 'END', 'label' => 'End']],
            'transitions' => [['from' => 'start', 'to' => 'approve'], ['from' => 'approve', 'to' => 'end']],
        ];
        $created = $this->actingAs($ctx['owner'])->withFreshStepUp()->postJson('/api/v1/workflows', $definition);
        $versionId = $created->json('workflow.versionId');
        $this->actingAs($delegate)->withFreshStepUp()->postJson("/api/v1/workflows/versions/{$versionId}/publication")->assertStatus(200);
        $instance = $this->actingAs($ctx['owner'])->withFreshStepUp()
            ->postJson('/api/v1/workflows/instances', ['domain_action' => 'primary_admin_change', 'resource_type' => 'ADMINISTRATOR', 'resource_id' => 'admin-toctou-1'], ['Idempotency-Key' => (string) Str::uuid()]);
        $assignmentId = $instance->json('instance.assignmentId');
        $this->assertDatabaseHas('workflow_assignments', ['id' => $assignmentId, 'assigned_user_id' => $delegate->id, 'delegated_from_user_id' => $target->id]);

        // Revoke the delegation *after* the task was already routed
        // through it -- the delegate must lose the ability to decide it,
        // even though `assigned_user_id` still literally points at them.
        $this->actingAs($ctx['owner'])->withFreshStepUp()
            ->postJson("/api/v1/workflows/delegations/{$delegationId}/revocation", ['reason' => 'Compromised device suspected.'], ['Idempotency-Key' => 'test-idem-delegation-toctou-revoke-0001'])
            ->assertStatus(200)->assertJsonPath('delegation.status', 'REVOKED');

        $this->actingAs($delegate)->withFreshStepUp()
            ->postJson("/api/v1/workflow-tasks/{$assignmentId}/decision", ['decision' => 'approve', 'reason' => 'Attempting after revocation.'])
            ->assertStatus(422)->assertJsonPath('code', 'TASK_NOT_ASSIGNED');
        $this->assertSame('PENDING', DB::table('workflow_assignments')->where('id', $assignmentId)->value('status'), 'The task must remain undecided.');

        // The original delegator can't step in and decide it either --
        // resolveAssignee() overwrote assigned_user_id with the delegate's
        // id at assign time, so this is pre-existing behaviour, not a
        // consequence of this fix: once routed through a delegation, only
        // the delegate (while it's active), never the delegator, can
        // decide that specific task.
        $this->actingAs($target)->withFreshStepUp()
            ->postJson("/api/v1/workflow-tasks/{$assignmentId}/decision", ['decision' => 'approve', 'reason' => 'Delegator attempting directly.'])
            ->assertStatus(422)->assertJsonPath('code', 'TASK_NOT_ASSIGNED');
    }
}
