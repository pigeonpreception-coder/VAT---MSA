<?php

namespace App\Http\Controllers\AccessGovernance;

use App\Http\Controllers\Controller;
use App\Services\AccessGovernance\AccessGovernanceService;
use App\Services\Administration\AdministrationSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from app/api/v1/access-requests/**, app/api/v1/access-reviews/
 * [id]/certifications/route.ts, app/api/v1/access-grants/revocation/
 * route.ts and app/api/v1/organisations/offboarding/route.ts -- Phase 12
 * slice 4 (the rest of Access governance). GET /access-requests and GET
 * /access-reviews both slice `getAdministrationSnapshot` (Phase 12's own
 * closing slice) down to their own fields, exactly matching the source.
 * Every write command that decides, certifies, revokes, or offboards is
 * step-up gated; the initial self/peer access *request* itself is not
 * (matching the source -- only `access-governance:read`, not `:manage`,
 * and no `requireStepUp` call in that one route).
 */
class AccessGovernanceController extends Controller
{
    public function __construct(private readonly AccessGovernanceService $access, private readonly AdministrationSnapshotService $snapshot) {}

    public function listAccessRequests(Request $request): JsonResponse
    {
        $this->authorize('permission', 'access-governance:read');
        $snapshot = $this->snapshot->getAdministrationSnapshot($request->user(), $request->query('organisation_id'));

        return response()->json(['organisation' => $snapshot['organisation'], 'requests' => $snapshot['accessRequests'], 'reviews' => $snapshot['accessReviews']]);
    }

    public function storeAccessRequest(Request $request): JsonResponse
    {
        $this->authorize('permission', 'access-governance:read');
        $accessRequest = $this->access->requestRoleAccess((array) $request->json()->all(), $request->user(), $request->query('organisation_id'));

        return response()->json(['request' => $accessRequest], Response::HTTP_CREATED);
    }

    public function listAccessReviews(Request $request): JsonResponse
    {
        $this->authorize('permission', 'access-governance:read');
        $snapshot = $this->snapshot->getAdministrationSnapshot($request->user(), $request->query('organisation_id'));

        return response()->json(['organisation' => $snapshot['organisation'], 'reviews' => $snapshot['accessReviews']]);
    }

    public function decideAccessRequest(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'access-governance:manage');
        $decision = $this->access->decideAccessRequest($id, (array) $request->json()->all(), $request->user(), $request->query('organisation_id'));

        return response()->json(['decision' => $decision]);
    }

    public function storeCertification(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'access-governance:manage');
        $certification = $this->access->certifyQuarterlyAccess($id, (array) $request->json()->all(), $request->user(), $request->query('organisation_id'));

        return response()->json(['certification' => $certification], Response::HTTP_CREATED);
    }

    public function storeRevocation(Request $request): JsonResponse
    {
        $this->authorize('permission', 'access-governance:manage');
        $revocation = $this->access->revokeAccessGrant((array) $request->json()->all(), $request->user(), $request->query('organisation_id'));

        return response()->json(['revocation' => $revocation]);
    }

    public function storeOffboarding(Request $request): JsonResponse
    {
        $this->authorize('permission', 'access-governance:manage');
        $offboarding = $this->access->offboardUser((array) $request->json()->all(), $request->user(), $request->query('organisation_id'));

        return response()->json(['offboarding' => $offboarding]);
    }
}
