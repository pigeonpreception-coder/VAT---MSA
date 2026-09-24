<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Multi-tenant SaaS pivot phase 5 (2026-09-24): `App\Models\
 * IntegrationConnection`'s own doc comment has long claimed "the four
 * pre-seeded government/banking/treasury connections (ITAS, BIPA,
 * bank-org1, treasury) already exist as rows here" -- true of none of
 * them until this seeder. Closes that gap for the two this phase's own
 * scope actually covers (ITAS, E-Tariff/ETARIFF); BIPA and the banking/
 * treasury connections remain unseeded, genuinely outside "generalize the
 * ITAS/E-Tariff port contracts" (see docs/MIGRATION_MATRIX.md's own phase
 * 5 entry).
 *
 * Both rows are platform-wide (`organisation_id` NULL) and use the same
 * free-text `configuration_status` convention `ServiceComponentSeeder`'s
 * own `component-itas` row already established
 * (`REQUIRES_*_CONTRACT`/`DISABLED`) -- deliberately outside
 * `IntegrationValidator::assertTransition`'s closed DRAFT/CONFIGURED/
 * SUSPENDED enum, per `IntegrationConnection`'s own doc comment, so
 * `IntegrationConnectionService::approve()` can never be the command that
 * flips either row live. `App\Integrations\Itas\
 * UnavailableItasIdentityAdapter`/`App\Integrations\Etariff\
 * UnavailableEtariffAdapter` (via `App\Integrations\
 * TenantScopedIntegrationLookup`) are what actually read these rows now,
 * per-organisation with this platform-wide row as the fallback -- before
 * this phase, no row existed for either provider at all, so the fallback
 * was silently "nothing found" rather than an honest, inspectable record.
 */
class IntegrationConnectionSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $connections = [
            [
                'id' => 'connection-itas', 'provider_key' => 'ITAS', 'category' => 'GOVERNMENT',
                'display_name' => 'ITAS statutory integration', 'capabilities' => json_encode(['IDENTITY_FEDERATION', 'TAXPAYER_VERIFICATION', 'RETURN_SUBMISSION']),
                'endpoint_reference' => null, 'credential_reference' => null,
                'configuration_status' => 'REQUIRES_ITAS_CONTRACT', 'operational_status' => 'DISABLED',
                'data_classification' => 'TAX_CONFIDENTIAL', 'last_health_check_at' => null, 'last_health_outcome' => null,
            ],
            [
                'id' => 'connection-etariff', 'provider_key' => 'ETARIFF', 'category' => 'GOVERNMENT',
                'display_name' => 'E-Tariff border/customs integration', 'capabilities' => json_encode(['FOREIGN_INVOICE_PULL']),
                'endpoint_reference' => null, 'credential_reference' => null,
                'configuration_status' => 'REQUIRES_ETARIFF_CONTRACT', 'operational_status' => 'DISABLED',
                'data_classification' => 'TAX_CONFIDENTIAL', 'last_health_check_at' => null, 'last_health_outcome' => null,
            ],
        ];
        foreach ($connections as $connection) {
            DB::table('integration_connections')->updateOrInsert(
                ['id' => $connection['id']],
                array_merge($connection, ['organisation_id' => null, 'created_at' => $now, 'updated_at' => $now]),
            );
        }
    }
}
