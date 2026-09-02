<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformChangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from lib/api/platform.ts's Module 8 Phase A handlers -- kept 1:1
 * with the source's own app/api/v1/platform/** route shapes.
 * `provisionStaff`'s route wears the `password.confirm` middleware
 * (unconditional step-up, the same posture as invoice cancellation --
 * unlike the report-export commands' data-conditional step-up, see
 * App\Support\Access\StepUp's own doc comment).
 */
class PlatformConfigController extends Controller
{
    public function __construct(private readonly PlatformChangeService $platform) {}

    public function config(Request $request): JsonResponse
    {
        $this->authorize('permission', 'platform:read');

        return response()->json($this->platform->config());
    }

    public function changeRequests(Request $request): JsonResponse
    {
        $this->authorize('permission', 'platform:read');
        $status = $request->query('status');
        $status = is_string($status) && trim($status) !== '' ? mb_strtoupper(trim($status)) : null;

        return response()->json(['change_requests' => $this->platform->listChangeRequests($status)]);
    }

    public function requestChange(Request $request): JsonResponse
    {
        $this->authorize('permission', 'platform:manage');
        $correlationId = (string) Str::uuid();
        $result = $this->platform->requestChange((array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['change_request' => $result], Response::HTTP_CREATED);
    }

    public function decideChange(Request $request, string $changeRequestId): JsonResponse
    {
        $this->authorize('permission', 'platform:manage');
        $correlationId = (string) Str::uuid();
        $result = $this->platform->decideChange($changeRequestId, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['change_request' => $result]);
    }

    public function provisionStaff(Request $request): JsonResponse
    {
        $this->authorize('permission', 'platform:manage');
        $correlationId = (string) Str::uuid();
        $result = $this->platform->provisionStaff((array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['staff' => $result], Response::HTTP_CREATED);
    }
}
