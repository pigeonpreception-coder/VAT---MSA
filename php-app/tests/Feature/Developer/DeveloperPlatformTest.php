<?php

namespace Tests\Feature\Developer;

use App\Models\ApiClient;
use App\Models\CredentialRef;
use App\Models\Organisation;
use App\Models\Taxpayer;
use App\Models\TestRun;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers Module 10 Phase D's RotateCredential/RunConformance
 * (App\Services\Developer\DeveloperPlatformService), both its Blade
 * surface (App\Http\Controllers\Portal\DeveloperPortalController) and its
 * JSON API mirror (App\Http\Controllers\Developer\
 * DeveloperPlatformController) -- see the service's own doc comment for
 * why CreateClient/RevokeCredential are not re-ported: clients under test
 * here are issued through the pre-existing App\Services\Integration\
 * PosApiClientService, matching how this port actually creates
 * `api_clients` rows.
 */
class DeveloperPlatformTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    /** @return array{taxpayer: Taxpayer, organisation: Organisation, admin: User} */
    private function makeOrganisation(string $vatNumber): array
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street, Windhoek', 'email' => strtolower($vatNumber).'@test.test',
        ]);
        $organisation = Organisation::create([
            'id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE',
        ]);
        \App\Models\OrganisationCapability::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'capability' => 'SELLER',
            'status' => 'ACTIVE', 'effective_from' => now()->subDay(), 'created_at' => now(),
        ]);
        $admin = User::create([
            'id' => (string) Str::uuid(), 'name' => "{$vatNumber} Admin", 'email' => strtolower($vatNumber).'-admin@devplatform.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_ADMIN', 'taxpayer_id' => $taxpayer->id, 'status' => 'ACTIVE',
        ]);

        return compact('taxpayer', 'organisation', 'admin');
    }

    private function issueClient(User $admin, string $name = 'Rotation test client'): ApiClient
    {
        $this->actingAs($admin)->post('/invoice-management/local/credentials', ['name' => $name]);

        return ApiClient::where('name', $name)->firstOrFail();
    }

    public function test_rotate_and_conformance_routes_require_authentication(): void
    {
        $this->post('/portal/developer/clients/some-id/rotation')->assertRedirect('/login');
        $this->post('/portal/developer/clients/some-id/conformance-runs')->assertRedirect('/login');
    }

    public function test_a_role_without_developer_manage_is_denied(): void
    {
        $org = $this->makeOrganisation('VAT-DP-0001');
        $client = $this->issueClient($org['admin']);
        $accountant = User::create([
            'id' => (string) Str::uuid(), 'name' => 'No Developer Manage', 'email' => 'accountant@devplatform.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_ACCOUNTANT', 'taxpayer_id' => $org['taxpayer']->id, 'status' => 'ACTIVE',
        ]);

        $this->actingAs($accountant)->post("/portal/developer/clients/{$client->id}/rotation")->assertForbidden();
        $this->actingAs($accountant)->post("/portal/developer/clients/{$client->id}/conformance-runs")->assertForbidden();
    }

    public function test_an_admin_can_rotate_a_credential(): void
    {
        $org = $this->makeOrganisation('VAT-DP-0002');
        $client = $this->issueClient($org['admin']);
        $originalReference = $client->credential_reference;
        $originalActiveRef = CredentialRef::where('api_client_id', $client->id)->where('status', 'ACTIVE')->firstOrFail();

        $response = $this->actingAs($org['admin'])->post("/portal/developer/clients/{$client->id}/rotation");

        $response->assertRedirect('/portal/developer');
        $response->assertSessionHas('status');
        $client->refresh();
        $this->assertNotSame($originalReference, $client->credential_reference);
        $this->assertNotNull($client->last_rotated_at);
        $this->assertDatabaseHas('credential_refs', ['id' => $originalActiveRef->id, 'status' => 'ROTATED']);
        $this->assertSame(1, CredentialRef::where('api_client_id', $client->id)->where('status', 'ACTIVE')->count());
    }

    public function test_rotating_a_revoked_credential_is_rejected(): void
    {
        $org = $this->makeOrganisation('VAT-DP-0003');
        $client = $this->issueClient($org['admin']);
        $this->actingAs($org['admin'])->post("/invoice-management/local/credentials/{$client->id}/revocation", ['reason' => 'Decommissioned before rotation test']);

        $response = $this->actingAs($org['admin'])->post("/portal/developer/clients/{$client->id}/rotation");

        $response->assertSessionHasErrors('rotation');
        $client->refresh();
        $this->assertSame('REVOKED', $client->status);
    }

    public function test_double_submitting_the_same_rendered_rotation_form_rotates_only_once(): void
    {
        $org = $this->makeOrganisation('VAT-DP-0004');
        $client = $this->issueClient($org['admin']);
        $key = (string) Str::uuid();

        $this->actingAs($org['admin'])->post("/portal/developer/clients/{$client->id}/rotation", ['idempotency_key' => $key]);
        $this->actingAs($org['admin'])->post("/portal/developer/clients/{$client->id}/rotation", ['idempotency_key' => $key]);

        $this->assertSame(1, CredentialRef::where('api_client_id', $client->id)->where('status', 'ROTATED')->count());
        $this->assertSame(1, CredentialRef::where('api_client_id', $client->id)->where('status', 'ACTIVE')->count());
    }

    public function test_an_organisation_cannot_rotate_another_organisations_credential(): void
    {
        $orgA = $this->makeOrganisation('VAT-DP-0005');
        $orgB = $this->makeOrganisation('VAT-DP-0006');
        $client = $this->issueClient($orgA['admin']);

        $response = $this->actingAs($orgB['admin'])->post("/portal/developer/clients/{$client->id}/rotation");

        $response->assertSessionHasErrors('rotation');
        $client->refresh();
        $this->assertNull($client->last_rotated_at);
    }

    /**
     * PosApiClientService issues its POS-invoice-submission scope in this
     * port's own colon-separated permission-code style ('invoices:submit'),
     * not source's dot-separated 'resource.action' pattern SCOPES_DECLARED
     * enforces (see DeveloperPlatformService's own doc comment) -- so a
     * POS-issued credential's own conformance run always fails that one
     * check honestly, rather than the pattern being loosened to hide it.
     */
    public function test_conformance_on_a_pos_issued_credential_fails_scopes_declared(): void
    {
        $org = $this->makeOrganisation('VAT-DP-0007');
        $client = $this->issueClient($org['admin']);

        $response = $this->actingAs($org['admin'])->post("/portal/developer/clients/{$client->id}/conformance-runs");

        $response->assertRedirect('/portal/developer');
        $response->assertSessionHas('status', 'Conformance run complete: FAILED.');
        $run = TestRun::where('api_client_id', $client->id)->firstOrFail();
        $this->assertSame('FAILED', $run->outcome);
        $checks = $run->checkList();
        $this->assertCount(5, $checks);
        $scopesCheck = collect($checks)->firstWhere('code', 'SCOPES_DECLARED');
        $this->assertSame('FAIL', $scopesCheck['status']);
        $externalCheck = collect($checks)->firstWhere('code', 'EXTERNAL_CREDENTIAL_PROVISIONED');
        $this->assertSame('NOT_CONFIGURED', $externalCheck['status']);
    }

    public function test_conformance_passes_for_a_client_with_a_dot_scoped_scope_and_an_active_credential(): void
    {
        $org = $this->makeOrganisation('VAT-DP-0012');
        $clientId = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('api_clients')->insert([
            'id' => $clientId, 'organisation_id' => $org['organisation']->id, 'developer_account_id' => null,
            'name' => 'Sandbox Client', 'client_key' => 'client-'.Str::random(12), 'scopes' => json_encode(['invoices.read']),
            'credential_reference' => 'secret-ref-'.Str::random(8), 'status' => 'ACTIVE', 'rate_limit_profile' => 'SANDBOX',
            'created_by' => $org['admin']->id, 'created_at' => now(),
        ]);
        CredentialRef::create([
            'id' => (string) Str::uuid(), 'api_client_id' => $clientId, 'credential_reference' => 'secret-ref-active',
            'status' => 'ACTIVE', 'issued_by' => $org['admin']->id, 'issued_at' => now(),
        ]);

        $response = $this->actingAs($org['admin'])->post("/portal/developer/clients/{$clientId}/conformance-runs");

        $response->assertSessionHas('status', 'Conformance run complete: PASSED.');
        $run = TestRun::where('api_client_id', $clientId)->firstOrFail();
        $this->assertSame('PASSED', $run->outcome);
    }

    public function test_conformance_fails_for_a_revoked_client(): void
    {
        $org = $this->makeOrganisation('VAT-DP-0008');
        $client = $this->issueClient($org['admin']);
        $this->actingAs($org['admin'])->post("/invoice-management/local/credentials/{$client->id}/revocation", ['reason' => 'Revoked before conformance test']);

        $response = $this->actingAs($org['admin'])->post("/portal/developer/clients/{$client->id}/conformance-runs");

        $response->assertSessionHas('status', 'Conformance run complete: FAILED.');
        $run = TestRun::where('api_client_id', $client->id)->firstOrFail();
        $this->assertSame('FAILED', $run->outcome);
        $operationalCheck = collect($run->checkList())->firstWhere('code', 'CLIENT_OPERATIONAL');
        $this->assertSame('FAIL', $operationalCheck['status']);
    }

    public function test_double_submitting_the_same_rendered_conformance_form_records_only_one_run(): void
    {
        $org = $this->makeOrganisation('VAT-DP-0009');
        $client = $this->issueClient($org['admin']);
        $key = (string) Str::uuid();

        $this->actingAs($org['admin'])->post("/portal/developer/clients/{$client->id}/conformance-runs", ['idempotency_key' => $key]);
        $this->actingAs($org['admin'])->post("/portal/developer/clients/{$client->id}/conformance-runs", ['idempotency_key' => $key]);

        $this->assertSame(1, TestRun::where('api_client_id', $client->id)->count());
    }

    public function test_the_developer_portal_shows_the_conformance_column_after_a_run(): void
    {
        $org = $this->makeOrganisation('VAT-DP-0010');
        $client = $this->issueClient($org['admin']);
        $this->actingAs($org['admin'])->post("/portal/developer/clients/{$client->id}/conformance-runs");

        $response = $this->actingAs($org['admin'])->get('/portal/developer');

        $response->assertOk();
        $response->assertSee('0/1');
        $response->assertSee('Rotate credential', false);
        $response->assertSee('Run conformance', false);
    }

    public function test_the_json_api_can_rotate_and_run_conformance(): void
    {
        $org = $this->makeOrganisation('VAT-DP-0011');
        $client = $this->issueClient($org['admin']);

        $rotateResponse = $this->actingAs($org['admin'])->postJson("/api/v1/developer/clients/{$client->id}/rotation", [], ['Idempotency-Key' => (string) Str::uuid().(string) Str::uuid()]);
        $rotateResponse->assertOk()->assertJsonPath('client.id', $client->id);

        $conformanceResponse = $this->actingAs($org['admin'])->postJson("/api/v1/developer/clients/{$client->id}/conformance-runs", [], ['Idempotency-Key' => (string) Str::uuid().(string) Str::uuid()]);
        $conformanceResponse->assertStatus(201)->assertJsonPath('test_run.outcome', 'FAILED');
    }
}
