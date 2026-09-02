<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Portal\PortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ported from app/api/v1/portals/route.ts -- makes the same self-scoped
 * "which portals can I see" answer that already gates every /portal/*
 * page server-side independently callable. No permission gate beyond
 * being authenticated, matching the source exactly (the answer is
 * inherently self-scoped to the caller's own role and capabilities).
 */
class PortalController extends Controller
{
    public function __construct(private readonly PortalService $portals) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['portals' => $this->portals->getAvailablePortals($request->user())]);
    }
}
