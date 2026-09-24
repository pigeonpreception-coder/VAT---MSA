<?php

namespace App\Integrations\Etariff;

/**
 * User's own explicit request: the Foreign Invoices screen must
 * autonomously pull a taxpayer's own foreign-invoice declarations from
 * NamRA's E-Tariff border system (the system that captures import/export
 * duties actually paid at the border), so the VAT audit of a foreign
 * invoice is cross-authenticated against that independent record rather
 * than trusting the taxpayer's own submission alone.
 *
 * Mirrors `App\Integrations\Itas\ItasIdentityPort`'s own shape, its own
 * documented honesty, and -- as of multi-tenant SaaS pivot phase 5
 * (2026-09-24) -- its own tenant-scoped `integration_connections` lookup:
 * `$organisationId` resolves the *calling* organisation's own connection
 * row (falling back to the platform-wide one), via
 * `App\Integrations\TenantScopedIntegrationLookup`, the same mechanism
 * `ItasIdentityPort` now uses. `pullDeclarations()` still always throws
 * regardless of what that lookup finds: there is no real E-Tariff
 * technical contract, credentials, or live connector (real or mock) for
 * this integration today (the same position ITAS itself is in) --
 * `UnavailableEtariffAdapter` is therefore the only implementation bound
 * in `AppServiceProvider`, and it never fabricates data. Upgrade to a
 * real HTTP-backed adapter once NamRA's own border/customs systems team
 * confirms a real API contract to call.
 */
interface EtariffPort
{
    /** @return array{provider: string, configured: bool, state: string, capabilities: list<string>} */
    public function status(?string $organisationId = null): array;

    /**
     * @param array{taxpayer_vat_number: string, tin: ?string, correlation_id: string} $request
     * @return list<array{declaration_number: string, customs_office: ?string, supplier_name: string, country_of_origin: string, currency: string, customs_value_cents: int, import_vat_cents: int, declaration_date: string, etariff_reference: string}>
     *
     * @throws EtariffIntegrationUnavailableException
     */
    public function pullDeclarations(array $request, ?string $organisationId = null): array;
}
