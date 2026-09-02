<?php

namespace App\Models\Concerns;

use App\Models\Scopes\OrganisationScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Opt-in trait for any Eloquent model with an `organisation_id` column --
 * registers App\Models\Scopes\OrganisationScope as a global scope on boot
 * (Eloquent auto-discovers `boot{TraitName}` on any model using the
 * trait). See that scope class's own doc comment for exactly what it does
 * and does not filter, and why.
 *
 * `withoutOrganisationScope()` is the escape hatch for the rare query that
 * must deliberately cross the boundary within a single request already
 * scoped to one actor (the scope's own national-actor/no-auth branches
 * already cover the common cases without needing this) -- e.g.
 * `BusinessParty::withoutOrganisationScope()->find($id)`.
 */
trait BelongsToOrganisation
{
    protected static function bootBelongsToOrganisation(): void
    {
        static::addGlobalScope(new OrganisationScope);
    }

    public function scopeWithoutOrganisationScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(OrganisationScope::class);
    }
}
