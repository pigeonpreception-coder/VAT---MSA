<?php

namespace Tests\Feature\Saas;

use App\Models\SaasApplication;
use App\Models\SaasProvider;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers Module 10 Phase C: SaaS provider onboarding
 * (App\Services\Saas\SaasService, ported from lib/data/saas-repository.ts)
 * -- RegisterProvider/SubmitConformance/GetUsage, over both the JSON API
 * and the Blade view built alongside it. Real HTTP, real MySQL, no mocks.
 */
class SaasProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
    }

    private function makeDeveloperPartner(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Developer Partner '.Str::random(6), 'email' => 'dev-'.Str::random(10).'@saastest.test',
            'password' => bcrypt('password'), 'role' => 'DEVELOPER_PARTNER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function makeNationalActor(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Super Admin '.Str::random(6), 'email' => 'super-'.Str::random(10).'@saastest.test',
            'password' => bcrypt('password'), 'role' => 'SUPER_ADMIN', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    private function registrationPayload(array $overrides = []): array
    {
        return array_replace([
            'schema_version' => '1.0.0', 'provider_key' => 'ACMEPAY'.Str::upper(Str::random(4)),
            'legal_name' => 'Acme Payments Ltd', 'contact_email' => 'contact@acmepayments.test', 'category' => 'ACCOUNTING',
            'application' => [
                'name' => 'Acme Connector', 'description' => 'Connects Acme accounting to VAT-MSA.',
                'requested_capabilities' => ['INVOICE_SUBMIT', 'QUOTATION_READ'], 'endpoint_reference' => 'https://acme.example.test/webhook',
            ],
        ], $overrides);
    }

    private function registerProvider(User $actor, array $overrides = []): array
    {
        return $this->actingAs($actor)->postJson('/api/v1/saas-providers', $this->registrationPayload($overrides), ['Idempotency-Key' => 'saas-'.Str::random(20)])
            ->assertStatus(201)->json('resource');
    }

    public function test_registering_a_provider_creates_an_active_provider_and_a_registered_application(): void
    {
        $actor = $this->makeDeveloperPartner();

        $response = $this->actingAs($actor)->postJson('/api/v1/saas-providers', $this->registrationPayload(), ['Idempotency-Key' => 'saas-'.Str::random(20)]);

        $response->assertStatus(201)->assertJsonPath('resource.status', 'ACTIVE');
        $providerId = $response->json('resource.id');
        $this->assertDatabaseHas('saas_providers', ['id' => $providerId, 'registered_by' => $actor->id]);
        $this->assertDatabaseHas('saas_applications', ['saas_provider_id' => $providerId, 'name' => 'Acme Connector', 'status' => 'REGISTERED']);
    }

    public function test_registering_a_provider_with_a_duplicate_provider_key_is_a_conflict(): void
    {
        $actor = $this->makeDeveloperPartner();
        $payload = $this->registrationPayload();
        $this->actingAs($actor)->postJson('/api/v1/saas-providers', $payload, ['Idempotency-Key' => 'saas-'.Str::random(20)])->assertStatus(201);

        $response = $this->actingAs($actor)->postJson('/api/v1/saas-providers', $payload, ['Idempotency-Key' => 'saas-'.Str::random(20)]);

        $response->assertStatus(409);
        $this->assertDatabaseCount('saas_providers', 1);
    }

    public function test_registering_a_provider_with_an_invalid_category_is_a_validation_error(): void
    {
        $actor = $this->makeDeveloperPartner();

        $response = $this->actingAs($actor)->postJson('/api/v1/saas-providers', $this->registrationPayload(['category' => 'NOT_A_CATEGORY']), ['Idempotency-Key' => 'saas-'.Str::random(20)]);

        $response->assertStatus(422);
    }

    public function test_registering_a_provider_with_a_non_https_endpoint_is_a_validation_error(): void
    {
        $actor = $this->makeDeveloperPartner();
        $payload = $this->registrationPayload();
        $payload['application']['endpoint_reference'] = 'http://insecure.example.test/webhook';

        $response = $this->actingAs($actor)->postJson('/api/v1/saas-providers', $payload, ['Idempotency-Key' => 'saas-'.Str::random(20)]);

        $response->assertStatus(422);
    }

    public function test_a_replayed_idempotency_key_returns_the_same_resource_not_a_second_registration(): void
    {
        $actor = $this->makeDeveloperPartner();
        $payload = $this->registrationPayload();
        $key = 'saas-replay-'.Str::random(20);

        $first = $this->actingAs($actor)->postJson('/api/v1/saas-providers', $payload, ['Idempotency-Key' => $key]);
        $second = $this->actingAs($actor)->postJson('/api/v1/saas-providers', $payload, ['Idempotency-Key' => $key]);

        $first->assertStatus(201);
        $second->assertStatus(201)->assertJsonPath('resource.id', $first->json('resource.id'));
        $this->assertDatabaseCount('saas_providers', 1);
    }

    public function test_a_passed_sandbox_conformance_run_is_immediately_granted(): void
    {
        $actor = $this->makeDeveloperPartner();
        $provider = $this->registerProvider($actor);
        $applicationId = $provider['application']['id'];

        $response = $this->actingAs($actor)->postJson("/api/v1/saas-applications/{$applicationId}/conformance-runs", [
            'schema_version' => '1.0.0', 'environment' => 'SANDBOX',
            'tested_capabilities' => ['INVOICE_SUBMIT'], 'acknowledged_events' => ['InvoiceCreated', 'InvoiceCertified'],
        ], ['Idempotency-Key' => 'conf-'.Str::random(20)]);

        $response->assertStatus(201)->assertJsonPath('resource.outcome', 'PASSED');
        $this->assertDatabaseHas('saas_environment_approvals', ['saas_application_id' => $applicationId, 'environment' => 'SANDBOX', 'status' => 'GRANTED']);
    }

    public function test_a_conformance_run_testing_an_unrequested_capability_fails_and_is_denied(): void
    {
        $actor = $this->makeDeveloperPartner();
        $provider = $this->registerProvider($actor);
        $applicationId = $provider['application']['id'];

        $response = $this->actingAs($actor)->postJson("/api/v1/saas-applications/{$applicationId}/conformance-runs", [
            'schema_version' => '1.0.0', 'environment' => 'SANDBOX',
            'tested_capabilities' => ['SOMETHING_NEVER_REQUESTED'], 'acknowledged_events' => ['InvoiceCreated'],
        ], ['Idempotency-Key' => 'conf-'.Str::random(20)]);

        $response->assertStatus(201)->assertJsonPath('resource.outcome', 'FAILED');
        $this->assertDatabaseHas('saas_environment_approvals', ['saas_application_id' => $applicationId, 'environment' => 'SANDBOX', 'status' => 'DENIED']);
    }

    public function test_a_conformance_run_acknowledging_an_unknown_event_fails(): void
    {
        $actor = $this->makeDeveloperPartner();
        $provider = $this->registerProvider($actor);
        $applicationId = $provider['application']['id'];

        $response = $this->actingAs($actor)->postJson("/api/v1/saas-applications/{$applicationId}/conformance-runs", [
            'schema_version' => '1.0.0', 'environment' => 'SANDBOX',
            'tested_capabilities' => ['INVOICE_SUBMIT'], 'acknowledged_events' => ['NotARealEvent'],
        ], ['Idempotency-Key' => 'conf-'.Str::random(20)]);

        $response->assertStatus(201)->assertJsonPath('resource.outcome', 'FAILED');
    }

    public function test_a_production_conformance_run_without_a_prior_passed_sandbox_run_fails(): void
    {
        $actor = $this->makeDeveloperPartner();
        $provider = $this->registerProvider($actor);
        $applicationId = $provider['application']['id'];

        $response = $this->actingAs($actor)->postJson("/api/v1/saas-applications/{$applicationId}/conformance-runs", [
            'schema_version' => '1.0.0', 'environment' => 'PRODUCTION',
            'tested_capabilities' => ['INVOICE_SUBMIT'], 'acknowledged_events' => ['InvoiceCreated'],
        ], ['Idempotency-Key' => 'conf-'.Str::random(20)]);

        $response->assertStatus(201)->assertJsonPath('resource.outcome', 'FAILED');
        $this->assertDatabaseHas('saas_environment_approvals', ['saas_application_id' => $applicationId, 'environment' => 'PRODUCTION', 'status' => 'DENIED']);
    }

    public function test_a_passed_production_conformance_run_after_a_passed_sandbox_run_awaits_authority_not_granted(): void
    {
        $actor = $this->makeDeveloperPartner();
        $provider = $this->registerProvider($actor);
        $applicationId = $provider['application']['id'];
        $this->actingAs($actor)->postJson("/api/v1/saas-applications/{$applicationId}/conformance-runs", [
            'schema_version' => '1.0.0', 'environment' => 'SANDBOX',
            'tested_capabilities' => ['INVOICE_SUBMIT'], 'acknowledged_events' => ['InvoiceCreated'],
        ], ['Idempotency-Key' => 'conf-'.Str::random(20)])->assertStatus(201)->assertJsonPath('resource.outcome', 'PASSED');

        $response = $this->actingAs($actor)->postJson("/api/v1/saas-applications/{$applicationId}/conformance-runs", [
            'schema_version' => '1.0.0', 'environment' => 'PRODUCTION',
            'tested_capabilities' => ['INVOICE_SUBMIT'], 'acknowledged_events' => ['InvoiceCreated'],
        ], ['Idempotency-Key' => 'conf-'.Str::random(20)]);

        $response->assertStatus(201)->assertJsonPath('resource.outcome', 'PASSED');
        $this->assertDatabaseHas('saas_environment_approvals', ['saas_application_id' => $applicationId, 'environment' => 'PRODUCTION', 'status' => 'AWAITING_AUTHORITY']);
    }

    public function test_another_developer_partner_cannot_submit_conformance_for_someone_elses_application(): void
    {
        $actor = $this->makeDeveloperPartner();
        $other = $this->makeDeveloperPartner();
        $provider = $this->registerProvider($actor);
        $applicationId = $provider['application']['id'];

        $response = $this->actingAs($other)->postJson("/api/v1/saas-applications/{$applicationId}/conformance-runs", [
            'schema_version' => '1.0.0', 'environment' => 'SANDBOX',
            'tested_capabilities' => ['INVOICE_SUBMIT'], 'acknowledged_events' => ['InvoiceCreated'],
        ], ['Idempotency-Key' => 'conf-'.Str::random(20)]);

        $response->assertStatus(403);
    }

    public function test_a_national_scope_actor_may_submit_conformance_for_any_application(): void
    {
        $actor = $this->makeDeveloperPartner();
        $national = $this->makeNationalActor();
        $provider = $this->registerProvider($actor);
        $applicationId = $provider['application']['id'];

        $response = $this->actingAs($national)->postJson("/api/v1/saas-applications/{$applicationId}/conformance-runs", [
            'schema_version' => '1.0.0', 'environment' => 'SANDBOX',
            'tested_capabilities' => ['INVOICE_SUBMIT'], 'acknowledged_events' => ['InvoiceCreated'],
        ], ['Idempotency-Key' => 'conf-'.Str::random(20)]);

        $response->assertStatus(201);
    }

    public function test_get_usage_for_an_unknown_provider_is_not_found(): void
    {
        $actor = $this->makeDeveloperPartner();

        $response = $this->actingAs($actor)->getJson('/api/v1/saas-providers/'.Str::uuid().'/usage');

        $response->assertStatus(404);
    }

    public function test_get_usage_returns_the_provider_its_application_and_zero_connections(): void
    {
        $actor = $this->makeDeveloperPartner();
        $provider = $this->registerProvider($actor);

        $response = $this->actingAs($actor)->getJson("/api/v1/saas-providers/{$provider['id']}/usage");

        $response->assertStatus(200)
            ->assertJsonPath('provider.id', $provider['id'])
            ->assertJsonPath('connectionCount', 0)
            ->assertJsonCount(1, 'applications');
    }

    public function test_another_developer_partner_cannot_view_someone_elses_provider_usage(): void
    {
        $actor = $this->makeDeveloperPartner();
        $other = $this->makeDeveloperPartner();
        $provider = $this->registerProvider($actor);

        $response = $this->actingAs($other)->getJson("/api/v1/saas-providers/{$provider['id']}/usage");

        $response->assertStatus(403);
    }

    public function test_listing_scopes_a_tenant_actor_to_providers_they_themselves_registered(): void
    {
        $actor = $this->makeDeveloperPartner();
        $other = $this->makeDeveloperPartner();
        $this->registerProvider($actor);
        $this->registerProvider($other);

        $response = $this->actingAs($actor)->getJson('/api/v1/saas-providers');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('resources'));
    }

    public function test_listing_shows_every_provider_to_a_national_scope_actor(): void
    {
        $actor = $this->makeDeveloperPartner();
        $other = $this->makeDeveloperPartner();
        $this->registerProvider($actor);
        $this->registerProvider($other);

        $response = $this->actingAs($this->makeNationalActor())->getJson('/api/v1/saas-providers');

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(2, count($response->json('resources')));
    }

    public function test_the_blade_view_requires_authentication(): void
    {
        $this->get('/saas-providers')->assertRedirect('/login');
    }

    public function test_the_blade_view_renders_the_register_form_and_a_registered_provider(): void
    {
        $actor = $this->makeDeveloperPartner();
        $this->registerProvider($actor, ['legal_name' => 'Blade Visible Ltd']);

        $response = $this->actingAs($actor)->get('/saas-providers');

        $response->assertOk()->assertViewIs('saas-providers.index');
        $response->assertSee('Register a provider');
        $response->assertSee('Blade Visible Ltd');
    }

    public function test_registering_through_the_blade_view_creates_an_active_provider(): void
    {
        $actor = $this->makeDeveloperPartner();

        $response = $this->actingAs($actor)->post('/saas-providers', [
            'provider_key' => 'BLADEPROV'.Str::upper(Str::random(4)), 'legal_name' => 'Blade Provider Co',
            'contact_email' => 'contact@bladeprovider.test', 'category' => 'ERP',
            'application_name' => 'Blade Connector', 'application_description' => 'A connector registered through the Blade form.',
            'requested_capabilities' => 'INVOICE_SUBMIT, QUOTATION_READ', 'endpoint_reference' => 'https://blade.example.test/hook',
        ]);

        $response->assertRedirect(route('saas-providers.index'));
        $this->assertDatabaseHas('saas_providers', ['legal_name' => 'Blade Provider Co', 'status' => 'ACTIVE']);
    }

    public function test_the_blade_show_page_renders_usage_and_the_conformance_form(): void
    {
        $actor = $this->makeDeveloperPartner();
        $provider = $this->registerProvider($actor);

        $response = $this->actingAs($actor)->get("/saas-providers/{$provider['id']}");

        $response->assertOk()->assertViewIs('saas-providers.show');
        $response->assertSee('Acme Connector');
        $response->assertSee('Submit a conformance run');
    }

    public function test_submitting_conformance_through_the_blade_view_redirects_back_to_the_provider_with_a_status_message(): void
    {
        $actor = $this->makeDeveloperPartner();
        $provider = $this->registerProvider($actor);
        $applicationId = $provider['application']['id'];

        $response = $this->actingAs($actor)->post("/saas-applications/{$applicationId}/conformance-runs", [
            'provider_id' => $provider['id'], 'environment' => 'SANDBOX',
            'tested_capabilities' => 'INVOICE_SUBMIT', 'acknowledged_events' => 'InvoiceCreated',
        ]);

        $response->assertRedirect(route('saas-providers.show', $provider['id']));
        $this->assertDatabaseHas('saas_conformance_runs', ['saas_application_id' => $applicationId, 'outcome' => 'PASSED']);
    }
}
