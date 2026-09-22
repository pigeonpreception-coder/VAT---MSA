<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Services\Operations\LogisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from app/api/v1/logistics-deliveries/{route,[id]/{route,dispatch,
 * delivery,cancellation}}/route.ts, via lib/api/logistics.ts's
 * handleLogisticsList/handleLogisticsGet/handleLogisticsCommand dispatch.
 * Reuses App\Services\Operations\LogisticsService exactly as
 * LogisticsViewController already does -- the same JSON-API gap this
 * session's route-level source sweep found for fixed assets and security
 * incidents, closed the same way: a thin controller over already-complete,
 * already-tested business logic, no new service/validator/model/migration.
 * No rate-limit middleware, matching every other business-domain write
 * route in this port.
 */
class LogisticsController extends Controller
{
    public function __construct(private readonly LogisticsService $logistics) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('permission', 'logistics:read');
        $deliveries = $this->logistics->list($request->user(), $request->query('organisation_id'));

        return response()->json(['resources' => $deliveries]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'logistics:read');
        $delivery = $this->logistics->get($id, $request->user());

        return response()->json(['resource' => $delivery]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('permission', 'logistics:manage');
        $correlationId = (string) Str::uuid();
        $delivery = $this->logistics->create((array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, $request->query('organisation_id'));

        return response()->json(['resource' => $delivery], Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }

    public function dispatch(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'logistics:manage');
        $correlationId = (string) Str::uuid();
        $delivery = $this->logistics->dispatch($id, $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['resource' => $delivery], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }

    public function deliver(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'logistics:manage');
        $correlationId = (string) Str::uuid();
        $delivery = $this->logistics->deliver($id, $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['resource' => $delivery], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'logistics:manage');
        $correlationId = (string) Str::uuid();
        $delivery = $this->logistics->cancel($id, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['resource' => $delivery], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }
}
