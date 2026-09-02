<?php

namespace App\Support\Compliance;

use App\Exceptions\ComplianceResourceException;
use App\Models\Taxpayer;
use App\Models\User;
use App\Support\Access\TenantScope;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Ported from lib/data/compliance-repository.ts's resolveTaxpayer -- a
 * national-scope actor may act on any requested taxpayer (defaulting to
 * "taxpayer id is required" if none given); a taxpayer-scoped actor is
 * always confined to their own taxpayer and an explicit request for a
 * different one is denied. Distinct from App\Support\Business\
 * OrganisationResolver (which resolves an *organisation* from an optional
 * query param, defaulting to "any/the caller's own"): here a taxpayer id
 * is a required part of the command payload itself (most compliance
 * commands are officer-initiated against a named taxpayer, not a
 * taxpayer's own self-service action against their own implicit scope).
 */
class TaxpayerResolver
{
    /** @return array{taxpayer_id: string, organisation_id: string, legal_name: string, vat_number: string} */
    public function resolve(User $actor, ?string $requestedTaxpayerId): array
    {
        $taxpayerId = TenantScope::isNational($actor) ? $requestedTaxpayerId : $actor->taxpayer_id;
        if (! $taxpayerId) {
            throw new ComplianceResourceException('A taxpayer id is required for this command.');
        }
        if (! TenantScope::isNational($actor) && $requestedTaxpayerId && $requestedTaxpayerId !== $actor->taxpayer_id) {
            throw new AuthorizationException('The requested taxpayer is outside your authorised scope.');
        }

        $taxpayer = Taxpayer::with('organisation')->find($taxpayerId);
        $organisation = $taxpayer?->organisation;
        if (! $taxpayer || ! $organisation || $organisation->status !== 'ACTIVE') {
            throw new ComplianceResourceException('The taxpayer does not resolve to an active organisation.', 404);
        }

        return ['taxpayer_id' => $taxpayer->id, 'organisation_id' => $organisation->id, 'legal_name' => $taxpayer->legal_name, 'vat_number' => $taxpayer->vat_number];
    }
}
