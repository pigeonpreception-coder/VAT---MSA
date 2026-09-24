<?php

namespace App\Integrations\Itas;

/**
 * Multi-tenant SaaS pivot phase 5 (2026-09-24): `$connectionConfigured`
 * is new, defaulting to `false` so every pre-phase-5 call site
 * (`new ItasIntegrationUnavailableException('taxpayer verification')`)
 * keeps throwing the exact same message as before. It exists to keep this
 * exception honest once a real per-organisation `integration_connections`
 * row is administratively CONFIGURED (see `UnavailableItasIdentityAdapter`):
 * "awaiting a confirmed technical contract" would be false at that point,
 * even though the command still cannot run -- there is still no live ITAS
 * connector (real or mock) anywhere in this codebase to call.
 */
class ItasIntegrationUnavailableException extends \RuntimeException
{
    public function __construct(string $capability = 'taxpayer verification', bool $connectionConfigured = false)
    {
        parent::__construct($connectionConfigured
            ? "ITAS {$capability} has an approved connection but no live connector implementation exists for this provider yet."
            : "ITAS {$capability} is awaiting a confirmed technical contract and is not available in this environment.");
    }
}
