<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Services\Business\BusinessPartyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Ported from app/api/v1/business-parties/route.ts and its [id]/[id]/deactivation siblings (Module 5 Phase A). verifySupplier/party_verification_snapshots are deliberately not ported this slice -- see docs/MIGRATION_MATRIX.md. */
class BusinessPartyController extends Controller
{
    public function __construct(private readonly BusinessPartyService $parties) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('permission', 'parties:manage');

        return response()->json($this->parties->search($request->user(), $request->query('organisation_id'), $request->query()));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('permission', 'parties:manage');
        $correlationId = (string) Str::uuid();
        $party = $this->parties->create((array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, $request->query('organisation_id'));

        return response()->json(['resource' => $party], Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'parties:manage');
        $correlationId = (string) Str::uuid();
        $party = $this->parties->update($id, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, $request->query('organisation_id'));

        return response()->json(['resource' => $party], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }

    public function deactivate(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'parties:manage');
        $correlationId = (string) Str::uuid();
        $party = $this->parties->deactivate($id, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, $request->query('organisation_id'));

        return response()->json(['resource' => $party], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }
}
