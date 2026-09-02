<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Ported from lib/api/platform.ts's handlePlatformList and the two portal-page-only snapshot reads. */
class PlatformSnapshotController extends Controller
{
    /** Technical/infrastructure roles with no organisation scope of their own -- see PlatformSnapshotService::getSnapshot's own doc comment. */
    private const TECHNICAL_ONLY_ROLES = ['SUPER_ADMIN', 'INFRASTRUCTURE_ADMIN'];

    public function __construct(private readonly PlatformSnapshotService $platform) {}

    /**
     * Security fix 2026-08-27 (SECURITY_GAP_ASSESSMENT.md, "finance-data
     * exclusion from technical admin"): a technical-only actor is routed
     * to the technical snapshot outright, never reaching a query that
     * touches `payment_instructions`/`bank_imports` at all -- made
     * structural here rather than relying on `getSnapshot`'s own scoping
     * to incidentally return them nothing.
     */
    public function show(Request $request): JsonResponse
    {
        $this->authorize('permission', 'platform:read');
        $user = $request->user();

        $result = in_array($user->role, self::TECHNICAL_ONLY_ROLES, true)
            ? $this->platform->getTechnicalSnapshot()
            : $this->platform->getSnapshot($user);

        return response()->json($result);
    }

    public function documentCustody(Request $request): JsonResponse
    {
        $this->authorize('permission', 'documents:read');

        return response()->json($this->platform->documentCustodySummary($request->user()));
    }

    public function developerPortal(Request $request): JsonResponse
    {
        $this->authorize('permission', 'developer:read');

        return response()->json($this->platform->developerPortalSnapshot($request->user()));
    }
}
