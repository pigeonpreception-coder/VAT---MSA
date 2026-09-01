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
 * Ported from app/api/v1/invoices/route.ts and its [id] sibling. Deliberately
 * narrower than the source for this phase: no port yet of
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
