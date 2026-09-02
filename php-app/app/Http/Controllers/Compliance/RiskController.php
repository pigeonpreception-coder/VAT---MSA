<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Services\Compliance\RiskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Ported from app/api/v1/risk-indicators/... route.ts files and taxpayers/[id]/risk-evaluation/route.ts (Module 4 Phases A-B). */
class RiskController extends Controller
{
    public function __construct(private readonly RiskService $risk) {}

    public function restricted(Request $request): JsonResponse
    {
        $this->authorize('permission', 'risk:read');

        return response()->json($this->risk->restricted($request->user(), $request->query()));
    }

    public function assignReview(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'risk:review');
        $correlationId = (string) Str::uuid();
        $indicator = $this->risk->assignReview($id, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['resource' => $indicator], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }

    public function approveAction(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'risk:review');
        $correlationId = (string) Str::uuid();
        $indicator = $this->risk->approveAction($id, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['resource' => $indicator], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }

    public function evaluate(Request $request, string $taxpayerId): JsonResponse
    {
        $this->authorize('permission', 'risk:review');
        $correlationId = (string) Str::uuid();
        $result = $this->risk->evaluate($taxpayerId, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json($result, Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }
}
