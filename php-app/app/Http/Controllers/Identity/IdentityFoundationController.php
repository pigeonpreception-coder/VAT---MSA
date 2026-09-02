<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Services\Identity\IdentityFoundationSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ported from lib/data/identity-repository.ts's
 * getIdentityFoundationSnapshot -- consumed directly by the source's own
 * `app/organisations/page.tsx` and `app/portal/{namra,namra-admin}/
 * page.tsx` server components rather than a dedicated `app/api/v1/**`
 * route file; exposed as one here anyway, matching this migration's own
 * established convention (see App\Http\Controllers\Administration\
 * AdministrationController's identical precedent for
 * getAdministrationSnapshot).
 */
class IdentityFoundationController extends Controller
{
    public function __construct(private readonly IdentityFoundationSnapshotService $snapshot) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorize('permission', 'identity:read');

        return response()->json($this->snapshot->getSnapshot($request->user()));
    }
}
