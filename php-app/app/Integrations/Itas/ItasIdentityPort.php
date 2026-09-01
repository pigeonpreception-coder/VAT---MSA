<?php

namespace App\Integrations\Itas;

/**
 * Ported from lib/integrations/itas.ts's ItasIdentityPort. In the source
 * this is gated by an `integration_connections` row (Module 10 Phase A's
 * generic connector model), seeded REQUIRES_AUTHORITY_CONTRACT/DISABLED
 * with no code path that can ever move it to CONFIGURED/OPERATIONAL --
 * i.e. genuinely, provably unreachable today, not a permanently-throwing
 * placeholder pretending to be pluggable. Module 10's own connector model
 * (integration_connections, saas_providers, etc.) is not migrated yet
 * (out of Phase 8's scope), so this stub currently always reports
 * unavailable unconditionally -- the identical real-world outcome the
 * source's own guard produces, just without the guard table to point at
 * yet. Upgrade to the full gated adapter when Module 10 is migrated.
 *
 * submitVatReturn was added for the VAT-return-generation prerequisite
 * (docs/MIGRATION_MATRIX.md's Phase 9/11 rows) -- same unconditional-
 * unavailable stub shape as verifyTaxpayer above, for the identical reason.
 */
interface ItasIdentityPort
{
    /** @return array{provider: string, configured: bool, state: string, capabilities: list<string>} */
    public function status(): array;

    /**
     * @param array{vat_number: string, tin: string, company_registration_number: ?string, correlation_id: string} $request
     * @return array{request_reference: string, verified: bool, response_hash: string, checked_at: string, expires_at: ?string}
     *
     * @throws ItasIntegrationUnavailableException
     */
    public function verifyTaxpayer(array $request): array;

    /**
     * @param array{request_reference: string, taxpayer_vat_number: string, period_code: string, return_version: int, payload_hash: string, boxes: list<array{code: string, amount_cents: int}>, correlation_id: string} $request
     * @return array{provider_reference: string, status: string, response_hash: string, submitted_at: string}
     *
     * @throws ItasIntegrationUnavailableException
     */
    public function submitVatReturn(array $request): array;
}
