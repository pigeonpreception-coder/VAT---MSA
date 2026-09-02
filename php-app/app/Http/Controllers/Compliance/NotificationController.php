<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Services\Compliance\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from app/api/v1/notifications/{route,[id]/{cancellation,read},preferences}/route.ts
 * (Module 6 Phase D). `index`/`cancel`/`markRead`/`updatePreference` all
 * gate on the source's own near-universal dashboard:read -- notifications
 * are personal to every role, not just compliance-facing ones; the real
 * scope check (can this actor see *this* notification) is enforced inside
 * NotificationService itself.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('permission', 'dashboard:read');

        return response()->json($this->notifications->list($request->user(), $request->query()));
    }

    public function queue(Request $request): JsonResponse
    {
        $this->authorize('permission', 'notifications:manage');
        $correlationId = (string) Str::uuid();
        $notification = $this->notifications->queue((array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['resource' => $notification], Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'dashboard:read');
        $correlationId = (string) Str::uuid();
        $notification = $this->notifications->cancel($id, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['resource' => $notification], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'dashboard:read');
        $correlationId = (string) Str::uuid();
        $notification = $this->notifications->markRead($id, $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['resource' => $notification], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }

    public function updatePreference(Request $request): JsonResponse
    {
        $this->authorize('permission', 'dashboard:read');
        $correlationId = (string) Str::uuid();
        $preference = $this->notifications->updatePreference((array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['resource' => $preference], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }
}
