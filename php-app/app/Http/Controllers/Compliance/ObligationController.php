<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Services\Compliance\ObligationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Ported from app/api/v1/obligations/{route,[id]/satisfaction}/route.ts (Module 3 Phase D). */
class ObligationController extends Controller
{
    public function __construct(private readonly ObligationService $obligations) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('permission', 'compliance:read');

        return response()->json($this->obligations->search($request->user(), $request->query()));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('permission', 'obligations:manage');
        $correlationId = (string) Str::uuid();
        $obligation = $this->obligations->create((array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['resource' => $obligation], Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }

    public function markSatisfied(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'obligations:manage');
        $correlationId = (string) Str::uuid();
        $obligation = $this->obligations->markSatisfied($id, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['resource' => $obligation], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }
}
