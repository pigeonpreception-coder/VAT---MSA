<?php

namespace Tests\Feature\Identity;

use App\Models\Taxpayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Ported from lib/data/identity-repository.ts's suspendTaxpayer. */
class TaxpayerSuspensionTest extends TestCase
{
    use RefreshDatabase;

    private function pilotAdmin(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Admin', 'email' => 'admin@test.test',
            'password' => bcrypt('password'), 'role' => 'PILOT_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function activeTaxpayer(): Taxpayer
    {
        return Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => 'VAT-SUSP-0001', 'tin' => 'TIN-SUSP-0001',
            'legal_name' => 'Suspend Target Co', 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Suspend Street', 'email' => 'finance@suspend-target.test',
        ]);
    }

    public function test_a_pilot_admin_can_suspend_an_active_taxpayer(): void
    {
        $admin = $this->pilotAdmin();
        $taxpayer = $this->activeTaxpayer();

        $response = $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/taxpayers/{$taxpayer->id}/suspension", ['reason' => 'Flagged for compliance review.']);

        $response->assertStatus(200)->assertJsonPath('suspension.vatStatus', 'SUSPENDED');
        $this->assertDatabaseHas('taxpayers', ['id' => $taxpayer->id, 'vat_status' => 'SUSPENDED']);
        $this->assertDatabaseHas('audit_events', ['action' => 'TAXPAYER_SUSPENDED', 'resource_id' => $taxpayer->id]);
    }

    public function test_suspending_an_already_suspended_taxpayer_is_an_idempotent_no_op(): void
    {
        $admin = $this->pilotAdmin();
        $taxpayer = $this->activeTaxpayer();
        $taxpayer->update(['vat_status' => 'SUSPENDED']);

        $response = $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/taxpayers/{$taxpayer->id}/suspension", ['reason' => 'Repeat check, should be a no-op.']);

        $response->assertStatus(200)->assertJsonPath('suspension.vatStatus', 'SUSPENDED');
        // No-op means no second audit event for this action.
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_a_taxpayer_owner_without_the_suspend_permission_is_denied(): void
    {
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Owner', 'email' => 'owner@test.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
        $taxpayer = $this->activeTaxpayer();

        $response = $this->actingAs($owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->postJson("/api/v1/taxpayers/{$taxpayer->id}/suspension", ['reason' => 'Should be denied.']);

        $response->assertStatus(403);
        $this->assertDatabaseHas('taxpayers', ['id' => $taxpayer->id, 'vat_status' => 'ACTIVE']);
    }
}
