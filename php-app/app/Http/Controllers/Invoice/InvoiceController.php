<?php

namespace App\Http\Controllers\Invoice;

use App\Exceptions\InvoiceValidationException;
use App\Http\Controllers\Controller;
use App\Services\Invoice\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from app/api/v1/invoices/route.ts and its [id]/cancellation,
 * [id]/vat-explanation and [id]/transaction-timeline siblings.
 * Deliberately narrower than the source: no port yet of
 * enforceInvoiceRateLimits/emitStructuredSecurityLog (rate-limiting and
 * structured request logging are cross-cutting concerns shared by several
 * still-unmigrated route files -- tracked in docs/MIGRATION_MATRIX.md rather
 * than half-ported here), and the response uses a plain JSON error body
 * rather than the source's RFC 7807 problem+json envelope (see
 * InvoiceValidationException/RepositoryConflictException's own render()).
 */
class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('permission', 'invoices:read');

        return response()->json(['invoices' => $this->invoices->list($request->user())]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'invoices:read');

        $invoice = $this->invoices->find($id, $request->user());
        if (! $invoice) {
            return response()->json(['title' => 'Not found', 'status' => 404], Response::HTTP_NOT_FOUND);
        }

        return response()->json($invoice);
    }

    /** Module 2 Phase B CancelInvoice: { reason }. Officer-only (invoices:cancel) and step-up gated via the route's own 'password.confirm' middleware, matching the sensitivity of suspending a taxpayer outright. */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'invoices:cancel');

        $payload = $request->json()->all();
        $correlationId = (string) Str::uuid();
        $cancellation = $this->invoices->cancel($request->user(), $id, is_array($payload) ? $payload : [], $correlationId);

        return response()->json(['cancellation' => $cancellation], Response::HTTP_OK, ['x-correlation-id' => $correlationId]);
    }

    /** Module 2 Phase A ExplainCalculation: per-line trace back to the exact approved VATRule version that produced its tax amount. */
    public function vatExplanation(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'invoices:read');

        $explanation = $this->invoices->explainVat($id, $request->user());
        if (! $explanation) {
            return response()->json(['title' => 'Not found', 'status' => 404], Response::HTTP_NOT_FOUND);
        }

        return response()->json($explanation);
    }

    /** Module 2 Phase D GetTransactionTimeline: certification, every correction and any cancellation for one invoice's lineage, as a chronological narrative of VATTransaction events. */
    public function transactionTimeline(Request $request, string $id): JsonResponse
    {
        $this->authorize('permission', 'invoices:read');

        $timeline = $this->invoices->transactionTimeline($id, $request->user());
        if (! $timeline) {
            return response()->json(['title' => 'Not found', 'status' => 404], Response::HTTP_NOT_FOUND);
        }

        return response()->json($timeline);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('permission', 'invoices:submit');

        $payload = $request->json()->all();
        if (! is_array($payload) || array_is_list($payload)) {
            throw new InvoiceValidationException([
                ['code' => 'INVALID_DOCUMENT', 'path' => '/', 'message' => 'The request body must contain an invoice object.'],
            ]);
        }

        $idempotencyKey = (string) $request->header('Idempotency-Key', '');
        $correlationId = (string) Str::uuid();
        $context = ['correlation_id' => $correlationId, 'device_id' => $request->header('X-Device-Id'), 'source_token' => $request->header('X-Source-Token', $request->ip())];

        $invoice = $this->invoices->submit($payload, $request->user(), $idempotencyKey, $context);

        $verificationUrl = $request->getSchemeAndHttpHost().'/verify/'.$invoice['verificationToken'];

        return response()->json([
            'invoice_id' => $invoice['id'],
            'document_type' => $invoice['documentType'],
            'transaction_id' => $invoice['transactionId'],
            'certificate_id' => $invoice['certificateId'],
            'status' => 'CERTIFIED',
            'processing_status' => $invoice['status'],
            'correction' => $invoice['correction'],
            'certified_at' => $invoice['certifiedAt'],
            'vat_rules_applied' => $this->invoices->vatRulesApplied($invoice['id']),
            'invoice_hash' => $invoice['payloadHash'],
            'signature' => $invoice['signature'],
            'verification_url' => $verificationUrl,
            'qr_payload' => $verificationUrl,
        ], Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }
}
