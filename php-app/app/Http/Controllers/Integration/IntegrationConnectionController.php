<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Services\Integration\IntegrationConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Ported from app/api/v1/integrations/**\/route.ts (lib/api/integration.ts's
 * handleIntegrationCommand/handleIntegrationHealth) -- Module 10 Phase A.
 * RegisterIntegration/ApproveIntegration/SuspendIntegration/StartSync are
 * all gated on `integrations:manage` (BUSINESS_WRITE, no `step-up`);
 * GetHealth is gated on the lighter `integrations:read`, matching source
 * exactly.
 */
class IntegrationConnectionController extends Controller
{
    public function __construct(private readonly IntegrationConnectionService $integrations) {}

    public function register(Request $request): JsonResponse
    {
        $this->authorize('permission', 'integrations:manage');

        $connection = $this->integrations->register((array) $request->json()->all(), $request->user(), $this->idempotencyKey($request), $this->correlationId());

        return response()->json(['resource' => $connection], 201);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'integrations:manage');

        $connection = $this->integrations->approve($id, $request->user(), $this->idempotencyKey($request), $this->correlationId());

        return response()->json(['resource' => $connection]);
    }

    public function suspend(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'integrations:manage');

        $connection = $this->integrations->suspend($id, (array) $request->json()->all(), $request->user(), $this->idempotencyKey($request), $this->correlationId());

        return response()->json(['resource' => $connection]);
    }

    public function startSync(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'integrations:manage');

        $job = $this->integrations->startSync($id, (array) $request->json()->all(), $request->user(), $this->idempotencyKey($request), $this->correlationId());

        return response()->json(['resource' => $job], 201);
    }

    public function health(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'integrations:read');

        return response()->json($this->integrations->getHealth($id, $request->user()));
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
