<?php

namespace App\Models\Scopes;

use App\Support\Access\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Phase 7's reusable Eloquent global scope -- the automatic-enforcement
 * counterpart to App\Support\Access\TenantScope's manual assertion. Every
 * organisation-scoped service built so far (Phases 8-12) already gets this
 * right by hand: each one resolves the actor's own organisation via
 * App\Support\Business\OrganisationResolver (or an equivalent inline
 * branch on TenantScope::isNational) and adds its own
 * `->where('organisation_id', ...)` to every query -- so this scope is not
 * closing a security gap (SECURITY_GAP_ASSESSMENT.md Section 3 already
 * found none), it is a defense-in-depth backstop: a model that opts in via
 * App\Models\Concerns\BelongsToOrganisation can never accidentally leak a
 * cross-tenant row through a query some future change forgets to scope by
 * hand.
 *
 * Behaviour, matching every existing service's own manual branch exactly:
 * - No authenticated actor (artisan commands, seeders, and the many
 *   existing tests that build fixtures with direct `Model::create()` calls
 *   outside a request) -- no filter. There is no actor to scope against,
 *   and fixture setup routinely spans multiple organisations.
 * - A national-scope actor (`TenantScope::isNational`) -- no filter,
 *   exactly like every service's own `if (! isNational) { ...scope... }`
 *   branch already does.
 * - A taxpayer-scoped actor -- filtered to the one organisation their own
 *   `taxpayer_id` resolves to (`organisations.taxpayer_id` is UNIQUE, so
 *   this is always at most one row), via a subquery rather than a second
 *   round trip cached on the request.
 * - A user with neither (no taxpayer_id and not a national role -- not a
 *   shape any seeded role produces today, but not one this scope will
 *   silently trust either) -- matches nothing, rather than leaking every
 *   tenant's rows to an actor this scope cannot place.
 */
class OrganisationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! Auth::check()) {
            return;
        }
        $user = Auth::user();
        if (TenantScope::isNational($user)) {
            return;
        }
        if ($user->taxpayer_id === null) {
            $builder->whereRaw('1 = 0');

            return;
        }
        $builder->whereIn($model->qualifyColumn('organisation_id'), function ($query) use ($user) {
            $query->select('id')->from('organisations')->where('taxpayer_id', $user->taxpayer_id);
        });
    }
}
