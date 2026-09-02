<?php

namespace App\Support\Licensing;

use App\Models\Organisation;
use App\Models\User;
use App\Support\Access\TenantScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Ported from lib/data/control-plane-repository.ts's resolveOrganisation/
 * getLicense/getEntitlements -- single-sourced here rather than duplicated
 * (the source itself defines these once and every function in that same
 * file, including assertEntitledOperation, reuses them), exactly matching
 * `App\Support\Invoice\VatRuleResolver`'s own precedent for pulling a
 * source file's shared internal helpers out into their own reusable
 * class. Extracted from `App\Services\Licensing\LicensingService` (its
 * original home, Phase 12 slice 1) so `App\Support\Licensing\
 * EntitlementGate` (slice 2's `assertEntitledOperation`) can reuse the
 * identical resolution logic without duplicating it -- a pure refactor,
 * no behaviour change.
 */
class LicenseResolver
{
    /**
     * A national-scope actor may pick any active, *licensed* organisation
     * (or the alphabetically-first one if none is requested -- matching
     * the source's own ORDER BY legal_name); a taxpayer-scoped actor is
     * always confined to their own organisation. The source resolves an
     * unspecified organisation for a taxpayer-scoped actor from their
     * current session membership (`actor.organisationId`); this port's
     * simpler session model (Phase 6) has no per-request membership
     * concept beyond `taxpayer_id`, so it falls back to that taxpayer's
     * own active organisation -- the same simplification
     * `App\Support\Business\OrganisationResolver` already established for
     * every other module reusing this exact resolution shape.
     */
    public static function resolveOrganisation(User $actor, ?string $requestedOrganisationId): Organisation
    {
        if ($requestedOrganisationId) {
            $organisation = Organisation::where('id', $requestedOrganisationId)->where('status', 'ACTIVE')->first();
            if (! $organisation) {
                throw new AuthorizationException('The organisation scope is unavailable.');
            }
            if (! TenantScope::isNational($actor) && $organisation->taxpayer_id !== $actor->taxpayer_id) {
                throw new AuthorizationException('The requested organisation is outside your authorised scope.');
            }

            return $organisation;
        }
        if (! TenantScope::isNational($actor)) {
            $organisation = Organisation::where('taxpayer_id', $actor->taxpayer_id ?? '__none__')->where('status', 'ACTIVE')->first();
            if (! $organisation) {
                throw new AuthorizationException('An active organisation membership is required.');
            }

            return $organisation;
        }
        $organisation = Organisation::where('organisations.status', 'ACTIVE')
            ->join('organisation_licenses', 'organisation_licenses.organisation_id', '=', 'organisations.id')
            ->orderBy('organisations.legal_name')
            ->select('organisations.*')
            ->first();
        if (! $organisation) {
            throw new AuthorizationException('No licensed organisation is available in this environment.');
        }

        return $organisation;
    }

    /** @return array{id: string, organisation_id: string, subscription_id: string, plan_id: string, plan_code: string, plan_name: string, plan_version: int, state: string, retention_policy: string, current_period_start: string, current_period_end: string} */
    public static function getLicense(Organisation $organisation): array
    {
        $row = DB::table('organisation_licenses as l')
            ->join('license_plans as p', 'p.id', '=', 'l.license_plan_id')
            ->join('subscriptions as s', 's.id', '=', 'l.subscription_id')
            ->where('l.organisation_id', $organisation->id)
            ->orderByDesc('l.effective_from')
            ->select([
                'l.id', 'l.organisation_id', 'l.subscription_id', 'p.id as plan_id', 'p.code as plan_code', 'p.name as plan_name',
                'p.version as plan_version', 'l.state', 'l.retention_policy', 's.current_period_start', 's.current_period_end',
            ])->first();
        if (! $row) {
            throw new AuthorizationException('The organisation has no configured licence.');
        }

        return (array) $row;
    }

    /**
     * `period_key IN ('2026-Q3','2026-08')` is a hardcoded pair of literal
     * period keys in the source itself, not derived from the current
     * date -- a genuine, pre-existing pilot-scope limitation carried
     * forward faithfully, not introduced by this port (confirmed against
     * lib/data/control-plane-repository.ts's own getEntitlements,
     * unchanged).
     *
     * @return list<array<string, mixed>>
     */
    public static function getEntitlements(array $license): array
    {
        return DB::table('license_plan_entitlements as e')
            ->join('license_features as f', 'f.feature_key', '=', 'e.feature_key')
            ->leftJoin('license_usage as u', function ($join) use ($license) {
                $join->on('u.metric_key', '=', 'f.metric_key')
                    ->where('u.organisation_license_id', $license['id'])
                    ->whereIn('u.period_key', ['2026-Q3', '2026-08']);
            })
            ->where('e.license_plan_id', $license['plan_id'])
            ->orderBy('f.name')
            ->select([
                'e.feature_key', 'f.name', 'f.description', 'f.metric_key', 'e.enabled', 'e.limit_value',
                DB::raw('COALESCE(u.used_value, 0) as used_value'), DB::raw('COALESCE(u.reserved_value, 0) as reserved_value'),
            ])->get()->map(fn ($row) => [
                'feature_key' => $row->feature_key, 'name' => $row->name, 'description' => $row->description,
                'metric_key' => $row->metric_key, 'enabled' => (bool) $row->enabled,
                'limit_value' => $row->limit_value !== null ? (int) $row->limit_value : null,
                'used_value' => (int) $row->used_value, 'reserved_value' => (int) $row->reserved_value,
            ])->values()->all();
    }
}
