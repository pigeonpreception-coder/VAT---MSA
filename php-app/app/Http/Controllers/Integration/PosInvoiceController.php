<?php

namespace App\Http\Controllers\Integration;

use App\Exceptions\InvoiceValidationException;
use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Services\Invoice\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Real-time invoice ingestion for a taxpayer's own private Point-of-Sale
 * system, authenticated by App\Http\Middleware\AuthenticatePosApiClient
 * rather than a session (see routes/api.php's own doc comment). Response
 * shape mirrors App\Http\Controllers\Invoice\InvoiceController::store()
 * exactly -- same underlying command (App\Services\Invoice\
 * InvoiceService::submit()), same certificate/verification contract --
 * since a private POS integrating against this endpoint deserves the
 * identical guarantee an in-app invoice submission already gets: real VAT
 * rule validation, real certification, and a real MATCHED/CERTIFIED/
 * EXCEPTION outcome, never a fabricated acknowledgement.
 *
 * `source.system_id` is always set from the authenticated credential, not
 * the request body -- a private POS could otherwise claim to be a
 * different system (including VAT-MSA's own POS or another taxpayer's
 * integration) purely by lying in its own payload.
 */
class PosInvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function store(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        if (! is_array($payload) || array_is_list($payload)) {
            throw new InvoiceValidationException([
                ['code' => 'INVALID_DOCUMENT', 'path' => '/', 'message' => 'The request body must contain an invoice object.'],
            ]);
        }

        /** @var ApiClient $client */
        $client = $request->attributes->get('posApiClient');
        $payload['source']['system_id'] = 'EXTERNAL-POS:'.$client->client_key;

        $idempotencyKey = (string) $request->header('Idempotency-Key', '');
        $correlationId = (string) Str::uuid();
        $context = ['correlation_id' => $correlationId, 'device_id' => $client->id, 'source_token' => $client->client_key];

        $invoice = $this->invoices->submit($payload, $request->user(), $idempotencyKey, $context);

        $verificationUrl = $request->getSchemeAndHttpHost().'/verify/'.$invoice['verificationToken'];

        return response()->json([
            'invoice_id' => $invoice['id'],
            'document_type' => $invoice['documentType'],
            'transaction_id' => $invoice['transactionId'],
            'certificate_id' => $invoice['certificateId'],
            'processing_status' => $invoice['status'],
            'correction' => $invoice['correction'],
            'certified_at' => $invoice['certifiedAt'],
            'invoice_hash' => $invoice['payloadHash'],
            'signature' => $invoice['signature'],
            'verification_url' => $verificationUrl,
            'qr_payload' => $verificationUrl,
        ], Response::HTTP_CREATED, ['x-correlation-id' => $correlationId]);
    }
}
