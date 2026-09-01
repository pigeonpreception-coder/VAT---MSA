<?php

namespace App\Domain\Invoice;

use App\Exceptions\InvoiceValidationException;

/**
 * Direct, line-for-line port of lib/domain/invoice.ts's
 * calculateAndValidateInvoice, decimalToScaled, centsToDecimal and
 * roundedDivide. Every decimal amount is parsed into an integer (cents, or
 * micros for quantity) and every calculation stays integer arithmetic --
 * never floating point -- exactly matching the source's own rule ("Use
 * integer cents... Do not use floating point for financial calculations").
 * PHP's native int is 64-bit on this platform, so no BigInt-equivalent is
 * needed for any realistic invoice value.
 */
class InvoiceCalculator
{
    private const TAX_CATEGORIES_REQUIRING_ZERO_RATE = ['ZERO_RATED', 'EXEMPT', 'OUTSIDE_SCOPE'];

    /**
     * @param array<string, mixed> $payload
     * @return array{lineNetCents: int, taxCents: int, totalCents: int, lines: list<array<string, mixed>>}
     *
     * @throws InvoiceValidationException
     */
    public function calculateAndValidate(array $payload): array
    {
        $errors = [];

        if (($payload['schema_version'] ?? null) !== '1.0.0') {
            $errors[] = ['code' => 'SCHEMA_VERSION', 'path' => '/schema_version', 'message' => 'Schema version 1.0.0 is required.'];
        }
        $invoiceNumber = trim((string) ($payload['invoice_number'] ?? ''));
        if ($invoiceNumber === '') {
            $errors[] = ['code' => 'INVOICE_NUMBER_REQUIRED', 'path' => '/invoice_number', 'message' => 'Invoice number is required.'];
        } elseif (mb_strlen($invoiceNumber) > 100) {
            $errors[] = ['code' => 'INVOICE_NUMBER_TOO_LONG', 'path' => '/invoice_number', 'message' => 'Invoice number must not exceed 100 characters.'];
        }

        $source = $payload['source'] ?? [];
        $systemId = trim((string) ($source['system_id'] ?? ''));
        $documentId = trim((string) ($source['document_id'] ?? ''));
        if ($systemId === '' || $documentId === '') {
            $errors[] = ['code' => 'SOURCE_REQUIRED', 'path' => '/source', 'message' => 'Source system and document identifiers are required.'];
        } else {
            if (mb_strlen($systemId) > 100) $errors[] = ['code' => 'SOURCE_SYSTEM_TOO_LONG', 'path' => '/source/system_id', 'message' => 'Source system ID must not exceed 100 characters.'];
            if (mb_strlen($documentId) > 150) $errors[] = ['code' => 'SOURCE_DOCUMENT_TOO_LONG', 'path' => '/source/document_id', 'message' => 'Source document ID must not exceed 150 characters.'];
            $submittedAt = $source['submitted_at'] ?? null;
            if (! $submittedAt || strtotime((string) $submittedAt) === false) {
                $errors[] = ['code' => 'SUBMITTED_AT_INVALID', 'path' => '/source/submitted_at', 'message' => 'Submitted timestamp must be a valid ISO date-time.'];
            }
        }

        $supplier = $payload['supplier'] ?? [];
        $supplierVat = $this->vatIdentifier($supplier);
        $supplierName = trim((string) ($supplier['name'] ?? ''));
        if ($supplierName === '' || ! $supplierVat) {
            $errors[] = ['code' => 'SUPPLIER_VAT_REQUIRED', 'path' => '/supplier', 'message' => 'The supplier name and VAT number are required.'];
        } elseif (mb_strlen($supplierName) > 250) {
            $errors[] = ['code' => 'SUPPLIER_NAME_TOO_LONG', 'path' => '/supplier/name', 'message' => 'Supplier name must not exceed 250 characters.'];
        }

        $customer = $payload['customer'] ?? [];
        $customerName = trim((string) ($customer['name'] ?? ''));
        $customerIdentifiers = $customer['identifiers'] ?? [];
        if ($customerName === '' || count($customerIdentifiers) === 0) {
            $errors[] = ['code' => 'CUSTOMER_REQUIRED', 'path' => '/customer', 'message' => 'Customer name and at least one identifier are required.'];
        } elseif (mb_strlen($customerName) > 250) {
            $errors[] = ['code' => 'CUSTOMER_NAME_TOO_LONG', 'path' => '/customer/name', 'message' => 'Customer name must not exceed 250 characters.'];
        }

        foreach (['supplier' => $supplier, 'customer' => $customer] as $partyName => $party) {
            $identifiers = $party['identifiers'] ?? [];
            if (count($identifiers) > 20) {
                $errors[] = ['code' => 'TOO_MANY_IDENTIFIERS', 'path' => "/{$partyName}/identifiers", 'message' => 'A party may have at most 20 identifiers.'];
            }
            foreach ($identifiers as $index => $identifier) {
                $value = trim((string) ($identifier['value'] ?? ''));
                if ($value === '' || mb_strlen($value) > 100) {
                    $errors[] = ['code' => 'IDENTIFIER_INVALID', 'path' => "/{$partyName}/identifiers/{$index}/value", 'message' => 'Identifier values must contain 1 to 100 characters.'];
                }
            }
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($payload['issue_date'] ?? ''))) {
            $errors[] = ['code' => 'ISSUE_DATE_INVALID', 'path' => '/issue_date', 'message' => 'Issue date must use YYYY-MM-DD.'];
        }
        if (! preg_match('/^[A-Z]{3}$/', (string) ($payload['currency'] ?? ''))) {
            $errors[] = ['code' => 'CURRENCY_INVALID', 'path' => '/currency', 'message' => 'Currency must be a three-letter ISO code.'];
        }

