<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\DataProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from lib/api/platform.ts's Module 7 Phase D analytics handlers --
 * kept 1:1 with the source's own app/api/v1/analytics/** route shapes.
 */
class DataProductController extends Controller
{
    public function __construct(private readonly DataProductService $dataProducts) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('permission', 'reports:read');

        return response()->json(['data_products' => $this->dataProducts->list()]);
    }

    public function runModel(Request $request, string $dataProductId): JsonResponse
    {
        $this->authorize('permission', 'reports:run');
        $correlationId = (string) Str::uuid();
        $result = $this->dataProducts->runModel($dataProductId, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['model_run' => $result], Response::HTTP_CREATED);
    }

    public function publish(Request $request, string $dataProductId): JsonResponse
    {
        $this->authorize('permission', 'reports:run');
        $correlationId = (string) Str::uuid();
        $result = $this->dataProducts->publish($dataProductId, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['snapshot' => $result], Response::HTTP_CREATED);
    }

    public function metrics(Request $request): JsonResponse
    {
        $this->authorize('permission', 'reports:read');
        $result = $this->dataProducts->approvedMetrics($request->query('data_product_id') ?: null, $request->query('code') ?: null);

        return response()->json(['metrics' => $result]);
    }

    public function anomalies(Request $request): JsonResponse
    {
        $this->authorize('permission', 'reports:read');
        $result = $this->dataProducts->anomalyCandidates($request->query('data_product_id') ?: null);

        return response()->json(['anomalies' => $result]);
    }
}
