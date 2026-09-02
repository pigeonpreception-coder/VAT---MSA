<?php

namespace App\Http\Controllers\OrganisationAdmin;

use App\Http\Controllers\Controller;
use App\Services\Administration\AdministrationSnapshotService;
use App\Services\OrganisationAdmin\OrganisationAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from app/api/v1/organisations/{employees,administrators,roles,
 * capabilities}/** and app/api/v1/access-reviews/route.ts's POST handler
 * -- Phase 12 slice 2 (organisation administration/employees).
 * `listCapabilityGrants` was always a genuinely standalone read; every
 * other GET list route here (employees/roles/administrators) slices
 * `getAdministrationSnapshot` (Phase 12's own closing slice -- see
 * docs/MIGRATION_MATRIX.md) down to its own fields, exactly matching the
 * source's own route shape. Every write command is step-up gated via the
 * same 'password.confirm' middleware every other sensitive command in
 * this migration uses.
 */
class OrganisationAdminController extends Controller
{
    public function __construct(private readonly OrganisationAdminService $admin, private readonly AdministrationSnapshotService $snapshot) {}

    public function listEmployees(Request $request): JsonResponse
    {
        $this->authorize('permission', 'employees:read');
        $snapshot = $this->snapshot->getAdministrationSnapshot($request->user(), $request->query('organisation_id'));

        return response()->json(['organisation' => $snapshot['organisation'], 'employees' => $snapshot['employees']]);
    }

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

    public function listAdministrators(Request $request): JsonResponse
    {
        $this->authorize('permission', 'administration:read');
        $snapshot = $this->snapshot->getAdministrationSnapshot($request->user(), $request->query('organisation_id'));

        return response()->json(['organisation' => $snapshot['organisation'], 'administrators' => $snapshot['administrators']]);
    }

    public function storeAdministrator(Request $request): JsonResponse
    {
        $this->authorize('permission', 'administration:manage');
        $administrator = $this->admin->appointAdministrator((array) $request->json()->all(), $request->user(), $request->query('organisation_id'));

        return response()->json(['administrator' => $administrator], Response::HTTP_CREATED);
    }

    public function listRoles(Request $request): JsonResponse
    {
        $this->authorize('permission', 'roles:read');
        $snapshot = $this->snapshot->getAdministrationSnapshot($request->user(), $request->query('organisation_id'));

        return response()->json(['organisation' => $snapshot['organisation'], 'roles' => $snapshot['roles']]);
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