        $rawLines = $payload['lines'] ?? [];
        if (! is_array($rawLines) || count($rawLines) === 0) {
            $errors[] = ['code' => 'LINES_REQUIRED', 'path' => '/lines', 'message' => 'At least one invoice line is required.'];
        } elseif (count($rawLines) > 10_000) {
            $errors[] = ['code' => 'TOO_MANY_LINES', 'path' => '/lines', 'message' => 'An invoice may contain at most 10,000 lines.'];
        }

        $documentType = (string) ($payload['document_type'] ?? '');
        $isCorrectionDoc = in_array($documentType, ['CREDIT_NOTE', 'DEBIT_NOTE'], true);
        $originalReference = $payload['original_document_reference'] ?? null;
        if ($isCorrectionDoc && ! $originalReference) {
            $errors[] = ['code' => 'ORIGINAL_DOCUMENT_REQUIRED', 'path' => '/original_document_reference', 'message' => 'Credit and debit notes must reference the original document.'];
        } elseif ($isCorrectionDoc) {
            if (trim((string) ($originalReference['reason_code'] ?? '')) === '') {
                $errors[] = ['code' => 'CORRECTION_REASON_CODE_REQUIRED', 'path' => '/original_document_reference/reason_code', 'message' => 'A correction reason code is required.'];
            }
            if (mb_strlen(trim((string) ($originalReference['reason'] ?? ''))) < 5) {
                $errors[] = ['code' => 'CORRECTION_REASON_REQUIRED', 'path' => '/original_document_reference/reason', 'message' => 'A correction reason of at least 5 characters is required.'];
            }
        }

