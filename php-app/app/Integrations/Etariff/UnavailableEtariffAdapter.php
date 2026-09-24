<?php

namespace App\Integrations\Etariff;

use App\Integrations\TenantScopedIntegrationLookup;

/**
 * Multi-tenant SaaS pivot phase 5 (2026-09-24) -- see `EtariffPort`'s own
 * doc comment for the full context, and `App\Integrations\Itas\
 * UnavailableItasIdentityAdapter`'s own doc comment for why
 * `pullDeclarations()` still always throws regardless of what the
 * tenant-scoped lookup finds.
 */
class UnavailableEtariffAdapter implements EtariffPort
{
    use TenantScopedIntegrationLookup;

    public function status(?string $organisationId = null): array
    {
        $configured = $this->integrationConfigured($this->integrationConnection('ETARIFF', $organisationId));

        return [
            'provider' => 'ETARIFF',
            'configured' => $configured,
            'state' => $configured ? 'CONFIGURED_NO_LIVE_CONNECTOR' : 'REQUIRES_ETARIFF_CONFIRMATION',
            'capabilities' => ['FOREIGN_INVOICE_PULL'],
        ];
    }

    public function pullDeclarations(array $request, ?string $organisationId = null): array
    {
        throw new EtariffIntegrationUnavailableException(
            'foreign invoice declaration pull',
            $this->integrationConfigured($this->integrationConnection('ETARIFF', $organisationId)),
        );
    }
}
