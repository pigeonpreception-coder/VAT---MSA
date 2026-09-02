<?php

namespace App\Http\Controllers\Refund;

use App\Http\Controllers\Controller;
use App\Services\Refund\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from app/api/v1/refunds/route.ts and its [id]/checks,
 * [id]/transition, [id]/disputes siblings (lib/api/compliance.ts's
 * handleComplianceCommand/handleRefundClaimChecks). This closes out
 * compliance-repository.ts's refund functions -- the workflow the
 * VAT-return-generation prerequisite was built to unblock.
 */
class RefundController extends Controller
{
    public function __construct(private readonly RefundService $refunds) {}

    public function store(Request $request): JsonResponse
    {
        $this->authorize('permission', 'refunds:request');

        $resource = $this->refunds->request((array) $request->json()->all(), $request->user(), $this->idempotencyKey($request), $this->correlationId());

        return response()->json(['resource' => $resource], Response::HTTP_CREATED);
    }

    public function checks(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'refunds:read');

        $result = $this->refunds->checks($id, $request->user());
        if (! $result) {
            return response()->json(['title' => 'Not found', 'status' => 404], Response::HTTP_NOT_FOUND);
        }

        return response()->json($result);
    }

    public function transition(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'refunds:review');

        $resource = $this->refunds->transition($id, (array) $request->json()->all(), $request->user(), $this->idempotencyKey($request), $this->correlationId());

        return response()->json(['resource' => $resource]);
    }

    public function dispute(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'refunds:request');

        $resource = $this->refunds->dispute($id, (array) $request->json()->all(), $request->user(), $this->idempotencyKey($request), $this->correlationId());

        return response()->json(['resource' => $resource]);
    }

    private function idempotencyKey(Request $request): string
    {
        return (string) $request->header('Idempotency-Key', '');
    }

    private function correlationId(): string
    {
        return (string) Str::uuid();
    }
}
