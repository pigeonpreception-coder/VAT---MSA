<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\OfflineSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Ported from lib/api/platform.ts's handleOfflineBatch (app/api/v1/offline/batches/route.ts). */
class OfflineSyncController extends Controller
{
    public function __construct(private readonly OfflineSyncService $offlineSync) {}

    public function store(Request $request): JsonResponse
    {
        $this->authorize('permission', 'offline:sync');

        $correlationId = (string) Str::uuid();
        $batch = $this->offlineSync->receive((array) $request->json()->all(), $request->user(), $correlationId);

        return response()->json(['batch' => $batch], Response::HTTP_ACCEPTED, ['x-correlation-id' => $correlationId]);
    }
}
