<?php

namespace App\Http\Controllers\OrganisationAdmin;

use App\Http\Controllers\Controller;
use App\Services\OrganisationAdmin\OrganisationAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from app/api/v1/organisations/{employees,administrators,roles,
 * capabilities}/** and app/api/v1/access-reviews/route.ts's POST handler
 * -- Phase 12 slice 2 (organisation administration/employees). Every GET
 * list route in the source bundles its data inside `getAdministrationSnapshot`
 * (the fixed-list dashboard aggregate, deferred -- see
 * docs/MIGRATION_MATRIX.md); `listCapabilityGrants` is the one exception,
 * a genuinely standalone read, and the only GET route ported here.
 * Every write command is step-up gated via the same 'password.confirm'
 * middleware every other sensitive command in this migration uses.
 */
class OrganisationAdminController extends Controller
{
    public function __construct(private readonly OrganisationAdminService $admin) {}

    public function storeEmployee(Request $request): JsonResponse
    {
        $this->authorize('permission', 'employees:manage');
        $employee = $this->admin->inviteEmployee((array) $request->json()->all(), $request->user(), $request->query('organisation_id'));

        return response()->json(['employee' => $employee], Response::HTTP_CREATED);
    }

    public function activateEmployee(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'employees:manage');
        $employee = $this->admin->activateEmployee($id, (array) $request->json()->all(), $request->user(), $request->query('organisation_id'));

        return response()->json(['employee' => $employee]);
    }

    public function terminateEmployee(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'employees:manage');
        $payload = (array) $request->json()->all();
        $employee = $this->admin->terminateEmployee($id, $payload['reason'] ?? '', $request->user(), $request->query('organisation_id'));

        return response()->json(['employee' => $employee]);
    }

    public function storeAdministrator(Request $request): JsonResponse
    {
        $this->authorize('permission', 'administration:manage');
        $administrator = $this->admin->appointAdministrator((array) $request->json()->all(), $request->user(), $request->query('organisation_id'));

        return response()->json(['administrator' => $administrator], Response::HTTP_CREATED);
    }

    public function storeRole(Request $request): JsonResponse
    {
        $this->authorize('permission', 'roles:manage');
        $role = $this->admin->createOrganisationRole((array) $request->json()->all(), $request->user(), $request->query('organisation_id'));

        return response()->json(['role' => $role], Response::HTTP_CREATED);
    }

    public function capabilities(Request $request): JsonResponse
    {
        $this->authorize('permission', 'roles:read');

        return response()->json($this->admin->listCapabilityGrants($request->user(), $request->query('organisation_id')));
    }

    public function storeCapability(Request $request): JsonResponse
    {
        $this->authorize('permission', 'roles:manage');
        $capability = $this->admin->grantCapability((array) $request->json()->all(), $request->user(), $request->query('organisation_id'));

        return response()->json(['capability' => $capability], Response::HTTP_CREATED);
    }

    public function storeAccessReview(Request $request): JsonResponse
    {
        $this->authorize('permission', 'access-governance:manage');
        $review = $this->admin->openQuarterlyAccessReview($request->user(), $request->query('organisation_id'));

        return response()->json(['review' => $review], Response::HTTP_CREATED);
    }
}
