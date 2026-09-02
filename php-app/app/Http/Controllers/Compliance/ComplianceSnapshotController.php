<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Services\Compliance\ComplianceSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Ported from lib/api/compliance.ts's handleComplianceList. */
class ComplianceSnapshotController extends Controller
{
    public function __construct(private readonly ComplianceSnapshotService $snapshot) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorize('permission', 'compliance:read');

        return response()->json($this->snapshot->getSnapshot($request->user()));
    }
}
