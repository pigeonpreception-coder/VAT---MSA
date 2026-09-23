<?php

namespace App\Support\Business;

use App\Exceptions\BusinessResourceException;
use App\Models\BusinessParty;
use App\Models\CounterpartyTrustProfile;

/**
 * Ported from lib/data/business-repository.ts's requirePartyRelationship's
 * own trust check (the second half of that function, after the active-
 * relationship lookup it already had) -- 05-security/
 * issue3-counterparty-trust-boundary.md's transaction gate. Pulled out
 * into its own shared helper because the source's single
 * requirePartyRelationship is, in this port, three call sites
 * (App\Services\Business\QuotationService::requirePartyRelationship,
 * App\Services\Business\ExpenseService::requireSupplierRelationship,
 * App\Services\Business\ProjectService::requireCustomerRelationship) --
 * each keeps its own existing active-relationship lookup and error
 * exactly as-is, then calls here once that lookup has already confirmed
 * an active BusinessParty row.
 */
class CounterpartyTrustGate
{
    public static function require(BusinessParty $party, string $label, bool $requireActiveTaxRegistration = false): void
    {
        $trust = CounterpartyTrustProfile::where('business_party_id', $party->id)->first();
        $current = (bool) ($trust?->isCurrent());
        $authorityTrusted = $trust && $trust->trust_status === 'AUTHORITY_VERIFIED' && $current;
        $syntheticTrusted = self::syntheticEnabled() && $trust && $trust->trust_status === 'SYNTHETIC_VALID'
            && $trust->provider_environment === 'SYNTHETIC_TEST' && $current;
        if (! $authorityTrusted && ! $syntheticTrusted) {
            throw new BusinessResourceException("{$label} is not currently trusted for new transactions. Complete an approved counterparty verification first.", 422);
        }
        if ($requireActiveTaxRegistration && $trust?->tax_registration_status !== 'ACTIVE') {
            throw new BusinessResourceException("{$label} does not have current ACTIVE tax-registration evidence for a tax-bearing transaction.", 422);
        }
    }

    /**
     * Ported from the source's own syntheticEnabled (duplicated identically
     * in requirePartyRelationship and syntheticallyVerifyBusinessParty) --
     * translated to Laravel's environment idiom rather than the source's
     * VAT_MSA_ENVIRONMENT/NODE_ENV/VITEST combination, which this port has
     * no equivalent of: enabled in local/testing always; enabled in
     * staging only behind an explicit config flag
     * (`services.vat_msa.enable_synthetic_counterparty_trust`, backed by
     * the VAT_MSA_ENABLE_SYNTHETIC_COUNTERPARTY_TRUST env var); always
     * disabled in production. Unlike App\Services\AuthorityGovernance\
     * AuthorityGovernanceService::requireLocalWritesEnabled (which
     * deliberately collapsed the source's own staging branch down to
     * "disabled" for simplicity), this keeps the staging opt-in: the
     * whole point of Issue 3's synthetic path is to let staging rehearse
     * the real workflow before AUTHORITY_VERIFIED exists, and collapsing
     * it away would remove exactly the capability being built here.
     */
    public static function syntheticEnabled(): bool
    {
        if (app()->environment('production')) {
            return false;
        }

        return ! app()->environment('staging') || (bool) config('services.vat_msa.enable_synthetic_counterparty_trust');
    }
}
