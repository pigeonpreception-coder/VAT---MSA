<?php

namespace App\Services\Licensing;

use App\Domain\Licensing\LicensingValidator;
use App\Exceptions\LicensingValidationException;
use App\Models\LicenseEvent;
use App\Models\LicensePlan;
use App\Models\Organisation;
use App\Models\OrganisationLicense;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Support\Access\TenantScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/control-plane-repository.ts's getEntitlementsSnapshot/
 * getUsageSnapshot/changeLicenseState/upgradeLicense -- the Licensing &
 * Entitlements slice of Phase 12 (portals/licensing/governance).
 * `assertEntitledOperation` (the internal cross-cutting entitlement gate
 * other admin commands call before a privileged write) is deliberately not
 * ported this slice: grepped and confirmed no route calls it directly, and
 * its own `ADMIN_WRITE` branch depends on `access_reviews` (Access
 * governance -- a separate, still-unbuilt slice of this same phase). It
 * belongs with whichever slice actually ports the admin-write commands
 * that call it, not this one.
 */
class LicensingService
{
    /** @return array<string, mixed> */
    public function entitlementsSnapshot(User $actor, ?string $requestedOrganisationId): array
    {
        $organisation = $this->resolveOrganisation($actor, $requestedOrganisationId);
        $license = $this->getLicense($organisation);

        return [
            'organisation' => $this->presentOrganisation($organisation),
            'license' => array_merge($this->presentLicense($license), ['price' => null, 'pricing_configured' => false]),
            'entitlements' => $this->getEntitlements($license),
        ];
    }

    /** @return array<string, mixed> */
    public function usageSnapshot(User $actor, ?string $requestedOrganisationId): array
    {
        $organisation = $this->resolveOrganisation($actor, $requestedOrganisationId);
        $license = $this->getLicense($organisation);
        $usage = DB::table('license_usage')->where('organisation_license_id', $license['id'])
            ->orderBy('metric_key')
            ->get(['metric_key', 'period_key', 'used_value', 'reserved_value', 'version', 'updated_at']);

        return [
            'organisation' => $this->presentOrganisation($organisation), 'license_id' => $license['id'],
            'usage' => $usage->map(fn ($row) => [
                'metric_key' => $row->metric_key, 'period_key' => $row->period_key, 'used_value' => (int) $row->used_value,
                'reserved_value' => (int) $row->reserved_value, 'version' => (int) $row->version, 'updated_at' => $row->updated_at,
            ])->values()->all(),
        ];
    }

    private const STATE_EVENT_TYPE = ['ACTIVATE' => 'LICENSE_ACTIVATED', 'SUSPEND' => 'LICENSE_SUSPENDED', 'RENEW' => 'LICENSE_RENEWED'];

    /**
     * Activate/Suspend/Renew combined into one command (three actions, one
     * state-machine shape) since license_events already models every
     * transition as from_state/to_state/authority/reason.
     *
     * @return array{license_id: string, state: string, previous_state: string}
     */
    public function changeState(array $payload, User $actor, ?string $requestedOrganisationId): array
    {
        $input = LicensingValidator::stateChange($payload);
        $organisation = $this->resolveOrganisation($actor, $requestedOrganisationId);
        $license = $this->getLicense($organisation);
        LicensingValidator::assertStateTransition($input['action'], $license['state']);
        $toState = $input['action'] === 'SUSPEND' ? 'SUSPENDED' : 'ACTIVE';
        $now = now();
        $eventType = self::STATE_EVENT_TYPE[$input['action']];

        DB::transaction(function () use ($license, $input, $toState, $organisation, $actor, $now, $eventType) {
            OrganisationLicense::where('id', $license['id'])->update(['state' => $toState, 'state_version' => DB::raw('state_version + 1'), 'updated_at' => $now]);
            LicenseEvent::create([
                'id' => (string) Str::uuid(), 'organisation_license_id' => $license['id'], 'organisation_id' => $organisation->id,
                'event_type' => $eventType, 'from_state' => $license['state'], 'to_state' => $toState,
                'authority' => $actor->id, 'reason' => $input['reason'], 'occurred_at' => $now,
            ]);
            if ($input['action'] === 'RENEW') {
                $currentEnd = Carbon::parse($license['current_period_end']);
                $base = $currentEnd->greaterThan($now) ? $currentEnd : $now->copy();
                $newStart = $base->copy();
                $newEnd = $base->copy()->addYear();
                Subscription::where('id', $license['subscription_id'])
                    ->update(['current_period_start' => $newStart->toDateString(), 'current_period_end' => $newEnd->toDateString(), 'updated_at' => $now]);
            }
            AuditService::append($actor, $eventType, 'ORGANISATION_LICENSE', $license['id'], [
                'organisationId' => $organisation->id, 'fromState' => $license['state'], 'toState' => $toState, 'reason' => $input['reason'],
            ], $now);
        });

        return ['license_id' => $license['id'], 'state' => $toState, 'previous_state' => $license['state']];
    }

