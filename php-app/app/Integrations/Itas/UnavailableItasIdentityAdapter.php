<?php

namespace App\Integrations\Itas;

use App\Integrations\TenantScopedIntegrationLookup;

/**
 * Multi-tenant SaaS pivot phase 5 (2026-09-24) -- see
 * `ItasIdentityPort`'s own doc comment for the full context.
 * `status()`'s `configured`/`state` now genuinely vary by organisation
 * (real, per-tenant administrative data), but `verifyTaxpayer()`/
 * `submitVatReturn()` still always throw: even an administratively
 * CONFIGURED `integration_connections` row has no live connector (real
 * or mock) behind it to actually call. This class's own name still
 * describes what matters most -- no command can ever reach a real ITAS
 * system through it.
 */
class UnavailableItasIdentityAdapter implements ItasIdentityPort
{
    use TenantScopedIntegrationLookup;

    public function status(?string $organisationId = null): array
    {
        $configured = $this->integrationConfigured($this->integrationConnection('ITAS', $organisationId));

        return [
            'provider' => 'ITAS',
            'configured' => $configured,
            'state' => $configured ? 'CONFIGURED_NO_LIVE_CONNECTOR' : 'REQUIRES_ITAS_CONFIRMATION',
            'capabilities' => ['IDENTITY_FEDERATION', 'TAXPAYER_VERIFICATION', 'RETURN_SUBMISSION'],
        ];
    }

    public function verifyTaxpayer(array $request, ?string $organisationId = null): array
    {
        throw new ItasIntegrationUnavailableException(
            'taxpayer verification',
            $this->integrationConfigured($this->integrationConnection('ITAS', $organisationId)),
        );
    }

    public function submitVatReturn(array $request, ?string $organisationId = null): array
    {
        throw new ItasIntegrationUnavailableException(
            'VAT return submission',
            $this->integrationConfigured($this->integrationConnection('ITAS', $organisationId)),
        );
    }
}
