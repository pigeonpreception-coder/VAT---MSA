<?php

namespace Tests\Feature\Administration;

use App\Models\AccessRequest;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\JobTitle;
use App\Models\Organisation;
use App\Models\OrganisationAdministrator;
use App\Models\OrganisationLicense;
use App\Models\OrganisationRole;
use App\Models\OrganisationRolePermission;
use App\Models\SodRule;
use App\Models\SodViolation;
use App\Models\Subscription;
use App\Models\Taxpayer;
use App\Models\User;
use App\Models\WorkflowAssignment;
use App\Models\WorkflowInstance;
use Database\Seeders\LicensePlanSeeder;
use Database\Seeders\OrganisationAdministratorRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers App\Services\Administration\AdministrationSnapshotService
 * (ported from lib/data/control-plane-repository.ts's
 * getAdministrationSnapshot) -- the fixed-list dashboard aggregate every
 * GET-list route across all five of Phase 12's own sub-domain slices
 * bundles into. Closes out control-plane-repository.ts entirely.
 */
class AdministrationSnapshotTest extends TestCase
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

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, owner: User} */
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
        OrganisationLicense::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'subscription_id' => $subscription->id,
            'license_plan_id' => 'plan-pilot-professional-v1', 'state' => 'ACTIVE', 'state_version' => 1,
            'effective_from' => now()->subMonth(), 'effective_to' => null, 'retention_policy' => 'NON_DESTRUCTIVE_TAX_RETENTION', 'updated_at' => now(),
        ]);
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Owner", 'email' => strtolower($vatNumber).'-owner@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'owner');
    }

    private function openReview(User $actor): void
    {
        $this->actingAs($actor)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson('/api/v1/access-reviews')
            ->assertStatus(201);
    }

    public function test_the_full_snapshot_aggregates_every_sub_domain_and_each_slice_route_returns_only_its_own_section(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-SNAP-0001');
        $this->openReview($ctx['owner']);

        $branch = Branch::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'code' => 'HQ', 'name' => 'Head Office',
            'address' => '1 Test Street, Windhoek', 'status' => 'ACTIVE', 'is_head_office' => true,
        ]);
        $department = Department::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'code' => 'FIN', 'name' => 'Finance',
            'parent_department_id' => null, 'status' => 'ACTIVE', 'created_at' => now(),
        ]);
        $jobTitle = JobTitle::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'code' => 'ACC', 'name' => 'Accountant',
            'description' => 'Handles day to day accounting.', 'status' => 'ACTIVE', 'created_at' => now(),
        ]);
        $employee = Employee::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'user_id' => null, 'employee_number' => 'EMP-0001',
            'full_name' => 'Jane Employee', 'email' => 'jane-0001@test.test', 'position_id' => null, 'job_title_id' => $jobTitle->id,
            'department_id' => $department->id, 'business_unit_id' => null, 'branch_id' => $branch->id, 'manager_employee_id' => null,
            'status' => 'ACTIVE', 'invited_at' => now(), 'activated_at' => now(), 'terminated_at' => null, 'last_activity_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $role = OrganisationRole::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'name' => 'Reconciliation Reviewer',
            'description' => 'Read-only VAT reconciliation access.', 'version' => 1, 'branch_scope' => '[]', 'approval_limit_cents' => null,
            'status' => 'ACTIVE', 'created_by' => $ctx['owner']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        OrganisationRolePermission::create([
            'id' => (string) Str::uuid(), 'organisation_role_id' => $role->id, 'permission_code' => 'exceptions:read',
            'record_scope' => 'ORGANISATION', 'effect' => 'ALLOW', 'created_at' => now(),
        ]);
        $subject = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Access Subject', 'email' => 'subject-0001@test.test', 'password' => bcrypt('password'),
            'role' => 'TAXPAYER_STAFF', 'taxpayer_id' => $ctx['taxpayer']->id, 'status' => 'ACTIVE',
        ]);
        AccessRequest::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'requested_by' => $ctx['owner']->id,
            'subject_user_id' => $subject->id, 'organisation_role_id' => $role->id, 'justification' => 'Needs reconciliation visibility for month-end.',
            'status' => 'PENDING_MANAGER', 'requested_at' => now(), 'completed_at' => null,
        ]);
        $administrator = OrganisationAdministrator::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'user_id' => $ctx['owner']->id, 'employee_id' => null,
            'administrator_role_code' => 'PRIMARY', 'scope' => json_encode(['organisation_id' => $ctx['organisation']->id]), 'is_primary' => true,
            'status' => 'ACTIVE', 'effective_from' => now(), 'effective_to' => null, 'appointed_by' => $ctx['owner']->id,
            'approval_reference' => json_encode([]),
        ]);

        $workflowId = (string) Str::uuid();
        $versionId = (string) Str::uuid();
        DB::table('workflows')->insert([
            'id' => $workflowId, 'organisation_id' => $ctx['organisation']->id, 'name' => 'Purchase Approval', 'domain_action' => 'PURCHASE_REQUEST',
            'status' => 'ACTIVE', 'created_by' => $ctx['owner']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('workflow_versions')->insert([
            'id' => $versionId, 'workflow_id' => $workflowId, 'organisation_id' => $ctx['organisation']->id, 'version_number' => 1,
            'status' => 'PUBLISHED', 'definition_hash' => hash('sha256', 'test'), 'definition' => '{}', 'effective_from' => now(),
            'published_by' => $ctx['owner']->id, 'approved_by' => $ctx['owner']->id, 'published_at' => now(), 'retired_at' => null, 'created_at' => now(),
        ]);
        $instance = WorkflowInstance::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'workflow_version_id' => $versionId,
            'resource_type' => 'PURCHASE_REQUEST', 'resource_id' => 'pr-1', 'initiated_by' => $ctx['owner']->id, 'status' => 'IN_PROGRESS',
            'current_node_key' => 'approve', 'context_snapshot' => '{}', 'started_at' => now(), 'completed_at' => null,
        ]);
        $assignment = WorkflowAssignment::create([
            'id' => (string) Str::uuid(), 'workflow_instance_id' => $instance->id, 'node_key' => 'approve', 'assigned_user_id' => $subject->id,
            'assigned_role_id' => null, 'status' => 'PENDING', 'due_at' => null, 'assigned_at' => now(),
        ]);
        $sodRule = SodRule::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'code' => 'NO_SELF_APPROVAL',
            'name' => 'No self approval', 'action_set' => json_encode(['CREATE', 'APPROVE']), 'scope' => 'ALL_PROTECTED_WORKFLOWS',
            'mandatory' => true, 'status' => 'ACTIVE', 'effective_from' => now()->subDay(), 'created_at' => now(),
        ]);
        SodViolation::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $ctx['organisation']->id, 'sod_rule_id' => $sodRule->id,
            'actor_id' => $ctx['owner']->id, 'resource_type' => 'WORKFLOW_ASSIGNMENT', 'resource_id' => $assignment->id,
            'status' => 'OPEN', 'evidence' => json_encode(['code' => 'SELF_APPROVAL_DENIED']), 'detected_at' => now(), 'resolved_at' => null,
        ]);
        DB::table('security_events')->insert([
            'id' => (string) Str::uuid(), 'event_type' => 'AUTHENTICATION_FAILED', 'severity' => 'MEDIUM', 'actor_id' => null,
            'source_token' => 'test', 'correlation_id' => (string) Str::uuid(), 'action' => 'LOGIN', 'outcome' => 'DENIED',
            'details' => '{}', 'occurred_at' => now(),
        ]);

        $full = $this->actingAs($ctx['owner'])->getJson('/api/v1/administration');
        $full->assertStatus(200)
            ->assertJsonPath('organisation.id', $ctx['organisation']->id)
            ->assertJsonPath('license.pricingConfigured', false)
            ->assertJsonPath('structures.branches', 1)
            ->assertJsonPath('structures.departments', 1)
            ->assertJsonPath('structures.job_titles', 1)
            ->assertJsonPath('security.open_sod_violations', 1)
            ->assertJsonPath('security.failed_logins_30d', 1)
            ->assertJsonPath('integrations.payments', 'DISABLED')
            ->assertJsonCount(1, 'employees')
            ->assertJsonCount(1, 'roles')
            ->assertJsonCount(1, 'workflows')
            ->assertJsonCount(1, 'tasks')
            ->assertJsonCount(1, 'accessRequests')
            ->assertJsonCount(1, 'accessReviews')
            ->assertJsonCount(1, 'administrators');
        $this->assertSame($employee->id, $full->json('employees.0.id'));
        $this->assertSame('exceptions:read', $full->json('roles.0.permissions'));
        $this->assertSame('PENDING_MANAGER', $full->json('accessRequests.0.status'));
        $this->assertSame($administrator->id, $full->json('administrators.0.id'));

        // Every other slice route slices this same snapshot down to only
        // its own fields, matching the source's own per-route shape.
        $employees = $this->actingAs($ctx['owner'])->getJson('/api/v1/organisations/employees');
        $employees->assertStatus(200);
        $this->assertSame(['organisation', 'employees'], array_keys($employees->json()));

        $roles = $this->actingAs($ctx['owner'])->getJson('/api/v1/organisations/roles');
        $this->assertSame(['organisation', 'roles'], array_keys($roles->json()));

        $administrators = $this->actingAs($ctx['owner'])->getJson('/api/v1/organisations/administrators');
        $this->assertSame(['organisation', 'administrators'], array_keys($administrators->json()));

        $license = $this->actingAs($ctx['owner'])->getJson('/api/v1/licensing/license');
        $this->assertSame(['organisation', 'license', 'entitlements'], array_keys($license->json()));

        $requests = $this->actingAs($ctx['owner'])->getJson('/api/v1/access-requests');
        $this->assertSame(['organisation', 'requests', 'reviews'], array_keys($requests->json()));

        $reviews = $this->actingAs($ctx['owner'])->getJson('/api/v1/access-reviews');
        $this->assertSame(['organisation', 'reviews'], array_keys($reviews->json()));

        $workflows = $this->actingAs($ctx['owner'])->getJson('/api/v1/workflows');
        $this->assertSame(['organisation', 'workflows', 'tasks'], array_keys($workflows->json()));
    }

    public function test_each_slice_route_is_gated_by_its_own_specific_permission_not_a_shared_one(): void
    {
        $ctx = $this->makeLicensedOrganisation('VAT-SNAP-0002');
        $this->openReview($ctx['owner']);
        // TAXPAYER_VIEWER holds none of employees:read/roles:read/
        // administration:read/access-governance:read/workflows:read
        // (confirmed against Permissions::ROLE_PERMISSIONS/
        // CONTROL_PLANE_PERMISSIONS).
        $viewer = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Viewer', 'email' => 'viewer-0002@test.test', 'password' => bcrypt('password'),
            'role' => 'TAXPAYER_VIEWER', 'taxpayer_id' => $ctx['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($viewer)->getJson('/api/v1/administration')->assertStatus(403);
        $this->actingAs($viewer)->getJson('/api/v1/organisations/employees')->assertStatus(403);
        $this->actingAs($viewer)->getJson('/api/v1/organisations/roles')->assertStatus(403);
        $this->actingAs($viewer)->getJson('/api/v1/organisations/administrators')->assertStatus(403);
        $this->actingAs($viewer)->getJson('/api/v1/access-requests')->assertStatus(403);
        $this->actingAs($viewer)->getJson('/api/v1/access-reviews')->assertStatus(403);
        $this->actingAs($viewer)->getJson('/api/v1/workflows')->assertStatus(403);
    }
}
