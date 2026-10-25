<?php

namespace App\Http\Controllers\AccessGovernance;

use App\Exceptions\LicensingValidationException;
use App\Exceptions\RepositoryConflictException;
use App\Http\Controllers\Controller;
use App\Services\Administration\AdministrationSnapshotService;
use App\Services\AccessGovernance\AccessGovernanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Real Blade UI for AccessGovernanceService -- RT-005 (the self-directed
 * red-team assessment, see docs/MIGRATION_MATRIX.md): request/decide/
 * certify/revoke/offboard were each a fully-built, tested JSON endpoint
 * (App\Http\Controllers\AccessGovernance\AccessGovernanceController) with
 * zero Blade UI anywhere -- a full-text search of resources/views/ for
 * "access-governance"/"AccessGovernance" found nothing.
 *
 * A standalone page, not folded into the existing Administration command
 * centre (resources/views/administration/index.blade.php), even though
 * that page's own doc comment names "access governance" as part of its
 * source-intended scope: access-governance:read is held by
 * TAXPAYER_ACCOUNTANT -- the red team finding's own named example role --
 * which does NOT hold administration:read (App\Support\Access\
 * Permissions::CONTROL_PLANE_PERMISSIONS -- TAXPAYER_ACCOUNTANT's grant
 * omits it). Gating this page on administration:read, as the existing
 * page does, would leave that exact role still unable to reach it.
 * index() therefore calls AdministrationSnapshotService directly (the
 * same shared snapshot AccessGovernanceController's own listAccessRequests/
 * listAccessReviews already slice down to these same two fields -- not a
 * new read pattern) and gates on access-governance:read alone, matching
 * the JSON controller's own gate exactly.
 *
 * activeMembers/roleGrants/capabilityGrants below are Blade-UI-only
 * additions (member pickers, a revocable-grants listing) -- deliberately
 * NOT added to AdministrationSnapshotService itself, whose own doc
 * comment is explicit that its shape mirrors the source's exact "ten
 * independent reads" one-for-one; widening that shared, source-mirroring
 * contract for a UI convenience this page alone needs would be a
 * port-fidelity regression, not a fix.
 */
class AccessGovernanceViewController extends Controller
{
    public function __construct(
        private readonly AccessGovernanceService $access,
        private readonly AdministrationSnapshotService $snapshot,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('permission', 'access-governance:read');
        $user = $request->user();
        $organisationId = $request->query('organisation_id');

        $snapshot = $this->snapshot->getAdministrationSnapshot($user, $organisationId);
        $orgId = $snapshot['organisation']['id'];

        return view('access-governance.index', [
            'organisation' => $snapshot['organisation'],
            'accessRequests' => $snapshot['accessRequests'],
            'accessReviews' => $snapshot['accessReviews'],
            'activeMembers' => $this->activeMembers($orgId),
            'roleGrants' => $this->roleGrants($orgId),
            'capabilityGrants' => $this->capabilityGrants($orgId),
            'roles' => $snapshot['roles'],
            'currentUserId' => $user->id,
            'canManage' => $user->hasAppPermission('access-governance:manage'),
        ]);
    }

    public function storeRequest(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'access-governance:read');
        $payload = [
            'subject_user_id' => $request->input('subject_user_id') ?: $request->user()->id,
            'role_id' => $request->input('role_id'),
            'justification' => $request->input('justification'),
        ];

        try {
            $this->access->requestRoleAccess($payload, $request->user(), $request->query('organisation_id'));
        } catch (LicensingValidationException $e) {
            return redirect()->route('access-governance.index')->withErrors(['request' => $e->getMessage()])->withInput();
        }

        return redirect()->route('access-governance.index')->with('status', 'Access request submitted for manager approval.');
    }

    public function storeDecision(Request $request, string $id): RedirectResponse
    {
        $this->authorize('permission', 'access-governance:manage');
        $payload = ['decision' => (string) $request->input('decision'), 'reason' => (string) $request->input('reason')];

        try {
            $this->access->decideAccessRequest($id, $payload, $request->user(), $request->query('organisation_id'));
        } catch (LicensingValidationException|RepositoryConflictException $e) {
            return redirect()->route('access-governance.index')->withErrors(['decision' => $e->getMessage()]);
        }

        return redirect()->route('access-governance.index')->with('status', 'Access request decided.');
    }

    public function storeCertification(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'access-governance:manage');
        $payload = [
            'subject_user_id' => $request->input('subject_user_id'),
            'disposition' => $request->input('disposition'),
            'finding' => $request->input('finding', ''),
        ];

        try {
            $this->access->certifyQuarterlyAccess($request->input('review_id'), $payload, $request->user(), $request->query('organisation_id'));
        } catch (LicensingValidationException|RepositoryConflictException $e) {
            return redirect()->route('access-governance.index')->withErrors(['certification' => $e->getMessage()])->withInput();
        }

        return redirect()->route('access-governance.index')->with('status', 'Access certification recorded.');
    }

    public function storeRevocation(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'access-governance:manage');
        $payload = [
            'grant_type' => $request->input('grant_type'),
            'grant_id' => $request->input('grant_id'),
            'reason' => $request->input('reason'),
        ];

        try {
            $this->access->revokeAccessGrant($payload, $request->user(), $request->query('organisation_id'));
        } catch (LicensingValidationException $e) {
            return redirect()->route('access-governance.index')->withErrors(['revocation' => $e->getMessage()]);
        }

        return redirect()->route('access-governance.index')->with('status', 'Access grant revoked.');
    }

    public function storeOffboarding(Request $request): RedirectResponse
    {
        $this->authorize('permission', 'access-governance:manage');
        $payload = ['user_id' => $request->input('user_id'), 'reason' => $request->input('reason')];

        try {
            $this->access->offboardUser($payload, $request->user(), $request->query('organisation_id'));
        } catch (LicensingValidationException $e) {
            return redirect()->route('access-governance.index')->withErrors(['offboarding' => $e->getMessage()]);
        }

        return redirect()->route('access-governance.index')->with('status', 'User offboarded: membership and all active role/capability grants revoked.');
    }

    /** @return list<array<string, mixed>> */
    private function activeMembers(string $organisationId): array
    {
        return DB::table('organisation_memberships as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.organisation_id', $organisationId)->where('m.status', 'ACTIVE')
            ->orderBy('u.name')
            ->get(['u.id', 'u.name', 'u.email'])
            ->map(fn ($row) => (array) $row)->all();
    }

    /** @return list<array<string, mixed>> */
    private function roleGrants(string $organisationId): array
    {
        return DB::table('user_role_assignments as a')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->join('organisation_roles as r', 'r.id', '=', 'a.organisation_role_id')
            ->where('a.organisation_id', $organisationId)->where('a.status', 'ACTIVE')
            ->orderBy('u.name')
            ->get(['a.id', 'u.id as user_id', 'u.name as user_name', 'r.name as role_name', 'a.effective_from'])
            ->map(fn ($row) => (array) $row)->all();
    }

    /** @return list<array<string, mixed>> */
    private function capabilityGrants(string $organisationId): array
    {
        return DB::table('user_capability_assignments as a')
            ->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.organisation_id', $organisationId)->where('a.status', 'ACTIVE')
            ->orderBy('u.name')
            ->get(['a.id', 'u.id as user_id', 'u.name as user_name', 'a.capability', 'a.effective_from'])
            ->map(fn ($row) => (array) $row)->all();
    }
}
