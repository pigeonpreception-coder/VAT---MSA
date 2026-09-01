<?php

namespace App\Http\Controllers\VatLifecycle;

use App\Http\Controllers\Controller;
use App\Services\VatLifecycle\VatLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from app/api/v1/vat-periods/**, app/api/v1/vat-returns/** and
 * app/api/v1/approval-tasks/[id]/decision/route.ts (lib/api/vat-lifecycle.ts's
 * handleVatLifecycleList/handleVatReturnDetail/handleVatCommand). The
 * source's RFC 7807 problem+json envelope is not reproduced here, matching
 * every other controller this migration has ported so far (see
 * InvoiceController's own doc comment) -- plain JSON error bodies via each
 * exception's own render().
 */
class VatLifecycleController extends Controller
{
    public function __construct(private readonly VatLifecycleService $vatLifecycle) {}

    public function periods(Request $request): JsonResponse
    {
        $this->authorize('permission', 'returns:read');

        return response()->json($this->vatLifecycle->snapshot($request->user()));
    }

    public function showReturn(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'returns:read');

        return response()->json($this->vatLifecycle->returnDetail($id, $request->user()));
    }

    public function createAdjustment(Request $request, string $periodId): JsonResponse
    {
        $this->authorize('permission', 'vat-adjustments:manage');

        $resource = $this->vatLifecycle->createAdjustment(
            $periodId, (array) $request->json()->all(), $request->user(), $this->idempotencyKey($request), $this->correlationId(),
        );

        return $this->commandResponse($resource, Response::HTTP_CREATED);
    }

    public function generateReturn(Request $request, string $periodId): JsonResponse
    {
        $this->authorize('permission', 'returns:generate');

        $resource = $this->vatLifecycle->generateReturn($periodId, $request->user(), $this->idempotencyKey($request), $this->correlationId());

        return $this->commandResponse($resource, Response::HTTP_CREATED);
    }

    public function requestReturnApproval(Request $request, string $versionId): JsonResponse
    {
        $this->authorize('permission', 'returns:generate');

        $resource = $this->vatLifecycle->requestReturnApproval($versionId, $request->user(), $this->idempotencyKey($request), $this->correlationId());

        return $this->commandResponse($resource, Response::HTTP_ACCEPTED);
    }

    public function decideApproval(Request $request, string $taskId): JsonResponse
    {
        $this->authorize('permission', 'returns:approve');

        $payload = (array) $request->json()->all();
        $resource = $this->vatLifecycle->decideApproval($taskId, $payload, $request->user(), $this->idempotencyKey($request), $this->correlationId());

        return $this->commandResponse($resource, Response::HTTP_OK);
    }

    public function submitReturn(Request $request, string $versionId): JsonResponse
    {
        $this->authorize('permission', 'returns:submit');

        $resource = $this->vatLifecycle->submitReturn($versionId, $request->user(), $this->idempotencyKey($request), $this->correlationId());

        return $this->commandResponse($resource, Response::HTTP_ACCEPTED);
    }

    private function idempotencyKey(Request $request): string
    {
        return (string) $request->header('Idempotency-Key', '');
    }

    private function correlationId(): string
    {
        return (string) Str::uuid();
    }

    private function commandResponse(array $resource, int $status): JsonResponse
    {
        return response()->json(['resource' => $resource], $status);
    }
}
