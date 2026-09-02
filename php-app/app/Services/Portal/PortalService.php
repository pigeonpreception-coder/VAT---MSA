<?php

namespace App\Services\Portal;

use App\Domain\Portal\PortalDefinitions;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Direct port of lib/portals.ts's getAvailablePortals/requirePortalAccess
 * -- Module 1 Buyer/Seller GetAvailablePortals. See PortalDefinitions'
 * own doc comment for why this genuinely separate file is closed out
 * alongside `getAdministrationSnapshot`/`searchWorkspace`.
 */
class PortalService
{
    /**
     * `capabilitySet` in the source: PILOT_ADMIN gets both BUYER and
     * SELLER unconditionally (a national actor isn't scoped to one
     * organisation's own held capabilities); every other role without a
     * taxpayer_id gets none (matching the source's own `!user.taxpayerId
     * ? new Set() : ...` short-circuit -- national NamRA/platform roles
     * never need a capability-gated portal anyway, since none of their
     * own portals declare one). Deliberately filters on the capability's
     * own effective_from/effective_to window -- stricter than
     * `NavigationService::accessContext()`'s own simpler organisation-
     * capability lookup, because this is a genuinely different source
     * function (`lib/portals.ts`'s own `capabilitySet`, not
     * `getNavigationAccessContext`'s), not an inconsistency to "fix".
     *
     * @return array<string, bool>
     */
    private function capabilitySet(User $actor): array
    {
        if ($actor->role === 'PILOT_ADMIN') {
            return ['BUYER' => true, 'SELLER' => true];
        }
        if (! $actor->taxpayer_id) {
            return [];
        }
        $now = now();
        $capabilities = DB::table('organisation_capabilities as c')
            ->join('organisations as o', 'o.id', '=', 'c.organisation_id')
            ->where('o.taxpayer_id', $actor->taxpayer_id)->where('o.status', 'ACTIVE')->where('c.status', 'ACTIVE')
            ->where('c.effective_from', '<=', $now)
            ->where(fn ($q) => $q->whereNull('c.effective_to')->orWhere('c.effective_to', '>', $now))
            ->pluck('c.capability');

        return array_fill_keys($capabilities->all(), true);
    }

    /** @return list<array<string, mixed>> */
    public function getAvailablePortals(User $actor): array
    {
        $capabilities = $this->capabilitySet($actor);

        return array_values(array_filter(
            PortalDefinitions::all(),
            fn ($portal) => PortalDefinitions::roleAllows($portal['key'], $actor->role, $capabilities),
        ));
    }
}