    /**
     * A distinct plan-change operation, not a state transition. Closes the
     * current organisation_licenses row (effective_to=now) and inserts a
     * new one on the target plan -- a versioned history of plan changes,
     * never an in-place mutation.
     *
     * @return array{license_id: string, plan_code: string, plan_name: string, state: string}
     */
    public function upgrade(array $payload, User $actor, ?string $requestedOrganisationId): array
    {
        $input = LicensingValidator::upgrade($payload);
        $organisation = $this->resolveOrganisation($actor, $requestedOrganisationId);
        $license = $this->getLicense($organisation);
        if ($input['licensePlanCode'] === $license['plan_code']) {
            throw new LicensingValidationException('LICENSE_PLAN_UNCHANGED', 'The organisation is already on this licence plan.');
        }
        if (! in_array($license['state'], ['ACTIVE', 'TRIAL'], true)) {
            throw new LicensingValidationException('LICENSE_TRANSITION_INVALID', "Cannot upgrade a licence currently in state {$license['state']}.");
        }
        $targetPlan = LicensePlan::where('code', $input['licensePlanCode'])->where('status', 'ACTIVE')->orderByDesc('version')->first();
        if (! $targetPlan) {
            throw new LicensingValidationException('LICENSE_PLAN_NOT_FOUND', 'The requested licence plan is not available.');
        }

        $now = now();
        $newLicenseId = (string) Str::uuid();
        DB::transaction(function () use ($license, $organisation, $targetPlan, $newLicenseId, $now, $actor) {
            OrganisationLicense::where('id', $license['id'])->update(['effective_to' => $now]);
            OrganisationLicense::create([
                'id' => $newLicenseId, 'organisation_id' => $organisation->id, 'subscription_id' => $license['subscription_id'],
                'license_plan_id' => $targetPlan->id, 'state' => 'ACTIVE', 'state_version' => 1, 'effective_from' => $now,
                'effective_to' => null, 'grace_ends_at' => null, 'retention_policy' => $license['retention_policy'], 'updated_at' => $now,
            ]);
            LicenseEvent::create([
                'id' => (string) Str::uuid(), 'organisation_license_id' => $newLicenseId, 'organisation_id' => $organisation->id,
                'event_type' => 'LICENSE_PLAN_UPGRADED', 'from_state' => $license['state'], 'to_state' => 'ACTIVE', 'authority' => $actor->id,
                'reason' => "Upgraded from {$license['plan_code']} to {$targetPlan->code}", 'occurred_at' => $now,
            ]);
            AuditService::append($actor, 'LICENSE_PLAN_UPGRADED', 'ORGANISATION_LICENSE', $newLicenseId, [
                'organisationId' => $organisation->id, 'fromPlan' => $license['plan_code'], 'toPlan' => $targetPlan->code,
            ], $now);
        });

        return ['license_id' => $newLicenseId, 'plan_code' => $targetPlan->code, 'plan_name' => $targetPlan->name, 'state' => 'ACTIVE'];
    }

    /**
     * Ported from resolveOrganisation. A national-scope actor may pick any
     * active, *licensed* organisation (or the alphabetically-first one if
     * none is requested -- matching the source's own ORDER BY legal_name);
     * a taxpayer-scoped actor is always confined to their own organisation.
     * The source resolves an unspecified organisation for a taxpayer-scoped
     * actor from their current session membership (`actor.organisationId`);
     * this port's simpler session model (Phase 6) has no per-request
     * membership concept beyond `taxpayer_id`, so it falls back to that
     * taxpayer's own active organisation -- the same simplification
     * `App\Support\Business\OrganisationResolver` already established for
     * every other module reusing this exact resolution shape.
     */
    private function resolveOrganisation(User $actor, ?string $requestedOrganisationId): Organisation
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
    private function getLicense(Organisation $organisation): array
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
     * Ported from getEntitlements. `period_key IN ('2026-Q3','2026-08')` is
     * a hardcoded pair of literal period keys in the source itself, not
     * derived from the current date -- a genuine, pre-existing pilot-scope
     * limitation carried forward faithfully, not introduced by this port
     * (confirmed against lib/data/control-plane-repository.ts's own
     * getEntitlements, unchanged).
     *
     * @return list<array<string, mixed>>
     */
    private function getEntitlements(array $license): array
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

    /** @return array<string, mixed> */
    private function presentOrganisation(Organisation $organisation): array
    {
        return ['id' => $organisation->id, 'taxpayer_id' => $organisation->taxpayer_id, 'legal_name' => $organisation->legal_name];
    }

    /** @return array<string, mixed> */
    private function presentLicense(array $license): array
    {
        return [
            'id' => $license['id'], 'organisation_id' => $license['organisation_id'], 'plan_id' => $license['plan_id'],
            'plan_code' => $license['plan_code'], 'plan_name' => $license['plan_name'], 'plan_version' => (int) $license['plan_version'],
            'state' => $license['state'], 'retention_policy' => $license['retention_policy'],
            'current_period_start' => $license['current_period_start'], 'current_period_end' => $license['current_period_end'],
        ];
    }
}