        $seenLineNumbers = [];
        $calculatedLines = [];
        foreach (array_slice($rawLines, 0, 10_000) as $index => $line) {
            $path = "/lines/{$index}";
            if (! is_array($line)) {
                $errors[] = ['code' => 'LINE_INVALID', 'path' => $path, 'message' => 'Each invoice line must be an object.'];
                $calculatedLines[] = ['line_number' => $index + 1, 'description' => '', 'quantity' => '0', 'unit_code' => 'EA', 'unit_price' => '0', 'net_amount' => '0', 'tax' => ['category' => 'OTHER', 'rate' => '0', 'taxable_amount' => '0', 'tax_amount' => '0'], 'quantityMicros' => 0, 'unitPriceCents' => 0, 'netAmountCents' => 0, 'taxRateBps' => 0, 'taxAmountCents' => 0];
                continue;
            }

            $tax = $line['tax'] ?? ['category' => 'OTHER', 'rate' => '', 'taxable_amount' => '', 'tax_amount' => ''];
            $quantityMicros = 0;
            $unitPriceCents = 0;
            $suppliedNetCents = 0;
            $suppliedTaxCents = 0;
            $suppliedTaxableCents = 0;
            $taxRateBps = 0;

            try { $quantityMicros = $this->decimalToScaled((string) ($line['quantity'] ?? ''), 6); }
            catch (\Throwable) { $errors[] = ['code' => 'QUANTITY_INVALID', 'path' => "{$path}/quantity", 'message' => 'Quantity must be a non-negative decimal.']; }
            try { $unitPriceCents = $this->decimalToScaled((string) ($line['unit_price'] ?? ''), 2); }
            catch (\Throwable) { $errors[] = ['code' => 'UNIT_PRICE_INVALID', 'path' => "{$path}/unit_price", 'message' => 'Unit price must be a decimal amount.']; }
            try { $suppliedNetCents = $this->decimalToScaled((string) ($line['net_amount'] ?? ''), 2); }
            catch (\Throwable) { $errors[] = ['code' => 'NET_AMOUNT_INVALID', 'path' => "{$path}/net_amount", 'message' => 'Net amount must be a decimal amount.']; }
            try { $suppliedTaxableCents = $this->decimalToScaled((string) ($tax['taxable_amount'] ?? ''), 2); }
            catch (\Throwable) { $errors[] = ['code' => 'TAXABLE_AMOUNT_INVALID', 'path' => "{$path}/tax/taxable_amount", 'message' => 'Taxable amount must be a decimal amount.']; }
            try { $suppliedTaxCents = $this->decimalToScaled((string) ($tax['tax_amount'] ?? ''), 2); }
            catch (\Throwable) { $errors[] = ['code' => 'TAX_AMOUNT_INVALID', 'path' => "{$path}/tax/tax_amount", 'message' => 'Tax amount must be a decimal amount.']; }
            try { $taxRateBps = $this->decimalToScaled((string) ($tax['rate'] ?? ''), 2); }
            catch (\Throwable) { $errors[] = ['code' => 'TAX_RATE_INVALID', 'path' => "{$path}/tax/rate", 'message' => 'Tax rate must be a non-negative percentage.']; }

            $lineNumber = $line['line_number'] ?? null;
            if (! is_int($lineNumber) || $lineNumber < 1) {
                $errors[] = ['code' => 'LINE_NUMBER_INVALID', 'path' => "{$path}/line_number", 'message' => 'Line number must be a positive integer.'];
            } elseif (in_array($lineNumber, $seenLineNumbers, true)) {
                $errors[] = ['code' => 'LINE_NUMBER_DUPLICATE', 'path' => "{$path}/line_number", 'message' => 'Line numbers must be unique within an invoice.'];
            } else {
                $seenLineNumbers[] = $lineNumber;
            }

            $description = trim((string) ($line['description'] ?? ''));
            if ($description === '') {
                $errors[] = ['code' => 'DESCRIPTION_REQUIRED', 'path' => "{$path}/description", 'message' => 'Line description is required.'];
            } elseif (mb_strlen($description) > 1_000) {
                $errors[] = ['code' => 'DESCRIPTION_TOO_LONG', 'path' => "{$path}/description", 'message' => 'Line description must not exceed 1,000 characters.'];
            }
            if ($quantityMicros <= 0) {
                $errors[] = ['code' => 'QUANTITY_NOT_POSITIVE', 'path' => "{$path}/quantity", 'message' => 'Quantity must be greater than zero.'];
            }
            $unitCode = (string) ($line['unit_code'] ?? '');
            if (mb_strlen($unitCode) > 20) {
                $errors[] = ['code' => 'UNIT_CODE_TOO_LONG', 'path' => "{$path}/unit_code", 'message' => 'Unit code must not exceed 20 characters.'];
            }
            if ($taxRateBps < 0 || $taxRateBps > 10_000) {
                $errors[] = ['code' => 'TAX_RATE_OUT_OF_RANGE', 'path' => "{$path}/tax/rate", 'message' => 'Tax rate must be between 0 and 100 percent.'];
            }
            if ($documentType !== 'CREDIT_NOTE' && $this->anyNegative([$unitPriceCents, $suppliedNetCents, $suppliedTaxableCents, $suppliedTaxCents])) {
                $errors[] = ['code' => 'NEGATIVE_AMOUNT_NOT_ALLOWED', 'path' => $path, 'message' => 'Negative amounts require the approved credit-note workflow.'];
            }
            if ($documentType === 'CREDIT_NOTE' && $this->anyPositive([$unitPriceCents, $suppliedNetCents, $suppliedTaxableCents, $suppliedTaxCents])) {
                $errors[] = ['code' => 'CREDIT_NOTE_SIGN_INVALID', 'path' => $path, 'message' => 'Credit-note monetary values must be zero or negative.'];
            }

            $computedNetCents = $this->roundedDivide($quantityMicros * $unitPriceCents, 1_000_000);
            $computedTaxCents = $this->roundedDivide($computedNetCents * $taxRateBps, 10_000);
            if ($computedNetCents !== $suppliedNetCents) {
                $errors[] = ['code' => 'LINE_NET_MISMATCH', 'path' => "{$path}/net_amount", 'message' => 'Expected '.$this->centsToDecimal($computedNetCents).' from quantity and unit price.'];
            }
            if ($suppliedTaxableCents !== $suppliedNetCents) {
                $errors[] = ['code' => 'TAXABLE_AMOUNT_MISMATCH', 'path' => "{$path}/tax/taxable_amount", 'message' => 'Taxable amount must equal the line net amount for the pilot rule set.'];
            }
            if ($computedTaxCents !== $suppliedTaxCents) {
                $errors[] = ['code' => 'LINE_TAX_MISMATCH', 'path' => "{$path}/tax/tax_amount", 'message' => 'Expected '.$this->centsToDecimal($computedTaxCents).' for the supplied rate.'];
            }
            $taxCategory = (string) ($tax['category'] ?? '');
            if (in_array($taxCategory, self::TAX_CATEGORIES_REQUIRING_ZERO_RATE, true) && $taxRateBps !== 0) {
                $errors[] = ['code' => 'ZERO_RATE_REQUIRED', 'path' => "{$path}/tax/rate", 'message' => "{$taxCategory} lines must use a zero rate."];
            }

            $calculatedLines[] = [
                ...$line,
                'tax' => $tax,
                'quantityMicros' => $quantityMicros,
                'unitPriceCents' => $unitPriceCents,
                'netAmountCents' => $computedNetCents,
                'taxRateBps' => $taxRateBps,
                'taxAmountCents' => $computedTaxCents,
            ];
        }

