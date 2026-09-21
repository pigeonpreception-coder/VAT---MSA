<?php

namespace App\Http\Controllers\Reconciliation;

use App\Http\Controllers\Controller;
use App\Services\Reconciliation\ReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Ported from app/api/v1/invoices/[id]/match/route.ts and the
 * app/api/v1/exceptions/** family (lib/api/reconciliation.ts's
 * reconciliationProblem/reconciliationJson) -- Module 3 Phase A/B: the
 * reconciliation matching engine and its NamRA-officer work queue.
 */
class ReconciliationController extends Controller
{
    public function __construct(private readonly ReconciliationService $reconciliation) {}

    public function match(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'reconciliation:manage');

        $match = $this->reconciliation->runMatch($id, $request->user(), $this->idempotencyKey($request), $this->correlationId());

        return response()->json(['match' => $match], 201);
    }

    public function exceptions(Request $request): JsonResponse
    {
        $this->authorize('permission', 'exceptions:read');

        return response()->json(['work_queue' => $this->reconciliation->getWorkQueue($request, $request->user())]);
    }

    public function assignException(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'reconciliation:manage');

        $assignment = $this->reconciliation->assignException($id, (array) $request->json()->all(), $request->user(), $this->idempotencyKey($request), $this->correlationId());

        return response()->json(['assignment' => $assignment]);
    }

    public function resolveException(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'reconciliation:manage');

        $resolution = $this->reconciliation->resolveException($id, (array) $request->json()->all(), $request->user(), $this->idempotencyKey($request), $this->correlationId());

        return response()->json(['resolution' => $resolution]);
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
