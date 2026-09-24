<?php

namespace App\Integrations\Itas;

/**
 * Ported from lib/integrations/itas.ts's ItasIdentityPort. In the source
 * this is gated by an `integration_connections` row (Module 10 Phase A's
 * generic connector model) -- Module 10 is migrated now, and multi-tenant
 * SaaS pivot phase 5 (2026-09-24) is the upgrade this interface's own
 * doc comment always asked for: every method now takes the acting
 * organisation, and `App\Integrations\Itas\UnavailableItasIdentityAdapter`
 * (via `App\Integrations\TenantScopedIntegrationLookup`) resolves that
 * organisation's own `integration_connections` row (falling back to the
 * platform-wide one), not a single hardcoded-unavailable global state.
 *
 * `$organisationId` is nullable and optional -- several call sites
 * genuinely have no single organisation in scope (a brand-new taxpayer
 * mid-registration, a national-scope actor's platform-wide snapshot) and
 * pass `null`, which resolves against the platform-wide connection only,
 * matching this port's own pre-phase-5 behaviour exactly for those cases.
 *
 * Still genuinely, provably unreachable today for every organisation
 * (seeded REQUIRES_AUTHORITY_CONTRACT/DISABLED platform-wide, with no
 * code path that can ever move any row to CONFIGURED/OPERATIONAL) -- not
 * a permanently-throwing placeholder pretending to be pluggable, and
 * `verifyTaxpayer()`/`submitVatReturn()` below still always throw even
 * once a connection is administratively CONFIGURED, since no live
 * connector implementation (real or mock) exists for ITAS at all. See
 * `ItasIntegrationUnavailableException`'s own doc comment for that
 * distinction.
 *
 * submitVatReturn was added for the VAT-return-generation prerequisite
 * (docs/MIGRATION_MATRIX.md's Phase 9/11 rows) -- same stub shape as
 * verifyTaxpayer above, for the identical reason.
 */
interface ItasIdentityPort
{
    /** @return array{provider: string, configured: bool, state: string, capabilities: list<string>} */
    public function status(?string $organisationId = null): array;

    /**
     * @param array{vat_number: string, tin: string, company_registration_number: ?string, correlation_id: string} $request
     * @return array{request_reference: string, verified: bool, response_hash: string, checked_at: string, expires_at: ?string}
     *
     * @throws ItasIntegrationUnavailableException
     */
    public function verifyTaxpayer(array $request, ?string $organisationId = null): array;

    /**
     * @param array{request_reference: string, taxpayer_vat_number: string, period_code: string, return_version: int, payload_hash: string, boxes: list<array{code: string, amount_cents: int}>, correlation_id: string} $request
     * @return array{provider_reference: string, status: string, response_hash: string, submitted_at: string}
     *
     * @throws ItasIntegrationUnavailableException
     */
    public function submitVatReturn(array $request, ?string $organisationId = null): array;
}