        $lineNetCents = array_sum(array_column($calculatedLines, 'netAmountCents'));
        $taxCents = array_sum(array_column($calculatedLines, 'taxAmountCents'));
        $totalCents = $lineNetCents + $taxCents;

        if ($documentType === 'CREDIT_NOTE' && $totalCents >= 0) {
            $errors[] = ['code' => 'CREDIT_NOTE_TOTAL_INVALID', 'path' => '/totals/payable_amount', 'message' => 'A credit note must reduce the original document with a negative payable total.'];
        }
        if ($documentType === 'DEBIT_NOTE' && $totalCents <= 0) {
            $errors[] = ['code' => 'DEBIT_NOTE_TOTAL_INVALID', 'path' => '/totals/payable_amount', 'message' => 'A debit note must increase the original document with a positive payable total.'];
        }

        $totals = $payload['totals'] ?? [];
        $totalChecks = [
            ['line_net_amount', $lineNetCents, 'Line net total'],
            ['tax_exclusive_amount', $lineNetCents, 'Tax-exclusive total'],
            ['tax_amount', $taxCents, 'VAT total'],
            ['tax_inclusive_amount', $totalCents, 'Tax-inclusive total'],
            ['payable_amount', $totalCents, 'Payable total'],
        ];
        foreach ($totalChecks as [$key, $expected, $label]) {
            try {
                $supplied = $this->decimalToScaled((string) ($totals[$key] ?? ''), 2);
                if ($supplied !== $expected) {
                    $errors[] = ['code' => 'DOCUMENT_TOTAL_MISMATCH', 'path' => "/totals/{$key}", 'message' => "{$label} must be ".$this->centsToDecimal($expected).'.'];
                }
            } catch (\Throwable) {
                $errors[] = ['code' => 'DOCUMENT_TOTAL_INVALID', 'path' => "/totals/{$key}", 'message' => "{$label} must be a decimal amount."];
            }
        }

        if (count($errors) > 0) {
            throw new InvoiceValidationException($errors);
        }

