<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Services\Business\QuotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Ported from app/api/v1/quotations/route.ts and its [id]/{sending,accept,rejection,expiration,convert} siblings (Module 5 Phase B). getQuotationForEdit is deliberately not ported this slice (show() below covers the same data) -- see docs/MIGRATION_MATRIX.md. */
class QuotationController extends Controller
{
    public function __construct(private readonly QuotationService $quotations) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('permission', 'commercial:read');

        return response()->json($this->quotations->search($request->user(), $request->query('organisation_id'), $request->query()));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('permission', 'quotations:manage');
        $correlationId = (string) Str::uuid();
        $quotation = $this->quotations->create((array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, $request->query('organisation_id'));

        return response()->json(['resource' => $quotation], Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'quotations:manage');
        $correlationId = (string) Str::uuid();
        $quotation = $this->quotations->update($id, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, $request->query('organisation_id'));

        return response()->json(['resource' => $quotation], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }

    public function send(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'quotations:manage');
        $correlationId = (string) Str::uuid();
        $quotation = $this->quotations->send($id, $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, $request->query('organisation_id'));

        return response()->json(['resource' => $quotation], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }

    public function accept(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'quotations:manage');
        $correlationId = (string) Str::uuid();
        $quotation = $this->quotations->accept($id, $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, $request->query('organisation_id'));

        return response()->json(['resource' => $quotation], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'quotations:manage');
        $correlationId = (string) Str::uuid();
        $quotation = $this->quotations->reject($id, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, $request->query('organisation_id'));

        return response()->json(['resource' => $quotation], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }

    public function expire(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'quotations:manage');
        $correlationId = (string) Str::uuid();
        $quotation = $this->quotations->expire($id, $request->user(), (string) $request->header('Idempotency-Key', ''), $correlationId, $request->query('organisation_id'));

        return response()->json(['resource' => $quotation], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }

    public function convert(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'quotations:manage');
        $this->authorize('permission', 'invoices:submit');
        $correlationId = (string) Str::uuid();
        $context = ['correlation_id' => $correlationId, 'device_id' => $request->header('X-Device-Id'), 'source_token' => $request->header('X-Source-Token', $request->ip())];
        $invoice = $this->quotations->convertToInvoice($id, (array) $request->json()->all(), $request->user(), (string) $request->header('Idempotency-Key', ''), $context, $request->query('organisation_id'));

        return response()->json(['resource' => $invoice], Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }
}
