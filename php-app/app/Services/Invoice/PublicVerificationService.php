<?php

namespace App\Services\Invoice;

use App\Models\Certificate;
use App\Models\InvoiceCorrection;

/**
 * Ported from lib/data/repository.ts's getPublicVerification. Module 2
 * Phase C/B: the public VerifyInvoice output includes correction lineage
 * -- previously only the authenticated invoice detail showed whether an
 * invoice had been credited/debited or was itself a correction, which
 * meant a paper/QR verification of a since-corrected invoice looked
 * identical to an unaffected one. Deliberately excludes correction reason
 * text (kept authenticated-only) since this is an unauthenticated, public-
 * posture command -- no actor, no audit trail, a pure read by
 * verification token.
 */
class PublicVerificationService
{
    /** @return array<string, mixed>|null */
    public function verify(string $token): ?array
    {
        $certificate = Certificate::with('invoice')->where('verification_token', $token)->first();
        if (! $certificate || ! $certificate->invoice) {
            return null;
        }
        $invoice = $certificate->invoice;

        $asOriginal = InvoiceCorrection::with('correctionInvoice')
            ->where('original_invoice_id', $invoice->id)->orderBy('created_at')->get();
        $asCorrection = InvoiceCorrection::with('originalInvoice')
            ->where('correction_invoice_id', $invoice->id)->first();

        return [
            'certificate_status' => $certificate->status,
            'issued_at' => $certificate->issued_at,
            'invoice_hash' => $certificate->invoice_hash,
            'signature_profile' => $certificate->signature_profile,
            'status' => $invoice->status,
            'supplier_name' => $invoice->supplier_name,
            'invoice_number' => $invoice->invoice_number,
            'total_cents' => (int) $invoice->total_cents,
            'currency' => $invoice->currency,
            'is_correction' => $asCorrection !== null,
            'corrects_invoice_number' => $asCorrection?->originalInvoice?->invoice_number,
            'correction_type' => $asCorrection?->correction_type,
            'corrections' => $asOriginal->map(fn (InvoiceCorrection $correction) => [
                'correction_type' => $correction->correction_type,
                'status' => $correction->status,
                'invoice_number' => $correction->correctionInvoice?->invoice_number,
                'total_cents' => (int) ($correction->correctionInvoice?->total_cents ?? 0),
                'created_at' => $correction->created_at,
            ])->values()->all(),
        ];
    }
}
