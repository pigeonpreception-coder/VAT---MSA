<?php

namespace App\Http\Controllers\Administration;

use App\Http\Controllers\Controller;
use App\Services\Administration\AdministrationSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ported from app/api/v1/administration/route.ts -- the one route that
 * returns getAdministrationSnapshot's own full, unsliced payload. Every
 * other consumer (organisations/employees, organisations/roles,
 * organisations/administrators, licensing/license, access-requests,
 * access-reviews, workflows) already has its own controller from its own
 * Phase 12 slice and just slices this same snapshot down to its own
 * fields -- see each of those controllers' own GET methods.
 */
class AdministrationController extends Controller
{
    public function __construct(private readonly AdministrationSnapshotService $snapshot) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorize('permission', 'administration:read');

        return response()->json($this->snapshot->getAdministrationSnapshot($request->user(), $request->query('organisation_id')));
    }
}
