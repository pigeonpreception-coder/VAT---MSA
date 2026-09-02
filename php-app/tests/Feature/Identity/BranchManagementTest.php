<?php

namespace Tests\Feature\Identity;

use App\Models\Branch;
use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Ported from lib/data/identity-repository.ts's listBranches/createBranch/updateBranch and assignMembership. */
class BranchManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function ownerWithOrganisation(): array
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => 'VAT-BR-0001', 'tin' => 'TIN-BR-0001',
            'legal_name' => 'Branch Test Co', 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Branch Street', 'email' => 'finance@branch-test.test',
        ]);
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Owner', 'email' => 'owner@branch-test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);
        $organisation = Organisation::create(['id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE']);
        $headOffice = Branch::create(['id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'code' => 'HEAD', 'name' => 'Head Office', 'address' => $taxpayer->address, 'status' => 'ACTIVE', 'is_head_office' => true]);

        return [$owner, $organisation, $headOffice];
    }

    public function test_an_organisation_admin_can_create_a_new_branch(): void
    {
        [$owner, $organisation] = $this->ownerWithOrganisation();

        $response = $this->actingAs($owner)->postJson("/api/v1/organisations/{$organisation->id}/branches", [
            'code' => 'SW-01', 'name' => 'Swakopmund Branch', 'address' => '1 Coastal Road, Swakopmund',
        ]);

        $response->assertStatus(201)->assertJsonPath('branch.code', 'SW-01');
        $this->assertDatabaseHas('branches', ['organisation_id' => $organisation->id, 'code' => 'SW-01', 'is_head_office' => false]);
    }

    public function test_creating_a_branch_with_a_duplicate_code_is_a_conflict(): void
    {
        [$owner, $organisation] = $this->ownerWithOrganisation();

        $response = $this->actingAs($owner)->postJson("/api/v1/organisations/{$organisation->id}/branches", [
            'code' => 'HEAD', 'name' => 'Duplicate', 'address' => '1 Somewhere',
        ]);

        $response->assertStatus(409);
    }

    public function test_the_head_office_branch_cannot_be_deactivated(): void
    {
        [$owner, $organisation, $headOffice] = $this->ownerWithOrganisation();

        $response = $this->actingAs($owner)->patchJson("/api/v1/organisations/{$organisation->id}/branches/{$headOffice->id}", [
            'status' => 'INACTIVE',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas('branches', ['id' => $headOffice->id, 'status' => 'ACTIVE']);
    }

    public function test_a_non_head_office_branch_can_be_deactivated(): void
    {
        [$owner, $organisation] = $this->ownerWithOrganisation();
        $branch = Branch::create(['id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'code' => 'SW-02', 'name' => 'Coastal', 'address' => '1 Coastal Road', 'status' => 'ACTIVE', 'is_head_office' => false]);

        $response = $this->actingAs($owner)->patchJson("/api/v1/organisations/{$organisation->id}/branches/{$branch->id}", [
            'status' => 'INACTIVE',
        ]);

        $response->assertStatus(200)->assertJsonPath('branch.status', 'INACTIVE');
    }

    public function test_an_organisation_admin_can_assign_membership_to_an_existing_user(): void
    {
        [$owner, $organisation] = $this->ownerWithOrganisation();
        $newMember = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Staff Member', 'email' => 'staff@branch-test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_STAFF', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        $response = $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/organisations/{$organisation->id}/memberships", [
                'user_id' => $newMember->id, 'role_code' => 'TAXPAYER_STAFF',
            ]);

        $response->assertStatus(201)->assertJsonPath('membership.status', 'ACTIVE');
        $this->assertDatabaseHas('organisation_memberships', ['organisation_id' => $organisation->id, 'user_id' => $newMember->id, 'role_code' => 'TAXPAYER_STAFF']);
        $this->assertDatabaseHas('users', ['id' => $newMember->id, 'taxpayer_id' => $organisation->taxpayer_id]);
    }

    public function test_assigning_a_national_role_via_membership_is_rejected_by_validation(): void
    {
        [$owner, $organisation] = $this->ownerWithOrganisation();
        $newMember = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Staff Member', 'email' => 'staff2@branch-test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_STAFF', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);

        // PILOT_ADMIN is not in AssignMembershipRequest::ASSIGNABLE_ROLES -- privilege-escalation ceiling.
        $response = $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/organisations/{$organisation->id}/memberships", [
                'user_id' => $newMember->id, 'role_code' => 'PILOT_ADMIN',
            ]);

        $response->assertStatus(422);
    }
}
