<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Services\Compliance\AuditCaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Ported from app/api/v1/audit-cases/** and audit-evidence/[id]/custody-events/route.ts (Module 4 Phases C-D). */
class AuditCaseController extends Controller
{
    public function __construct(private readonly AuditCaseService $cases) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('permission', 'compliance:read');

        return response()->json($this->cases->search($request->user(), $request->query()));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('permission', 'cases:manage');
        $correlationId = (string) Str::uuid();
        $case = $this->cases->open((array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['resource' => $case], Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }

    public function transition(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'cases:manage');
        $correlationId = (string) Str::uuid();
        $case = $this->cases->transition($id, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['resource' => $case], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }

    public function issueFinding(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'cases:manage');
        $correlationId = (string) Str::uuid();
        $finding = $this->cases->issueFinding($id, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['resource' => $finding], Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }

    public function timeline(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'compliance:read');
        $timeline = $this->cases->timeline($id, $request->user());
        if (! $timeline) {
            return response()->json(['title' => 'Not found', 'status' => 404], Response::HTTP_NOT_FOUND);
        }

        return response()->json($timeline);
    }

    public function addEvidence(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'cases:manage');
        $correlationId = (string) Str::uuid();
        $evidence = $this->cases->addEvidence($id, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['resource' => $evidence], Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }

    public function evidence(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'compliance:read');
        $evidence = $this->cases->evidence($id, $request->user());
        if (! $evidence) {
            return response()->json(['title' => 'Not found', 'status' => 404], Response::HTTP_NOT_FOUND);
        }

        return response()->json($evidence);
    }

    public function recordEvidenceCustodyEvent(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'cases:manage');
        $correlationId = (string) Str::uuid();
        $evidence = $this->cases->recordEvidenceCustodyEvent($id, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['resource' => $evidence], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }

    public function addNote(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'cases:manage');
        $correlationId = (string) Str::uuid();
        $note = $this->cases->addNote($id, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['resource' => $note], Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }

    public function notes(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'compliance:read');
        $notes = $this->cases->notes($id, $request->user());
        if (! $notes) {
            return response()->json(['title' => 'Not found', 'status' => 404], Response::HTTP_NOT_FOUND);
        }

        return response()->json($notes);
    }
}
