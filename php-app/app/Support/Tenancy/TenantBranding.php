<?php

namespace App\Support\Tenancy;

use App\Models\User;

/**
 * Multi-tenant SaaS pivot phase 4 (2026-09-24): the single resolution point
 * every Blade view's currency/authority display text goes through, in
 * place of the ~150 mechanical hardcoded 'NAD'/'N$'/'NamRA' literals this
 * phase swept out of them. Registered as a global view composer (see
 * AppServiceProvider::boot()) so every view gets these variables without
 * each controller having to pass them explicitly.
 *
 * A guest, a national-scope actor (no `taxpayer_id` at all -- see
 * User::isNationalScope()'s own doc comment), or a taxpayer whose
 * Organisation row somehow doesn't exist all resolve to today's only
 * tenant's values -- the same fail-to-NamRA/NAD/N$ default
 * Organisation's own resolver methods use, so every page a platform-side
 * user (NamRA staff, pilot admin, security/audit roles) sees keeps
 * looking exactly as it does today.
 */
class TenantBranding
{
    /** @return array{currencyCode: string, currencySymbol: string, authorityShortName: string, authorityName: string} */
    public static function forUser(?User $user): array
    {
        $organisation = $user?->organisation();

        return [
            'currencyCode' => $organisation?->currencyCode() ?? 'NAD',
            'currencySymbol' => $organisation?->currencySymbol() ?? 'N$',
            // Prose branding text ("what NamRA owes") -- see
            // Organisation::taxAuthorityShortName()'s own doc comment for
            // why this isn't the uppercase technical `code`.
            'authorityShortName' => $organisation?->taxAuthorityShortName() ?? 'NamRA',
            'authorityName' => $organisation?->taxAuthorityName() ?? 'Namibia Revenue Agency',
        ];
    }
}
