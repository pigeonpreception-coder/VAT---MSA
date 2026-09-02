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
use App\Support\Licensing\LicenseResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ported from lib/data/control-plane-repository.ts's getEntitlementsSnapshot/
 * getUsageSnapshot/changeLicenseState/upgradeLicense -- the Licensing &
 * Entitlements slice of Phase 12 (portals/licensing/governance). Resolution
 * logic (resolveOrganisation/getLicense/getEntitlements) lives in
 * `App\Support\Licensing\LicenseResolver`, single-sourced with
 * `App\Support\Licensing\EntitlementGate::assertEntitledOperation`
 * (organisation administration/employees, Phase 12 slice 2) rather than
 * duplicated here.
 */
class LicensingService
{
    /** @return array<string, mixed> */
    public function entitlementsSnapshot(User $actor, ?string $requestedOrganisationId): array
    {
        $organisation = LicenseResolver::resolveOrganisation($actor, $requestedOrganisationId);
        $license = LicenseResolver::getLicense($organisation);

        return [
            'organisation' => $this->presentOrganisation($organisation),
            'license' => array_merge($this->presentLicense($license), ['price' => null, 'pricing_configured' => false]),
            'entitlements' => LicenseResolver::getEntitlements($license),
        ];
    }

    /** @return array<string, mixed> */
    public function usageSnapshot(User $actor, ?string $requestedOrganisationId): array
    {
        $organisation = LicenseResolver::resolveOrganisation($actor, $requestedOrganisationId);
        $license = LicenseResolver::getLicense($organisation);
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
        $organisation = LicenseResolver::resolveOrganisation($actor, $requestedOrganisationId);
        $license = LicenseResolver::getLicense($organisation);
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
        $organisation = LicenseResolver::resolveOrganisation($actor, $requestedOrganisationId);
        $license = LicenseResolver::getLicense($organisation);
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
