<?php

namespace App\Http\Controllers\Portal;

use App\Exceptions\BusinessResourceException;
use App\Exceptions\DeveloperValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Services\Developer\DeveloperPlatformService;
use App\Services\Platform\PlatformSnapshotService;
use App\Services\Portal\PortalService;
use App\Support\Business\OrganisationResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Ported from the source's own app/portal/developer/page.tsx -- the
 * fifth of the six per-portal dashboards. `index()` needs zero new
 * backend query beyond what `PlatformSnapshotService::
 * developerPortalSnapshot()` already returns (`clients`/`webhooks`, now
 * with each client's latest conformance outcome folded in).
 *
 * `createClient()`/`rotateCredential()`/`revokeCredential()`/
 * `runConformance()` are this port's Blade surface for Module 10 Phase D's
 * full command set -- gated `developer:manage`, matching source's actual
 * permission model for all four (distinct from `App\Http\Controllers\
 * Business\LocalInvoiceViewController`'s `integrations:manage` gate on
 * issuing/revoking a POS credential in the first place). Source's own
 * operationClass for all four is BUSINESS_WRITE, not COMPLIANCE_WRITE, so
 * none wears `step-up` -- see routes/web.php's own comment at the matching
 * JSON API routes.
 *
 * Gate is `developer:read`, not `dashboard:read` -- see
 * `App\Http\Controllers\Portal\SuperAdminPortalController`'s own doc
 * comment for the full rationale (and docs/MIGRATION_MATRIX.md's Super
 * Administration section for the general pattern this is the second
 * instance of). `SELLER_ADMIN` is on `PortalDefinitions`' own
 * `developer` role list but does not hold `developer:read`
 * (`Permissions::ROLE_PERMISSIONS` confirms it), so the source denies
 * that role even though role/capability membership alone would not
 * catch it.
 */
class DeveloperPortalController extends Controller
{
    public function __construct(
        private readonly PortalService $portals,
        private readonly PlatformSnapshotService $snapshot,
        private readonly DeveloperPlatformService $developer,
        private readonly OrganisationResolver $organisations,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'developer:read');
        $user = $request->user();
        $available = collect($this->portals->getAvailablePortals($user))->pluck('key');
        if (! $available->contains('developer')) {
            throw new AuthorizationException("Role {$user->role} is not authorised for the Developer portal in the active organisation context.");
        }

        return view('portal.developer', [
            'snapshot' => $this->snapshot->developerPortalSnapshot($user),
            'canManage' => $user->hasAppPermission('developer:manage'),
        ]);
    }

    public function createClient(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'developer:manage');
        $user = $request->user();

        $scopes = array_values(array_filter(array_map('trim', explode(',', (string) $request->input('scopes', '')))));
        $payload = [
            'schema_version' => '1.0.0',
            'name' => $request->input('name'),
            'scopes' => $scopes,
            'rate_limit_profile' => $request->input('rate_limit_profile'),
        ];

        try {
            $this->developer->createClient($user, $payload, $this->formIdempotencyKey($request), (string) Str::uuid());
        } catch (DeveloperValidationException $e) {
            return back()->withErrors(['client' => collect($e->errors())->pluck('message')->implode(' ')]);
        } catch (AuthorizationException $e) {
            return back()->withErrors(['client' => $e->getMessage()]);
        }

        return redirect()->route('portal.developer')->with('status', 'Application registered. Its credential is pending provisioning.');
    }

    public function revokeCredential(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'developer:manage');
        $user = $request->user();
        $organisation = $this->organisations->resolve($user, $request->query('organisation_id'));

        try {
            $this->developer->revokeCredential($organisation, $id, [
                'schema_version' => '1.0.0',
                'reason' => $request->input('reason'),
            ], $user, $this->formIdempotencyKey($request), (string) Str::uuid());
        } catch (DeveloperValidationException $e) {
            return back()->withErrors(['revocation' => collect($e->errors())->pluck('message')->implode(' ')]);
        } catch (BusinessResourceException|RepositoryConflictException $e) {
            return back()->withErrors(['revocation' => $e->getMessage()]);
        }

        return redirect()->route('portal.developer')->with('status', 'Credential revoked. This application can no longer authenticate.');
    }

    public function rotateCredential(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'developer:manage');
        $user = $request->user();
        $organisation = $this->organisations->resolve($user, $request->query('organisation_id'));

        try {
            $this->developer->rotateCredential($organisation, $id, $user, $this->formIdempotencyKey($request), (string) Str::uuid());
        } catch (BusinessResourceException|RepositoryConflictException $e) {
            return back()->withErrors(['rotation' => $e->getMessage()]);
        }

        return redirect()->route('portal.developer')->with('status', 'Credential rotated. The previous credential reference is now retired.');
    }

    public function runConformance(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'developer:manage');
        $user = $request->user();
        $organisation = $this->organisations->resolve($user, $request->query('organisation_id'));

        try {
            $run = $this->developer->runConformance($organisation, $id, $user, $this->formIdempotencyKey($request), (string) Str::uuid());
        } catch (BusinessResourceException $e) {
            return back()->withErrors(['conformance' => $e->getMessage()]);
        }

        return redirect()->route('portal.developer')->with('status', "Conformance run complete: {$run['outcome']}.");
    }
}
