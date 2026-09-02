<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Services\Compliance\DisputeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Ported from app/api/v1/disputes/route.ts. */
class DisputeController extends Controller
{
    public function __construct(private readonly DisputeService $disputes) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('permission', 'compliance:read');

        return response()->json($this->disputes->search($request->user(), $request->query()));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('permission', 'disputes:manage');
        $correlationId = (string) Str::uuid();
        $dispute = $this->disputes->file((array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId);

        return response()->json(['resource' => $dispute], Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }
}
