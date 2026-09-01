<?php

namespace App\Http\Controllers\AccessGovernance;

use App\Http\Controllers\Controller;
use App\Services\AccessGovernance\AccessGovernanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from app/api/v1/access-requests/**, app/api/v1/access-reviews/
 * [id]/certifications/route.ts, app/api/v1/access-grants/revocation/
 * route.ts and app/api/v1/organisations/offboarding/route.ts -- Phase 12
 * slice 4 (the rest of Access governance). GET /access-requests bundles
 * its data inside getAdministrationSnapshot (the dashboard aggregate,
 * deferred), so only its POST half is ported here, matching every other
 * GET-list route in this migration's own Phase 12 slices. Every write
 * command that decides, certifies, revokes, or offboards is step-up
 * gated; the initial self/peer access *request* itself is not (matching
 * the source -- only `access-governance:read`, not `:manage`, and no
 * `requireStepUp` call in that one route).
 */
class AccessGovernanceController extends Controller
{
    public function __construct(private readonly AccessGovernanceService $access) {}

    public function storeAccessRequest(Request $request): JsonResponse
    {
        $this->authorize('permission', 'access-governance:read');
        $accessRequest = $this->access->requestRoleAccess((array) $request->json()->all(), $request->user(), $request->query('organisation_id'));

        return response()->json(['request' => $accessRequest], Response::HTTP_CREATED);
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
