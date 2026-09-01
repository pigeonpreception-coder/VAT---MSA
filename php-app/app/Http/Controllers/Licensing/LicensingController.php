<?php

namespace App\Http\Controllers\Licensing;

use App\Http\Controllers\Controller;
use App\Services\Licensing\LicensingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ported from app/api/v1/licensing/{entitlements,usage,state,upgrade}/
 * route.ts -- the Licensing & Entitlements slice of Phase 12 (portals/
 * licensing/governance). state/upgrade are step-up gated via the same
 * 'password.confirm' middleware every other sensitive command in this
 * migration uses, matching the source's own requireStepUp calls on both.
 */
class LicensingController extends Controller
{
    public function __construct(private readonly LicensingService $licensing) {}

    public function entitlements(Request $request): JsonResponse
    {
        $this->authorize('permission', 'licensing:read');

        return response()->json($this->licensing->entitlementsSnapshot($request->user(), $request->query('organisation_id')));
    }

    public function usage(Request $request): JsonResponse
    {
        $this->authorize('permission', 'licensing:read');

        return response()->json($this->licensing->usageSnapshot($request->user(), $request->query('organisation_id')));
    }

    public function state(Request $request): JsonResponse
    {
        $this->authorize('permission', 'licensing:manage');
        $license = $this->licensing->changeState((array) $request->json()->all(), $request->user(), $request->query('organisation_id'));

        return response()->json(['license' => $license]);
    }

    public function upgrade(Request $request): JsonResponse
    {
        $this->authorize('permission', 'licensing:manage');
        $license = $this->licensing->upgrade((array) $request->json()->all(), $request->user(), $request->query('organisation_id'));

        return response()->json(['license' => $license]);
    }
}
