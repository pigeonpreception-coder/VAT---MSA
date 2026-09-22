<?php

namespace Tests\Feature\Identity;

use App\Models\Taxpayer;
use App\Models\TaxpayerIdentifier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithStepUp;
use Tests\TestCase;

/**
 * Ported from lib/data/identity-repository.ts's correctTaxpayerIdentifier
 * and verifyTaxpayerIdentifiers.
 */
class TaxpayerIdentifierTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithStepUp;

    private function pilotAdmin(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Admin', 'email' => 'admin@test.test',
            'password' => bcrypt('password'), 'role' => 'NAMRA_SYSTEM_SUPPORT', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    /** @return array{taxpayer: Taxpayer, identifier: TaxpayerIdentifier, owner: User} */
    private function taxpayerWithActiveIdentifier(string $suffix, string $identifierType = 'VAT_NUMBER'): array
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => "VAT-{$suffix}", 'tin' => "TIN-{$suffix}",
            'legal_name' => "Identifier Target Co {$suffix}", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Identifier Street', 'email' => "finance-{$suffix}@identifier-target.test",
        ]);
        $identifier = TaxpayerIdentifier::create([
            'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'identifier_type' => $identifierType,
            'identifier_value' => $identifierType === 'VAT_NUMBER' ? $taxpayer->vat_number : $taxpayer->tin,
            'country' => 'NA', 'status' => 'ACTIVE', 'source' => 'MANUAL_REVIEW', 'verified_at' => now(),
            'created_at' => now(), 'version' => 1, 'effective_from' => now(),
        ]);
        $owner = User::create([
            'id' => (string) Str::uuid(), 'name' => 'Owner', 'email' => "owner-{$suffix}@identifier-target.test",
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return ['taxpayer' => $taxpayer, 'identifier' => $identifier, 'owner' => $owner];
    }

    // -- correctIdentifier ---------------------------------------------

    public function test_a_pilot_admin_can_correct_a_vat_number(): void
    {
        $admin = $this->pilotAdmin();
        $fixture = $this->taxpayerWithActiveIdentifier('CORR-0001');

        $response = $this->actingAs($admin)->withFreshStepUp()->postJson(
            "/api/v1/taxpayers/{$fixture['taxpayer']->id}/identifiers/{$fixture['identifier']->id}/correction",
            ['identifier_value' => 'vat-corr-0001-b', 'reason' => 'Corrected a data-entry typo in the VAT number.']
        );

        $response->assertStatus(200)
            ->assertJsonPath('correction.identifierValue', 'VAT-CORR-0001-B')
            ->assertJsonPath('correction.version', 2);
        $this->assertDatabaseHas('taxpayers', ['id' => $fixture['taxpayer']->id, 'vat_number' => 'VAT-CORR-0001-B']);
        $this->assertDatabaseHas('taxpayer_identifiers', ['id' => $fixture['identifier']->id, 'status' => 'SUPERSEDED']);
        $this->assertDatabaseHas('taxpayer_identifiers', [
            'taxpayer_id' => $fixture['taxpayer']->id, 'identifier_value' => 'VAT-CORR-0001-B', 'status' => 'ACTIVE',
            'previous_version_id' => $fixture['identifier']->id, 'version' => 2,
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'TAXPAYER_IDENTIFIER_CORRECTED']);
    }

    public function test_correcting_a_superseded_identifier_is_rejected(): void
    {
        $admin = $this->pilotAdmin();
        $fixture = $this->taxpayerWithActiveIdentifier('CORR-0002');
        $fixture['identifier']->update(['status' => 'SUPERSEDED']);

        $response = $this->actingAs($admin)->withFreshStepUp()->postJson(
            "/api/v1/taxpayers/{$fixture['taxpayer']->id}/identifiers/{$fixture['identifier']->id}/correction",
            ['identifier_value' => 'VAT-CORR-0002-B', 'reason' => 'Should be rejected, not the active version.']
        );

        $response->assertStatus(422)->assertJsonPath('errors.0.code', 'IDENTIFIER_NOT_ACTIVE');
    }

    public function test_correcting_a_non_correctable_identifier_type_is_rejected(): void
    {
        $admin = $this->pilotAdmin();
        $fixture = $this->taxpayerWithActiveIdentifier('CORR-0003', 'COMPANY_REGISTRATION_NUMBER');

        $response = $this->actingAs($admin)->withFreshStepUp()->postJson(
            "/api/v1/taxpayers/{$fixture['taxpayer']->id}/identifiers/{$fixture['identifier']->id}/correction",
            ['identifier_value' => 'CRN-CORR-0003-B', 'reason' => 'Should be rejected, wrong identifier type.']
        );

        $response->assertStatus(422)->assertJsonPath('errors.0.code', 'IDENTIFIER_TYPE_NOT_CORRECTABLE');
    }

    public function test_correcting_to_the_identical_value_is_rejected(): void
    {
        $admin = $this->pilotAdmin();
        $fixture = $this->taxpayerWithActiveIdentifier('CORR-0004');

        $response = $this->actingAs($admin)->withFreshStepUp()->postJson(
            "/api/v1/taxpayers/{$fixture['taxpayer']->id}/identifiers/{$fixture['identifier']->id}/correction",
            ['identifier_value' => mb_strtolower($fixture['identifier']->identifier_value), 'reason' => 'Same value, just re-cased.']
        );

        $response->assertStatus(422)->assertJsonPath('errors.0.code', 'IDENTIFIER_UNCHANGED');
    }

    public function test_correcting_to_a_value_another_active_identifier_already_holds_is_a_conflict(): void
    {
        $admin = $this->pilotAdmin();
        $fixture = $this->taxpayerWithActiveIdentifier('CORR-0005');
        $other = $this->taxpayerWithActiveIdentifier('CORR-0006');

        $response = $this->actingAs($admin)->withFreshStepUp()->postJson(
            "/api/v1/taxpayers/{$fixture['taxpayer']->id}/identifiers/{$fixture['identifier']->id}/correction",
            ['identifier_value' => $other['identifier']->identifier_value, 'reason' => 'Should conflict with another taxpayer.']
        );

        $response->assertStatus(409);
        $this->assertDatabaseHas('taxpayer_identifiers', ['id' => $fixture['identifier']->id, 'status' => 'ACTIVE']);
    }

    public function test_correction_without_a_fresh_step_up_is_locked(): void
    {
        $admin = $this->pilotAdmin();
        $fixture = $this->taxpayerWithActiveIdentifier('CORR-0007');

        $response = $this->actingAs($admin)->postJson(
            "/api/v1/taxpayers/{$fixture['taxpayer']->id}/identifiers/{$fixture['identifier']->id}/correction",
            ['identifier_value' => 'VAT-CORR-0007-B', 'reason' => 'Should be locked without step-up.']
        );

        $response->assertStatus(423);
        $this->assertDatabaseHas('taxpayer_identifiers', ['id' => $fixture['identifier']->id, 'status' => 'ACTIVE']);
    }

    public function test_a_taxpayer_owner_without_the_suspend_permission_cannot_correct_an_identifier(): void
    {
        $fixture = $this->taxpayerWithActiveIdentifier('CORR-0008');

        $response = $this->actingAs($fixture['owner'])->withFreshStepUp()->postJson(
            "/api/v1/taxpayers/{$fixture['taxpayer']->id}/identifiers/{$fixture['identifier']->id}/correction",
            ['identifier_value' => 'VAT-CORR-0008-B', 'reason' => 'Should be denied, owner lacks taxpayers:suspend.']
        );

        $response->assertStatus(403);
    }

    public function test_correcting_an_unknown_identifier_id_returns_not_found(): void
    {
        $admin = $this->pilotAdmin();
        $fixture = $this->taxpayerWithActiveIdentifier('CORR-0009');

        $response = $this->actingAs($admin)->withFreshStepUp()->postJson(
            "/api/v1/taxpayers/{$fixture['taxpayer']->id}/identifiers/".Str::uuid().'/correction',
            ['identifier_value' => 'VAT-CORR-0009-B', 'reason' => 'Identifier id does not exist.']
        );

        $response->assertStatus(422)->assertJsonPath('errors.0.code', 'IDENTIFIER_NOT_FOUND');
    }

    // -- verifyIdentifiers -----------------------------------------------

    public function test_a_taxpayer_owner_can_retrigger_verification_for_their_own_taxpayer(): void
    {
        $fixture = $this->taxpayerWithActiveIdentifier('VRFY-0001');

        // No step-up needed: this only attempts an external call.
        $response = $this->actingAs($fixture['owner'])->postJson("/api/v1/taxpayers/{$fixture['taxpayer']->id}/identifiers/verification");

        $response->assertStatus(200)
            ->assertJsonPath('verification.status', 'AWAITING_PROVIDER_CONTRACT')
            ->assertJsonPath('verification.provider', 'ITAS');
        $this->assertDatabaseHas('audit_events', ['action' => 'TAXPAYER_IDENTIFIER_VERIFICATION_ATTEMPTED', 'resource_id' => $fixture['taxpayer']->id]);
    }

    public function test_a_national_admin_can_verify_any_taxpayer(): void
    {
        $admin = $this->pilotAdmin();
        $fixture = $this->taxpayerWithActiveIdentifier('VRFY-0002');

        $response = $this->actingAs($admin)->postJson("/api/v1/taxpayers/{$fixture['taxpayer']->id}/identifiers/verification");

        $response->assertStatus(200)->assertJsonPath('verification.status', 'AWAITING_PROVIDER_CONTRACT');
    }

    public function test_a_taxpayer_owner_cannot_verify_a_different_taxpayer(): void
    {
        $fixture = $this->taxpayerWithActiveIdentifier('VRFY-0003');
        $other = $this->taxpayerWithActiveIdentifier('VRFY-0004');

        $response = $this->actingAs($other['owner'])->postJson("/api/v1/taxpayers/{$fixture['taxpayer']->id}/identifiers/verification");

        $response->assertStatus(403);
    }

    public function test_verifying_an_unknown_taxpayer_returns_not_found(): void
    {
        $admin = $this->pilotAdmin();

        $response = $this->actingAs($admin)->postJson('/api/v1/taxpayers/'.Str::uuid().'/identifiers/verification');

        $response->assertStatus(422)->assertJsonPath('errors.0.code', 'TAXPAYER_NOT_FOUND');
    }
}
