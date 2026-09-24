<?php

namespace Tests\Feature\MultiTenant;

use App\Integrations\Etariff\EtariffIntegrationUnavailableException;
use App\Integrations\Etariff\EtariffPort;
use App\Integrations\Itas\ItasIntegrationUnavailableException;
use App\Integrations\Itas\ItasIdentityPort;
use App\Models\IntegrationConnection;
use App\Models\Organisation;
use App\Models\Taxpayer;
use Database\Seeders\IntegrationConnectionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Multi-tenant SaaS pivot phase 5 (2026-09-24): covers
 * `App\Integrations\Itas\ItasIdentityPort`/`App\Integrations\Etariff\
 * EtariffPort`'s own generalization -- both now resolve a per-organisation
 * `integration_connections` row (falling back to the platform-wide one)
 * instead of a single hardcoded-unavailable global state. See
 * `App\Integrations\TenantScopedIntegrationLookup`'s own doc comment for
 * why `verifyTaxpayer()`/`submitVatReturn()`/`pullDeclarations()` still
 * always throw regardless of what that lookup finds -- this test file
 * follows `tests/Feature/Payment/PaymentConnectorTest.php`'s own
 * established two-halves pattern for exactly this shape: the real command
 * path (always blocked, for either reason) plus a clearly-labelled direct-
 * DB simulation of a hypothetical CONFIGURED connection (something no real
 * command can create) proving `status()`'s own per-tenant resolution and
 * the distinct "configured but no live connector" message are both sound.
 */
class TenantScopedIntegrationAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(IntegrationConnectionSeeder::class);
    }

    private function makeOrganisation(string $vatNumber): Organisation
    {
        $taxpayer = Taxpayer::create([
            'id' => (string) Str::uuid(), 'vat_number' => $vatNumber, 'tin' => "TIN-{$vatNumber}",
            'legal_name' => "{$vatNumber} Trading Co", 'taxpayer_type' => 'PRIVATE_COMPANY', 'vat_status' => 'ACTIVE',
            'return_frequency' => 'MONTHLY', 'address' => '1 Test Street', 'email' => strtolower($vatNumber).'@test.test',
        ]);

        return Organisation::create(['id' => (string) Str::uuid(), 'taxpayer_id' => $taxpayer->id, 'legal_name' => $taxpayer->legal_name, 'status' => 'ACTIVE']);
    }

    public function test_the_platform_wide_itas_and_etariff_rows_exist_from_the_seeder_alone(): void
    {
        $this->assertDatabaseHas('integration_connections', ['id' => 'connection-itas', 'provider_key' => 'ITAS', 'organisation_id' => null, 'configuration_status' => 'REQUIRES_ITAS_CONTRACT']);
        $this->assertDatabaseHas('integration_connections', ['id' => 'connection-etariff', 'provider_key' => 'ETARIFF', 'organisation_id' => null, 'configuration_status' => 'REQUIRES_ETARIFF_CONTRACT']);
    }

    public function test_status_and_every_command_stay_unavailable_with_only_the_seeded_platform_wide_row(): void
    {
        $organisation = $this->makeOrganisation('VAT-ITAS-0001');
        $itas = app(ItasIdentityPort::class);
        $etariff = app(EtariffPort::class);

        $this->assertFalse($itas->status($organisation->id)['configured']);
        $this->assertFalse($etariff->status($organisation->id)['configured']);
        $this->assertFalse($itas->status(null)['configured']);

        $this->expectException(ItasIntegrationUnavailableException::class);
        $itas->verifyTaxpayer(['vat_number' => 'VAT-ITAS-0001', 'tin' => 'TIN-1', 'company_registration_number' => null, 'correlation_id' => (string) Str::uuid()], $organisation->id);
    }

    public function test_a_tenant_specific_configured_connection_is_preferred_over_the_platform_wide_row(): void
    {
        $organisation = $this->makeOrganisation('VAT-ITAS-0002');
        // No real command can ever reach CONFIGURED/OPERATIONAL (see
        // IntegrationValidator::assertTransition's own doc comment on why
        // the seeded free-text status falls outside its closed enum) --
        // this direct write simulates the hypothetical state purely to
        // prove status()'s own resolution logic, matching
        // PaymentConnectorTest's own precedent for the identical shape.
        IntegrationConnection::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'provider_key' => 'ITAS', 'category' => 'GOVERNMENT',
            'display_name' => 'Tenant ITAS connection', 'capabilities' => json_encode(['TAXPAYER_VERIFICATION']),
            'configuration_status' => 'CONFIGURED', 'operational_status' => 'OPERATIONAL', 'data_classification' => 'TAX_CONFIDENTIAL',
        ]);

        $itas = app(ItasIdentityPort::class);
        $status = $itas->status($organisation->id);
        $this->assertTrue($status['configured']);
        $this->assertSame('CONFIGURED_NO_LIVE_CONNECTOR', $status['state']);

        // A different organisation, and the platform-wide default, must
        // both still see the unconfigured platform-wide row -- one
        // tenant's own configuration never leaks to another.
        $otherOrganisation = $this->makeOrganisation('VAT-ITAS-0003');
        $this->assertFalse($itas->status($otherOrganisation->id)['configured']);
        $this->assertFalse($itas->status(null)['configured']);
    }

    public function test_verify_taxpayer_still_throws_even_once_configured_but_with_a_distinct_reason(): void
    {
        $organisation = $this->makeOrganisation('VAT-ITAS-0004');
        IntegrationConnection::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'provider_key' => 'ITAS', 'category' => 'GOVERNMENT',
            'display_name' => 'Tenant ITAS connection', 'capabilities' => json_encode(['TAXPAYER_VERIFICATION']),
            'configuration_status' => 'CONFIGURED', 'operational_status' => 'OPERATIONAL', 'data_classification' => 'TAX_CONFIDENTIAL',
        ]);

        $itas = app(ItasIdentityPort::class);
        try {
            $itas->verifyTaxpayer(['vat_number' => 'VAT-ITAS-0004', 'tin' => 'TIN-4', 'company_registration_number' => null, 'correlation_id' => (string) Str::uuid()], $organisation->id);
            $this->fail('Expected ItasIntegrationUnavailableException.');
        } catch (ItasIntegrationUnavailableException $e) {
            $this->assertStringContainsString('no live connector implementation exists', $e->getMessage());
            $this->assertStringNotContainsString('awaiting a confirmed technical contract', $e->getMessage());
        }
    }

    public function test_etariff_pull_declarations_still_throws_even_once_configured_but_with_a_distinct_reason(): void
    {
        $organisation = $this->makeOrganisation('VAT-ETARIFF-0001');
        IntegrationConnection::create([
            'id' => (string) Str::uuid(), 'organisation_id' => $organisation->id, 'provider_key' => 'ETARIFF', 'category' => 'GOVERNMENT',
            'display_name' => 'Tenant E-Tariff connection', 'capabilities' => json_encode(['FOREIGN_INVOICE_PULL']),
            'configuration_status' => 'CONFIGURED', 'operational_status' => 'OPERATIONAL', 'data_classification' => 'TAX_CONFIDENTIAL',
        ]);

        $etariff = app(EtariffPort::class);
        try {
            $etariff->pullDeclarations(['taxpayer_vat_number' => 'VAT-ETARIFF-0001', 'tin' => null, 'correlation_id' => (string) Str::uuid()], $organisation->id);
            $this->fail('Expected EtariffIntegrationUnavailableException.');
        } catch (EtariffIntegrationUnavailableException $e) {
            $this->assertStringContainsString('no live connector implementation exists', $e->getMessage());
        }
    }
}