        return ['lineNetCents' => $lineNetCents, 'taxCents' => $taxCents, 'totalCents' => $totalCents, 'lines' => $calculatedLines];
    }

    /**
     * Ported verbatim from lib/domain/invoice.ts's scoreInvoice.
     *
     * @param array<string, mixed> $payload
     * @param array{lineNetCents: int, taxCents: int, totalCents: int, lines: list<array<string, mixed>>} $calculated
     * @return array{level: string, reasons: list<string>}
     */
    public function score(array $payload, array $calculated, bool $buyerRegistered): array
    {
        $reasons = [];
        $points = 0;

        if ($calculated['totalCents'] >= 100_000_000) {
            $points += 80;
            $reasons[] = 'Transaction value exceeds N$1,000,000.';
        } elseif ($calculated['totalCents'] >= 25_000_000) {
            $points += 35;
            $reasons[] = 'High-value transaction exceeds N$250,000.';
        }
        if (! $buyerRegistered) {
            $points += 15;
            $reasons[] = 'Buyer VAT number is not present in the pilot registry.';
        }
        $categories = array_unique(array_map(fn ($line) => $line['tax']['category'] ?? null, $payload['lines'] ?? []));
        if (count($categories) > 1) {
            $points += 10;
            $reasons[] = 'Invoice contains mixed VAT categories.';
        }
        if (($payload['document_type'] ?? null) === 'CREDIT_NOTE') {
            $points += 10;
            $reasons[] = 'Credit note requires linked-document review.';
        }

        $level = $points >= 80 ? 'CRITICAL' : ($points >= 45 ? 'HIGH' : ($points >= 20 ? 'MEDIUM' : 'LOW'));

        return ['level' => $level, 'reasons' => $reasons];
    }

    /** Ported verbatim from lib/domain/invoice.ts's decimalToScaled -- half-up rounding, integer-only, no float parsing anywhere. */
    public function decimalToScaled(string $value, int $scale): int
    {
        if (! preg_match('/^(-?)(0|[1-9][0-9]*)(?:\.([0-9]+))?$/', trim($value), $match)) {
            throw new \InvalidArgumentException("Invalid decimal value: {$value}");
        }
        $negative = $match[1] === '-';
        $whole = $match[2];
        $fraction = $match[3] ?? '';
        $kept = str_pad(mb_substr($fraction, 0, $scale), $scale, '0');

        $result = bcadd(bcmul($whole, bcpow('10', (string) $scale)), $kept === '' ? '0' : $kept);
        if (mb_strlen($fraction) > $scale && (int) $fraction[$scale] >= 5) {
            $result = bcadd($result, '1');
        }
        if ($negative) {
            $result = bcmul($result, '-1');
        }

        if (bccomp($result, (string) PHP_INT_MAX) > 0 || bccomp($result, (string) PHP_INT_MIN) < 0) {
            throw new \OverflowException('Decimal value exceeds the supported range.');
        }

        return (int) $result;
    }

    /** Ported verbatim from lib/domain/invoice.ts's getVatNumber (itself an alias for vatIdentifier). @param array<string, mixed> $party */
    public function getVatNumber(array $party): ?string
    {
        return $this->vatIdentifier($party);
    }

    /** Ported verbatim from lib/domain/invoice.ts's centsToDecimal. */
    public function centsToDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);
        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Ported verbatim from lib/domain/invoice.ts's roundedDivide -- half-up, sign-preserving. */
    private function roundedDivide(int $numerator, int $denominator): int
    {
        $sign = $numerator < 0 ? -1 : 1;
        return $sign * intdiv(abs($numerator) + intdiv($denominator, 2), $denominator);
    }

    /** @param array<string, mixed> $party */
    private function vatIdentifier(array $party): ?string
    {
        foreach ($party['identifiers'] ?? [] as $identifier) {
            if (($identifier['type'] ?? null) === 'VAT_NUMBER') {
                $value = trim((string) ($identifier['value'] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return null;
    }

    /** @param list<int> $values */
    private function anyNegative(array $values): bool
    {
        return count(array_filter($values, fn ($v) => $v < 0)) > 0;
    }

    /** @param list<int> $values */
    private function anyPositive(array $values): bool
    {
        return count(array_filter($values, fn ($v) => $v > 0)) > 0;
    }
}
