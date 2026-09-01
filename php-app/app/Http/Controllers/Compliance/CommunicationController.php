<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Services\Compliance\CommunicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from app/api/v1/communications/{route,notices,[id],[id]/{responses,closure}}/route.ts
 * (Module 6 Phase C). `respond` deliberately gates on the same broad
 * compliance:read the source's own route does -- the real permission
 * (communications:manage OR communications:respond) is enforced inside
 * CommunicationService::respond, the same layered-permission pattern
 * AuditCaseController's evidence-custody route already uses.
 */
class CommunicationController extends Controller
{
    public function __construct(private readonly CommunicationService $communications) {}

    public function inbox(Request $request): JsonResponse
    {
        $this->authorize('permission', 'compliance:read');

        return response()->json($this->communications->inbox($request->user(), $request->query()));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'compliance:read');
        $conversation = $this->communications->conversation($id, $request->user());
        if (! $conversation) {
            return response()->json(['title' => 'Not found', 'status' => 404], Response::HTTP_NOT_FOUND);
        }

        return response()->json($conversation);
    }

    public function sendNotice(Request $request): JsonResponse
    {
        $this->authorize('permission', 'communications:manage');
        $correlationId = (string) Str::uuid();
        $thread = $this->communications->sendNotice((array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['resource' => $thread], Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }

    public function respond(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'compliance:read');
        $correlationId = (string) Str::uuid();
        $message = $this->communications->respond($id, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['resource' => $message], Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }

    public function close(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'communications:manage');
        $correlationId = (string) Str::uuid();
        $thread = $this->communications->close($id, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['resource' => $thread], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }
}
