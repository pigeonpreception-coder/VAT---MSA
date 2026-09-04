<?php

namespace App\Http\Controllers\Licensing;

use App\Domain\Licensing\LicensingValidator;
use App\Exceptions\LicensingValidationException;
use App\Http\Controllers\Controller;
use App\Services\Licensing\LicensingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Real Blade UI for LicensingService (Phase 12 slice 1: licence
 * entitlements, usage, and state changes), alongside the JSON API surface
 * LicensingController already exposes -- see InvoiceViewController's own
 * doc comment for why this app keeps a dedicated Blade-rendering
 * controller next to each JSON one.
 *
 * `upgrade()` is deliberately not given a UI action: LicensePlanSeeder
 * seeds exactly one plan (PILOT_PROFESSIONAL) and nothing anywhere in this
 * migration ever creates a second one, so upgrade() can never actually
 * succeed here -- any call either targets the org's own current plan
 * (LICENSE_PLAN_UNCHANGED) or a plan that doesn't exist
 * (LICENSE_PLAN_NOT_FOUND). Building a button for an action that cannot
 * succeed against any real data in this environment would be the same
 * mistake already avoided for OfflineSyncService and Disputes' missing
 * decide path -- implying a capability that does not exist. `changeState()`
 * (activate/suspend/renew) has no such problem: it operates on the org's
 * existing licence and is fully exercisable.
 *
 * A genuine, necessary addition this slice required: neither
 * `subscriptions` nor an organisation's first `organisation_licenses` row
 * has any application write path anywhere in this migration (see each
 * table's own migration doc comment) -- every organisation in the dev
 * database, including every demo fixture used elsewhere in this
 * build-out, had no licence row at all until this slice's own
 * DemoSeeder addition, and `EntitlementGate`/`LicenseResolver` throw
 * "no configured licence" without one. Fixed by seeding a real
 * subscription + PILOT_PROFESSIONAL licence for the primary demo
 * organisation, matching the exact fixture shape this migration's own
 * LicensingTest already provisions per-test.
 *
 * `licensing:manage` (state changes) is step-up gated with the same
 * 'password.confirm' middleware the JSON API route already uses.
 */
class LicensingViewController extends Controller
{
    public function __construct(private readonly LicensingService $licensing) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'licensing:read');
        $actor = $request->user();
        $organisationId = $request->query('organisation_id');

        $entitlementsSnapshot = $this->licensing->entitlementsSnapshot($actor, $organisationId);
        $usageSnapshot = $this->licensing->usageSnapshot($actor, $organisationId);

        return view('licensing.index', [
            'organisation' => $entitlementsSnapshot['organisation'],
            'license' => $entitlementsSnapshot['license'],
            'entitlements' => $entitlementsSnapshot['entitlements'],
            'usage' => $usageSnapshot['usage'],
            'availableActions' => LicensingValidator::actionsFor($entitlementsSnapshot['license']['state']),
            'canManage' => $actor->hasAppPermission('licensing:manage'),
        ]);
    }

    public function storeState(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'licensing:manage');

        $payload = ['action' => (string) $request->input('action'), 'reason' => (string) $request->input('reason')];

        try {
            $this->licensing->changeState($payload, $request->user(), $request->query('organisation_id'));
        } catch (LicensingValidationException|AuthorizationException $e) {
            // Explicit redirect to the index route rather than back(): the
            // same class of bug ConfirmPasswordController's own fix
            // addressed earlier in this build-out for a step-up-gated POST
            // whose form lives on exactly one known page.
            return redirect()->route('licensing.index')->withErrors(['form' => $e->getMessage()])->withInput();
        }

        return redirect()->route('licensing.index')->with('status', 'Licence state updated.');
    }
}
