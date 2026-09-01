<?php

namespace App\Services\Identity;

use App\Models\Organisation;
use App\Models\User;
use App\Support\Access\TenantScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

/** Ported from lib/data/identity-repository.ts's listOrganisations/getOrganisation. */
class OrganisationService
{
    public function list(User $user): Collection
    {
        $query = Organisation::query()->with('taxpayer')->withCount([
            'branches as branch_count' => fn ($q) => $q->where('status', 'ACTIVE'),
            'memberships as member_count' => fn ($q) => $q->where('status', 'ACTIVE'),
        ]);

        if (! TenantScope::isNational($user)) {
            $query->where('taxpayer_id', $user->taxpayer_id ?? '__none__');
        }

        return $query->orderBy('legal_name')->get();
    }

    public function get(User $user, string $organisationId): ?Organisation
    {
        $organisation = Organisation::with([
            'taxpayer', 'taxpayer.identifiers',
            'branches' => fn ($q) => $q->orderByDesc('is_head_office')->orderBy('name'),
            'memberships.user',
            'capabilities',
        ])->find($organisationId);

        if (! $organisation) {
            return null;
        }

        TenantScope::requireTaxpayer($user, $organisation->taxpayer_id);

        return $organisation;
    }

    /** @throws AuthorizationException */
    public function requireInScope(User $user, string $organisationId): Organisation
    {
        $organisation = Organisation::find($organisationId);
        if (! $organisation) {
            throw new \InvalidArgumentException('The organisation does not exist.');
        }
        TenantScope::requireTaxpayer($user, $organisation->taxpayer_id);
        return $organisation;
    }
}
