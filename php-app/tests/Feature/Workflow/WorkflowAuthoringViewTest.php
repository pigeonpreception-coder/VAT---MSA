<?php

namespace Tests\Feature\Workflow;

use App\Domain\Licensing\AccessReviewWindow;
use App\Models\LicenseUsage;
use App\Models\Organisation;
use App\Models\OrganisationLicense;
use App\Models\OrganisationRole;
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
use Tests\TestCase;

/**
 * Covers the real Blade UI for the workflow engine's own authoring
 * console (App\Http\Controllers\Workflow\WorkflowAuthoringViewController /
 * resources/views/workflows/index.blade.php) -- reuses
 * App\Services\Workflow\WorkflowService directly, already covered end to
 * end (licence-seat reservation, conditional routing, the maker-checker
 * self-publish/self-approval refusals) by
 * tests/Feature/Workflow/WorkflowTest.php. This file's own job is the
 * access gate, the view's rendering, the real form submissions reached
 * through this UI, and the route-level password.confirm gate every write
 * here carries (matching the JSON API's own unconditional step-up
 * posture on every command but the side-effect-free test dry-run).
 */
class WorkflowAuthoringViewTest extends TestCase
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
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@wfview.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);
        DB::table('access_reviews')->insert([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'name' => 'Quarterly access review',
            'review_type' => 'QUARTERLY', 'status' => 'OPEN', 'period_start' => AccessReviewWindow::current()['periodStart'],
            'due_at' => AccessReviewWindow::current()['dueAt'], 'created_by' => $owner->id, 'created_at' => now(),
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

    private function grantRole(Organisation $organisation, OrganisationRole $role, User $user, User $assignedBy): void
    {
        UserRoleAssignment::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'user_id' => $user->id,
            'employee_id' => null, 'organisation_role_id' => $role->id, 'status' => 'ACTIVE',
            'effective_from' => now(), 'effective_to' => null, 'assigned_by' => $assignedBy->id, 'created_at' => now(),
        ]);
    }

    /** @return array{name: string, domain_action: string, nodes: string, transitions: string} */
    private function draftForm(string $name, string $approverRoleId): array
    {
        return [
            'name' => $name, 'domain_action' => 'EXPENSE',
            'nodes' => json_encode([
                ['id' => 'start', 'type' => 'START', 'label' => 'Start'],
                ['id' => 'approve', 'type' => 'APPROVAL', 'assignee_type' => 'ROLE', 'assignee_ref' => $approverRoleId, 'label' => 'Approval'],
                ['id' => 'end', 'type' => 'END', 'label' => 'End'],
            ]),
            'transitions' => json_encode([['from' => 'start', 'to' => 'approve'], ['from' => 'approve', 'to' => 'end']]),
        ];
    }

    public function test_the_workflows_page_requires_authentication(): void
    {
        $this->get('/workflows')->assertRedirect('/login');
    }

    public function test_a_role_without_workflows_read_is_denied(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-WFV-0001');
        $staff = $this->makeUser($ctx['taxpayer'], 'staff@wfview.test', 'TAXPAYER_STAFF');

        $this->actingAs($staff)->get('/workflows')->assertForbidden();
    }

    public function test_the_page_renders_with_the_catalogue_and_hides_manage_actions_without_workflows_manage(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-WFV-0002');
        $accountant = $this->makeUser($ctx['taxpayer'], 'accountant@wfview.test', 'TAXPAYER_ACCOUNTANT');

        $response = $this->actingAs($accountant)->get('/workflows');

        $response->assertOk()->assertViewIs('workflows.index');
        $response->assertSee('Versioned workflows');
        $response->assertDontSee('Create a workflow draft');
    }

    public function test_creating_a_draft_requires_workflows_manage(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-WFV-0003');
        $accountant = $this->makeUser($ctx['taxpayer'], 'accountant2@wfview.test', 'TAXPAYER_ACCOUNTANT');
        $role = OrganisationRole::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'name' => 'Approver', 'description' => 'Approves.',
            'version' => 1, 'branch_scope' => '[]', 'approval_limit_cents' => null, 'status' => 'ACTIVE', 'created_by' => $ctx['owner']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($accountant)->withSession(['auth.password_confirmed_at' => time()])
            ->post('/workflows', $this->draftForm('Denied Draft', $role->id))
            ->assertForbidden();
    }

    public function test_creating_a_draft_without_a_fresh_step_up_redirects_to_password_confirmation(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-WFV-0004');
        $role = OrganisationRole::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'name' => 'Approver', 'description' => 'Approves.',
            'version' => 1, 'branch_scope' => '[]', 'approval_limit_cents' => null, 'status' => 'ACTIVE', 'created_by' => $ctx['owner']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($ctx['owner'])->post('/workflows', $this->draftForm('No Step Up Draft', $role->id));

        $response->assertRedirect(route('password.confirm'));
        $this->assertDatabaseMissing('workflows', ['name' => 'No Step Up Draft']);
    }

    public function test_a_manager_can_create_a_workflow_draft_with_a_fresh_step_up(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-WFV-0005');
        $role = OrganisationRole::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'name' => 'Approver', 'description' => 'Approves.',
            'version' => 1, 'branch_scope' => '[]', 'approval_limit_cents' => null, 'status' => 'ACTIVE', 'created_by' => $ctx['owner']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->post('/workflows', $this->draftForm('Expense Approval', $role->id));

        $response->assertRedirect('/workflows');
        $response->assertSessionHas('status');
        $this->assertDatabaseHas('workflows', ['name' => 'Expense Approval', 'status' => 'DRAFT']);
        $this->assertDatabaseHas('workflow_versions', ['status' => 'DRAFT', 'version_number' => 1]);
    }

    /** @return array{organisation: Organisation, owner: User, versionId: string, approver: User, approverRole: OrganisationRole} */
    private function createdDraft(string $vatNumber): array
    {
        $ctx = $this->makeLicensedOrganisation($vatNumber);
        $approverRole = OrganisationRole::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'name' => 'Approver', 'description' => 'Approves.',
            'version' => 1, 'branch_scope' => '[]', 'approval_limit_cents' => null, 'status' => 'ACTIVE', 'created_by' => $ctx['owner']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $approver = $this->makeUser($ctx['taxpayer'], "approver-{$vatNumber}@wfview.test");
        $this->grantRole($ctx['organisation'], $approverRole, $approver, $ctx['owner']);
        $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->post('/workflows', $this->draftForm("Draft {$vatNumber}", $approverRole->id));
        $versionId = DB::table('workflow_versions as v')->join('workflows as w', 'w.id', '=', 'v.workflow_id')
            ->where('w.name', "Draft {$vatNumber}")->value('v.id');

        return ['organisation' => $ctx['organisation'], 'owner' => $ctx['owner'], 'versionId' => $versionId, 'approver' => $approver, 'approverRole' => $approverRole];
    }

    public function test_the_drafts_own_creator_cannot_publish_it_themselves(): void
    {
        $ctx = $this->createdDraft('VAT-WFV-0006');

        $response = $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->post("/workflows/versions/{$ctx['versionId']}/publish");

        $response->assertRedirect('/workflows');
        $response->assertSessionHasErrors('publish');
        $this->assertDatabaseHas('workflow_versions', ['id' => $ctx['versionId'], 'status' => 'DRAFT']);
    }

    public function test_a_different_user_can_publish_the_draft(): void
    {
        $ctx = $this->createdDraft('VAT-WFV-0007');

        $response = $this->actingAs($ctx['approver'])->withSession(['auth.password_confirmed_at' => time()])
            ->post("/workflows/versions/{$ctx['versionId']}/publish");

        $response->assertRedirect('/workflows');
        $this->assertDatabaseHas('workflow_versions', ['id' => $ctx['versionId'], 'status' => 'PUBLISHED']);
    }

    public function test_testing_a_draft_versions_routing_does_not_require_step_up_and_shows_the_result(): void
    {
        $ctx = $this->createdDraft('VAT-WFV-0008');

        $response = $this->actingAs($ctx['owner'])->post("/workflows/versions/{$ctx['versionId']}/test", ['context' => '{}']);
        $response->assertRedirect('/workflows');

        $page = $this->actingAs($ctx['owner'])->get('/workflows');
        $page->assertSee('Test result:');
    }

    public function test_assigning_and_deciding_a_workflow_instance_end_to_end(): void
    {
        $ctx = $this->createdDraft('VAT-WFV-0009');
        $this->actingAs($ctx['approver'])->withSession(['auth.password_confirmed_at' => time()])
            ->post("/workflows/versions/{$ctx['versionId']}/publish");

        $assignResponse = $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->post('/workflows/instances', ['domain_action' => 'EXPENSE', 'resource_type' => 'EXPENSE_CLAIM', 'resource_id' => 'exp-0001', 'context' => '{}']);
        $assignResponse->assertRedirect('/workflows');
        $this->assertDatabaseHas('workflow_instances', ['resource_id' => 'exp-0001', 'status' => 'IN_PROGRESS']);
        $assignmentId = DB::table('workflow_assignments as a')->join('workflow_instances as i', 'i.id', '=', 'a.workflow_instance_id')
            ->where('i.resource_id', 'exp-0001')->value('a.id');

        // The initiator cannot decide their own task.
        $selfAttempt = $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->post("/workflow-tasks/{$assignmentId}/decide", ['decision' => 'APPROVE', 'reason' => 'Approving my own expense.']);
        $selfAttempt->assertRedirect('/workflows');
        $selfAttempt->assertSessionHasErrors('decide');
        $this->assertDatabaseHas('workflow_assignments', ['id' => $assignmentId, 'status' => 'PENDING']);

        $decided = $this->actingAs($ctx['approver'])->withSession(['auth.password_confirmed_at' => time()])
            ->post("/workflow-tasks/{$assignmentId}/decide", ['decision' => 'APPROVE', 'reason' => 'Reviewed and approved.']);
        $decided->assertRedirect('/workflows');
        $this->assertDatabaseHas('workflow_assignments', ['id' => $assignmentId, 'status' => 'APPROVED']);
        $this->assertDatabaseHas('workflow_instances', ['resource_id' => 'exp-0001', 'status' => 'COMPLETED']);
    }

    public function test_creating_and_revoking_a_delegation(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-WFV-0010');
        $delegate = $this->makeUser($ctx['taxpayer'], 'delegate@wfview.test');

        $created = $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->post('/workflows/delegations', [
                'delegator_user_id' => $ctx['owner']->id, 'delegate_user_id' => $delegate->id,
                'effective_from' => now()->format('Y-m-d\TH:i'), 'effective_to' => now()->addMonth()->format('Y-m-d\TH:i'),
                'reason' => 'Annual leave cover.',
            ]);
        $created->assertRedirect('/workflows');
        $this->assertDatabaseHas('workflow_delegations', ['delegator_user_id' => $ctx['owner']->id, 'delegate_user_id' => $delegate->id, 'status' => 'ACTIVE']);
        $delegationId = DB::table('workflow_delegations')->where('delegator_user_id', $ctx['owner']->id)->value('id');

        $revoked = $this->actingAs($ctx['owner'])->withSession(['auth.password_confirmed_at' => time()])
            ->post("/workflows/delegations/{$delegationId}/revoke", ['reason' => 'Back from leave early.']);
        $revoked->assertRedirect('/workflows');
        $this->assertDatabaseHas('workflow_delegations', ['id' => $delegationId, 'status' => 'REVOKED']);
    }
}
