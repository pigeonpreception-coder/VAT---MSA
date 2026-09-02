<?php

namespace App\Services\Identity;

use App\Integrations\Itas\ItasIdentityPort;
use App\Models\User;
use App\Support\Access\TenantScope;
use Illuminate\Support\Facades\DB;

/**
 * Ported from lib/data/identity-repository.ts's
 * getIdentityFoundationSnapshot -- Module 1's own dashboard aggregate
 * (consumed by the source's `app/organisations/page.tsx` and the
 * `app/portal/{namra,namra-admin}/page.tsx` server components directly,
 * not through a dedicated `app/api/v1/**` route file the way every other
 * snapshot in this migration has been; exposed here as one anyway,
 * matching this migration's own established "every repository function
 * gets a JSON endpoint" convention). `providers`/`organisations`/
 * `registrations`/`access` are the source's own four parallel reads
 * (`Promise.all`), run sequentially here -- the same non-parallel
 * simplification `App\Services\Administration\AdministrationSnapshotService`
 * already established for its own ten-way version of the identical
 * pattern.
 */
class IdentityFoundationSnapshotService
{
    public function __construct(
        private readonly OrganisationService $organisations,
        private readonly RegistrationService $registrations,
        private readonly ItasIdentityPort $itas,
    ) {}

    /** @return array<string, mixed> */
    public function getSnapshot(User $user): array
    {
        $providers = DB::table('identity_providers')
            ->orderByRaw("CASE provider_key WHEN 'ITAS' THEN 1 WHEN 'SITES_WORKSPACE' THEN 2 ELSE 3 END")
            ->get(['provider_key', 'display_name', 'provider_type', 'authority_level', 'status', 'configuration_status', 'updated_at']);

        return [
            'providers' => $providers->map(fn ($row) => (array) $row)->all(),
            'organisations' => $this->organisations->list($user),
            'registrations' => $this->registrations->list($user),
            'access' => $this->accessCounts($user),
            'itas' => $this->itas->status(),
        ];
    }

    /**
     * A national-scope actor sees platform-wide counts; a taxpayer-scoped
     * actor sees only their own taxpayer's -- exactly matching the
     * source's own two separate SQL statements (reproduced here as four
     * separate counts each, rather than one combined multi-subquery
     * SELECT, matching the plain-count style
     * `AdministrationSnapshotService::getAdministrationSnapshot()`'s own
     * `structures`/`security` fields already established).
     *
     * @return array{active_users: int, active_identity_links: int, active_memberships: int, active_branches: int}
     */
    private function accessCounts(User $user): array
    {
        if (TenantScope::isNational($user)) {
            return [
                'active_users' => DB::table('users')->where('status', 'ACTIVE')->count(),
                'active_identity_links' => DB::table('identity_links')->where('status', 'ACTIVE')->count(),
                'active_memberships' => DB::table('organisation_memberships')->where('status', 'ACTIVE')->count(),
                'active_branches' => DB::table('branches')->where('status', 'ACTIVE')->count(),
            ];
        }

        $taxpayerId = $user->taxpayer_id ?? '__none__';

        return [
            'active_users' => DB::table('users')->where('status', 'ACTIVE')->where('taxpayer_id', $taxpayerId)->count(),
            'active_identity_links' => DB::table('identity_links as l')->join('users as u', 'u.id', '=', 'l.user_id')
                ->where('l.status', 'ACTIVE')->where('u.taxpayer_id', $taxpayerId)->count(),
            'active_memberships' => DB::table('organisation_memberships as m')->join('organisations as o', 'o.id', '=', 'm.organisation_id')
                ->where('m.status', 'ACTIVE')->where('o.taxpayer_id', $taxpayerId)->count(),
            'active_branches' => DB::table('branches as b')->join('organisations as o', 'o.id', '=', 'b.organisation_id')
                ->where('b.status', 'ACTIVE')->where('o.taxpayer_id', $taxpayerId)->count(),
        ];
    }
}
