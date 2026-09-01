<?php

namespace App\Domain\VatLifecycle;

use App\Exceptions\VatLifecycleValidationException;

/**
 * Direct port of lib/domain/vat-lifecycle.ts's normalizeAndValidateAdjustment/
 * calculateReturnPosition/validateDecisionComment -- the VAT-return-
 * generation prerequisite Phase 9 deferred and Phase 11's refund slice was
 * blocked on (see docs/MIGRATION_MATRIX.md). Every validate* function
 * throws VatLifecycleValidationException (a list of {code, path, message})
 * on failure, matching the source's own VatLifecycleValidationError exactly.
 */
class VatLifecycleValidator
{
    private const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:-]{1,99}$/';
    private const REASON_PATTERN = '/^[A-Z0-9][A-Z0-9_-]{1,39}$/';
    private const ADJUSTMENT_TYPES = ['OUTPUT_TAX', 'INPUT_TAX', 'NET_PAYABLE'];
    private const DIRECTIONS = ['INCREASE', 'DECREASE'];

    private static function text(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    /** @return array{schema_version: string, adjustment_type: string, direction: string, amount_cents: int, reason_code: string, explanation: string, evidence_document_id: ?string} */
    public static function adjustment(mixed $payload): array
    {
        if (! is_array($payload) || array_is_list($payload)) {
            throw new VatLifecycleValidationException([
                ['code' => 'DOCUMENT_INVALID', 'path' => '/', 'message' => 'The request body must be an adjustment object.'],
            ]);
        }
        $messages = [];
        if (($payload['schema_version'] ?? null) !== '1.0.0') {
            $messages[] = ['code' => 'SCHEMA_VERSION_UNSUPPORTED', 'path' => '/schema_version', 'message' => 'schema_version must be 1.0.0.'];
        }
        $adjustmentType = mb_strtoupper(self::text($payload['adjustment_type'] ?? null));
        if (! in_array($adjustmentType, self::ADJUSTMENT_TYPES, true)) {
            $messages[] = ['code' => 'ADJUSTMENT_TYPE_INVALID', 'path' => '/adjustment_type', 'message' => 'Select a supported VAT adjustment type.'];
        }
        $direction = mb_strtoupper(self::text($payload['direction'] ?? null));
        if (! in_array($direction, self::DIRECTIONS, true)) {
            $messages[] = ['code' => 'DIRECTION_INVALID', 'path' => '/direction', 'message' => 'Direction must be INCREASE or DECREASE.'];
        }
        $amountCents = $payload['amount_cents'] ?? null;
        $amountCentsValid = is_numeric($amountCents) && (int) $amountCents == $amountCents && (int) $amountCents > 0;
        if (! $amountCentsValid) {
            $messages[] = ['code' => 'AMOUNT_INVALID', 'path' => '/amount_cents', 'message' => 'Amount cents must be a positive safe integer.'];
        }
        $reasonCode = mb_strtoupper(self::text($payload['reason_code'] ?? null));
        if (! preg_match(self::REASON_PATTERN, $reasonCode)) {
            $messages[] = ['code' => 'REASON_CODE_INVALID', 'path' => '/reason_code', 'message' => 'Reason code must contain 2 to 40 uppercase letters, numbers, underscores or hyphens.'];
        }
        $explanation = self::text($payload['explanation'] ?? null);
        if (mb_strlen($explanation) < 10 || mb_strlen($explanation) > 2_000) {
            $messages[] = ['code' => 'EXPLANATION_INVALID', 'path' => '/explanation', 'message' => 'Explanation must contain 10 to 2000 characters.'];
        }
        $evidenceDocumentId = self::text($payload['evidence_document_id'] ?? null) ?: null;
        if ($evidenceDocumentId !== null && ! preg_match(self::ID_PATTERN, $evidenceDocumentId)) {
            $messages[] = ['code' => 'EVIDENCE_ID_INVALID', 'path' => '/evidence_document_id', 'message' => 'Evidence document id is invalid.'];
        }
        if ($messages) {
            throw new VatLifecycleValidationException($messages);
        }

        return [
            'schema_version' => '1.0.0', 'adjustment_type' => $adjustmentType, 'direction' => $direction,
            'amount_cents' => (int) $amountCents, 'reason_code' => $reasonCode, 'explanation' => $explanation,
            'evidence_document_id' => $evidenceDocumentId,
        ];
    }

    /** @param list<int> $values */
    private static function checkedSum(array $values, string $path): int
    {
        foreach ($values as $amount) {
            if (! is_int($amount) || $amount < 0) {
                throw new VatLifecycleValidationException([
                    ['code' => 'LEDGER_AMOUNT_INVALID', 'path' => $path, 'message' => 'Ledger calculation encountered an invalid or overflowing integer amount.'],
                ]);
            }
        }
        $sum = array_sum($values);
        if (! is_int($sum)) {
            throw new VatLifecycleValidationException([
                ['code' => 'LEDGER_AMOUNT_INVALID', 'path' => $path, 'message' => 'Ledger calculation encountered an invalid or overflowing integer amount.'],
            ]);
        }

        return $sum;
    }

    /**
     * Ported from calculateReturnPosition. `$outputEntries`/`$inputEntries`
     * are lists of {id, amount_cents}; `$adjustments` is a list of
     * {id, adjustment_type, direction, amount_cents} (already-APPROVED rows
     * only -- the caller filters by status before calling this).
     *
     * @param list<array{id: string, amount_cents: int}> $outputEntries
     * @param list<array{id: string, amount_cents: int}> $inputEntries
     * @param list<array{id: string, adjustment_type: string, direction: string, amount_cents: int}> $adjustments
     * @return array{outputTaxCents: int, inputTaxCents: int, adjustmentCents: int, netPayableCents: int, outputSourceCount: int, inputSourceCount: int, adjustmentSourceCount: int}
     */
    public static function calculateReturnPosition(array $outputEntries, array $inputEntries, array $adjustments): array
    {
        $baseOutput = self::checkedSum(array_map(fn ($e) => (int) $e['amount_cents'], $outputEntries), '/output_entries');
        $baseInput = self::checkedSum(array_map(fn ($e) => (int) $e['amount_cents'], $inputEntries), '/input_entries');
        $outputTaxCents = $baseOutput;
        $inputTaxCents = $baseInput;
        $directNetAdjustment = 0;
        foreach ($adjustments as $adjustment) {
            $amount = $adjustment['amount_cents'];
            if (! in_array($adjustment['adjustment_type'], self::ADJUSTMENT_TYPES, true)
                || ! in_array($adjustment['direction'], self::DIRECTIONS, true)
                || ! is_int($amount) || $amount <= 0) {
                throw new VatLifecycleValidationException([
                    ['code' => 'ADJUSTMENT_STATE_INVALID', 'path' => "/adjustments/{$adjustment['id']}", 'message' => 'An approved adjustment has invalid calculation state.'],
                ]);
            }
            $signed = $adjustment['direction'] === 'INCREASE' ? $amount : -$amount;
            if ($adjustment['adjustment_type'] === 'OUTPUT_TAX') {
                $outputTaxCents += $signed;
            } elseif ($adjustment['adjustment_type'] === 'INPUT_TAX') {
                $inputTaxCents += $signed;
            } else {
                $directNetAdjustment += $signed;
            }
        }
        if ($outputTaxCents < 0 || $inputTaxCents < 0) {
            throw new VatLifecycleValidationException([
                ['code' => 'ADJUSTMENT_UNDERFLOW', 'path' => '/adjustments', 'message' => 'Approved adjustments cannot reduce output or input VAT below zero.'],
            ]);
        }
        $adjustmentCents = ($outputTaxCents - $baseOutput) - ($inputTaxCents - $baseInput) + $directNetAdjustment;
        $netPayableCents = $outputTaxCents - $inputTaxCents + $directNetAdjustment;

        return [
            'outputTaxCents' => $outputTaxCents, 'inputTaxCents' => $inputTaxCents,
            'adjustmentCents' => $adjustmentCents, 'netPayableCents' => $netPayableCents,
            'outputSourceCount' => count($outputEntries), 'inputSourceCount' => count($inputEntries),
            'adjustmentSourceCount' => count($adjustments),
        ];
    }

    public static function decisionComment(mixed $value): string
    {
        $comment = self::text($value);
        if (mb_strlen($comment) < 5 || mb_strlen($comment) > 1_000) {
            throw new VatLifecycleValidationException([
                ['code' => 'DECISION_COMMENT_INVALID', 'path' => '/comment', 'message' => 'Decision comment must contain 5 to 1000 characters.'],
            ]);
        }

        return $comment;
    }
}
