<?php

namespace App\Support\Business;

use App\Exceptions\BusinessResourceException;
use App\Models\Organisation;
use App\Models\User;
use App\Support\Access\TenantScope;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Ported from lib/data/business-repository.ts's resolveOrganisation --
 * every command in that file (and this phase's business services) scopes
 * to exactly one organisation, resolved from an optional
 * `?organisation_id=` query parameter: a national-scope actor may pick any
 * active organisation (or the lowest-id one, deterministically, if none is
 * requested); a taxpayer-scoped actor is always confined to their own
 * organisation and an explicit request for a different one is denied.
 */
class OrganisationResolver
{
    public function resolve(User $user, ?string $requestedOrganisationId): Organisation
    {
        if (TenantScope::isNational($user)) {
            $query = Organisation::where('status', 'ACTIVE');
            $organisation = $requestedOrganisationId
                ? $query->where('id', $requestedOrganisationId)->first()
                : $query->orderBy('id')->first();
            if (! $organisation) {
                throw new BusinessResourceException('No active organisation is available in the requested scope.', 404);
            }

            return $organisation;
        }

        $organisation = Organisation::where('taxpayer_id', $user->taxpayer_id ?? '__none__')->where('status', 'ACTIVE')->first();
        if (! $organisation) {
            throw new AuthorizationException('Your account is not assigned to an active taxpayer organisation.');
        }
        if ($requestedOrganisationId && $requestedOrganisationId !== $organisation->id) {
            throw new AuthorizationException('The requested organisation is outside your authorised scope.');
        }

        return $organisation;
    }
}
