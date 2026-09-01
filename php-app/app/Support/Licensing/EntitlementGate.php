<?php

namespace App\Support\Licensing;

use App\Domain\Licensing\AccessReviewWindow;
use App\Domain\Licensing\EntitlementEvaluator;
use App\Models\AccessReview;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Ported from lib/data/control-plane-repository.ts's
 * assertEntitledOperation -- the internal cross-cutting entitlement gate
 * every organisation-administration/employees write command (Phase 12
 * slice 2) calls before doing anything else. Deliberately not ported in
 * Phase 12 slice 1 (Licensing & Entitlements) since nothing there needed
 * it; built now because inviteEmployee/activateEmployee/
 * appointAdministrator/createOrganisationRole/grantCapability/
 * terminateEmployee all genuinely require it, `ADMIN_WRITE` gate included.
 */
class EntitlementGate
{
    /**
     * @return array{organisation: Organisation, license: array<string, mixed>}
     */
    public static function assert(User $actor, string $featureKey, string $operationClass, int $requested, ?string $requestedOrganisationId): array
    {
        $organisation = LicenseResolver::resolveOrganisation($actor, $requestedOrganisationId);
        $license = LicenseResolver::getLicense($organisation);
        $entitlement = collect(LicenseResolver::getEntitlements($license))->firstWhere('feature_key', $featureKey);

        $evaluation = EntitlementEvaluator::evaluate([
            'licenseState' => $license['state'], 'featureKey' => $featureKey, 'featureEnabled' => (bool) ($entitlement['enabled'] ?? false),
            'operationClass' => $operationClass, 'limit' => $entitlement['limit_value'] ?? null,
            'used' => $entitlement['used_value'] ?? 0, 'reserved' => $entitlement['reserved_value'] ?? 0, 'requested' => $requested,
        ]);
        if (! $evaluation['allowed']) {
            throw new AuthorizationException("{$evaluation['code']}: {$evaluation['reason']}");
        }

        if ($operationClass === 'ADMIN_WRITE') {
            $window = AccessReviewWindow::current();
            $currentReview = AccessReview::where('organisation_id', $organisation->id)->where('review_type', 'QUARTERLY')
                ->where('period_start', $window['periodStart'])->whereIn('status', ['OPEN', 'COMPLETED'])->first();
            if (! $currentReview || ($currentReview->status === 'OPEN' && $currentReview->due_at->isPast())) {
                throw new AuthorizationException("QUARTERLY_ACCESS_REVIEW_REQUIRED: Open or complete the {$window['key']} access review before privileged organisation changes.");
            }
        }

        return ['organisation' => $organisation, 'license' => $license];
    }
}
