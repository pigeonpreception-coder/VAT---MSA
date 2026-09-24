<?php

namespace App\Integrations\Etariff;

/**
 * Multi-tenant SaaS pivot phase 5 (2026-09-24): `$connectionConfigured`
 * is new, defaulting to `false` so every pre-phase-5 call site keeps
 * throwing the exact same message as before -- see
 * `App\Integrations\Itas\ItasIntegrationUnavailableException`'s own doc
 * comment for the identical reasoning, mirrored here exactly.
 */
class EtariffIntegrationUnavailableException extends \RuntimeException
{
    public function __construct(string $capability = 'foreign invoice declaration pull', bool $connectionConfigured = false)
    {
        parent::__construct($connectionConfigured
            ? "E-Tariff {$capability} has an approved connection but no live connector implementation exists for this provider yet."
            : "E-Tariff {$capability} is awaiting a confirmed technical contract with NamRA's border/customs systems and is not available in this environment.");
    }
}
