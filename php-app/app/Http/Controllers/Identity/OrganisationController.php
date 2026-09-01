<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Services\Identity\OrganisationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Ported from app/api/v1/organisations/route.ts and its [id]/route.ts sibling. */
class OrganisationController extends Controller
{
    public function __construct(private readonly OrganisationService $organisations) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('permission', 'identity:read');
        return response()->json(['organisations' => $this->organisations->list($request->user())]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'identity:read');
        $organisation = $this->organisations->get($request->user(), $id);
        if (! $organisation) {
            return response()->json(['code' => 'RESOURCE_NOT_FOUND', 'message' => 'The organisation was not found.'], 404);
        }
        return response()->json($organisation);
    }
}
