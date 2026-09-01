<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Services\Business\BusinessPartyService;
use App\Services\Business\SupplierVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Ported from app/api/v1/business-parties/route.ts and its [id], [id]/deactivation and [id]/verification siblings (Module 5 Phase A, in full). */
class BusinessPartyController extends Controller
{
    public function __construct(
        private readonly BusinessPartyService $parties,
        private readonly SupplierVerificationService $verification,
    ) {}

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

    /** Module 5 Phase A GetSupplierVerificationHistory: most recent first. */
    public function verificationHistory(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'parties:manage');

        return response()->json($this->verification->history($id, $request->user(), $request->query('organisation_id')));
    }

    /** Module 5 Phase A VerifySupplier: always re-checks live against TransactionClassifier and writes a brand-new snapshot, even on idempotent replay -- see SupplierVerificationService::verify's own doc comment. */
    public function verify(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'parties:manage');
        $correlationId = (string) Str::uuid();
        $snapshot = $this->verification->verify($id, $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, $request->query('organisation_id'));

        return response()->json(['resource' => $snapshot], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }
}
