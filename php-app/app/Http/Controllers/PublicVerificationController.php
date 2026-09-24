<?php

namespace App\Http\Controllers;

use App\Exceptions\RateLimitExceededException;
use App\Services\Invoice\PublicVerificationService;
use App\Support\Invoice\InvoiceNumberMask;
use App\Support\Security\RequestContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ported from app/api/v1/verify/[token]/route.ts and app/verify/[token]/
 * page.tsx -- an unauthenticated, public invoice-authenticity lookup by
 * `certificate.verification_token` (what a QR code on a printed tax
 * invoice points to). Deliberately outside every auth-gated route group
 * in routes/web.php: the whole point is that anyone holding the token can
 * verify it, no session required. Privacy-minimised, matching both
 * source surfaces: the invoice number is masked and correction reason
 * text is never included, though the supplier name and gross amount are
 * shown in the clear (source's own choice, kept as-is).
 */
class PublicVerificationController extends Controller
{
    public function __construct(private readonly PublicVerificationService $verifications) {}

    public function show(Request $request, string $token): JsonResponse
    {
        // Not caught here -- RateLimitExceededException::render() already
        // self-renders the correct 429 JSON body, matching every other
        // self-rendering exception in this codebase.
        $ip = RequestContext::unauthenticatedRequestIp($request);
        $record = $this->verifications->verify($token, $ip, $ip);
        if (! $record) {
            return response()->json([
                'type' => 'https://vat-msa.local/problems/not-found', 'title' => 'Certificate not found', 'status' => 404,
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'valid' => $record['certificate_status'] === 'VALID',
            'certificate_status' => $record['certificate_status'],
            'status' => $record['status'],
            'certified_at' => $record['issued_at'],
            'supplier_display' => $record['supplier_name'],
            'invoice_number_masked' => InvoiceNumberMask::mask($record['invoice_number']),
            'total_amount' => number_format($record['total_cents'] / 100, 2, '.', ''),
            'currency' => $record['currency'],
            'is_correction' => $record['is_correction'],
            'correction_type' => $record['correction_type'],
            'corrects_invoice_number_masked' => $record['corrects_invoice_number'] ? InvoiceNumberMask::mask($record['corrects_invoice_number']) : null,
            'corrections' => array_map(fn (array $correction) => [
                'correction_type' => $correction['correction_type'],
                'status' => $correction['status'],
                'invoice_number_masked' => InvoiceNumberMask::mask($correction['invoice_number']),
                'total_amount' => number_format($correction['total_cents'] / 100, 2, '.', ''),
                'created_at' => $correction['created_at'],
            ], $record['corrections']),
        ]);
    }

    public function page(Request $request, string $token): View
    {
        $ip = RequestContext::unauthenticatedRequestIp($request);
        try {
            $record = $this->verifications->verify($token, $ip, $ip);
        } catch (RateLimitExceededException $e) {
            abort($e->status(), $e->getMessage());
        }
        abort_if(! $record, 404);

        return view('verify.show', ['record' => $record, 'token' => $token]);
    }
}
