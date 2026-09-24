<?php

namespace App\Integrations;

use App\Models\IntegrationConnection;

/**
 * Multi-tenant SaaS pivot phase 5 (2026-09-24): shared by every
 * `Unavailable*Adapter` that now consults `integration_connections`
 * (Module 10 Phase A's already-migrated, already organisation-scoped
 * generic connector model -- `App\Services\Integration\
 * IntegrationConnectionService`) for a per-organisation connection before
 * falling back to the platform-wide row (`organisation_id` NULL), rather
 * than a single hardcoded-unavailable global state. See
 * `App\Integrations\Itas\ItasIdentityPort`/`App\Integrations\Etariff\
 * EtariffPort`'s own doc comments for why the command methods stay
 * unconditionally unable to execute a real call regardless of what this
 * lookup finds: `integration_connections` records administrative
 * configuration (a real government contract, credentials, an approved
 * row), never a live connector implementation -- no such HTTP client or
 * mock exists for either integration today (unlike
 * `App\Integrations\Payment\SandboxPaymentConnector`, whose own genuine
 * mock logic this deliberately does not attempt to mirror).
 *
 * A tenant-specific row always wins over the platform-wide one when both
 * exist, matching `IntegrationConnectionService::loadConnectionForActor()`'s
 * own ownership boundary: a tenant's own configuration is never shadowed
 * by a platform default once it exists.
 */
trait TenantScopedIntegrationLookup
{
    private function integrationConnection(string $providerKey, ?string $organisationId): ?IntegrationConnection
    {
        if ($organisationId !== null) {
            $tenantConnection = IntegrationConnection::where('provider_key', $providerKey)
                ->where('organisation_id', $organisationId)->first();
            if ($tenantConnection) {
                return $tenantConnection;
            }
        }

        return IntegrationConnection::where('provider_key', $providerKey)->whereNull('organisation_id')->first();
    }

    private function integrationConfigured(?IntegrationConnection $connection): bool
    {
        return $connection?->configuration_status === 'CONFIGURED' && $connection?->operational_status === 'OPERATIONAL';
    }
}
