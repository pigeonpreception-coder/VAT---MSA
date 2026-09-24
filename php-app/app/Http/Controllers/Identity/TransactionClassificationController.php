<?php

namespace App\Http\Controllers\Identity;

use App\Http\Controllers\Controller;
use App\Support\Business\TransactionClassifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Gap-finding pass (2026-09-24): ported from app/api/v1/counterparties/
 * classification/route.ts (Module 1 Buyer/Seller ClassifyTransaction) --
 * a pre-flight counterparty check a caller can make ahead of invoice
 * submission, by raw VAT number, before any BusinessParty record exists.
 * App\Support\Business\TransactionClassifier::classify() (the underlying
 * logic) already existed, reused internally by SupplierVerificationService,
 * but had no standalone route of its own.
 */
class TransactionClassificationController extends Controller
{
    private const IDENTIFIER_PATTERN = '/^[A-Z0-9][A-Z0-9.\/\-]{2,39}$/';

    public function show(Request $request): JsonResponse
    {
        $this->authorize('permission', 'invoices:submit');

        $vatNumber = mb_strtoupper(trim((string) $request->query('vat_number')));
        if (! preg_match(self::IDENTIFIER_PATTERN, $vatNumber)) {
            throw ValidationException::withMessages(['vat_number' => 'VAT number is required and must match the identifier pattern.']);
        }

        return response()->json(['classification' => TransactionClassifier::classify($vatNumber)]);
    }
}
