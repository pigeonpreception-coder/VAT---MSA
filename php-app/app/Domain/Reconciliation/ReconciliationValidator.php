<?php

namespace App\Domain\Reconciliation;

use App\Exceptions\ReconciliationValidationException;
use Illuminate\Http\Request;

/**
 * Direct port of lib/domain/reconciliation.ts -- Module 3 Phase A/B: the
 * reconciliation matching engine and its work-queue query normalization.
 * Pure validation and the RunMatch decision logic, no DB access.
 */
class ReconciliationValidator
{
    private const EXCEPTION_STATUSES = ['OPEN', 'ASSIGNED', 'RESOLVED'];
    private const EXCEPTION_SEVERITIES = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];
    private const MAX_WORK_QUEUE_LIMIT = 200;
    private const DEFAULT_WORK_QUEUE_LIMIT = 50;

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /** @return array{officer_id: string} */
    public static function exceptionAssignment(array $payload): array
    {
        $officerId = self::text($payload['officer_id'] ?? null);
        if ($officerId === '') {
            throw new ReconciliationValidationException('OFFICER_ID_REQUIRED', 'officer_id is required.');
        }

        return ['officer_id' => $officerId];
    }

    /** @return array{notes: string} */
    public static function exceptionResolution(array $payload): array
    {
        $notes = trim(preg_replace('/\s+/', ' ', self::text($payload['notes'] ?? null)));
        if (mb_strlen($notes) < 10 || mb_strlen($notes) > 400) {
            throw new ReconciliationValidationException('NOTES_INVALID', 'Provide 10 to 400 characters describing how this exception was resolved.');
        }

        return ['notes' => $notes];
    }

    /**
     * Module 3 Phase B GetWorkQueue: the filter/status/officer/age
     * predicates a real reconciliation work queue needs. Pagination is
     * designed in from the start (bounded limit, explicit offset) rather
     * than retrofitted later, per source's own watch-out note.
     *
     * @return array{status: ?string, severity: ?string, assigned_officer_id: ?string, unassigned_only: bool, min_age_days: ?int, max_age_days: ?int, limit: int, offset: int}
     */
    public static function workQueueQuery(Request $request): array
    {
        $status = $request->query('status') ? mb_strtoupper(trim((string) $request->query('status'))) : null;
        if ($status !== null && ! in_array($status, self::EXCEPTION_STATUSES, true)) {
            throw new ReconciliationValidationException('STATUS_INVALID', 'status must be one of: '.implode(', ', self::EXCEPTION_STATUSES).'.');
        }

        $severity = $request->query('severity') ? mb_strtoupper(trim((string) $request->query('severity'))) : null;
        if ($severity !== null && ! in_array($severity, self::EXCEPTION_SEVERITIES, true)) {
            throw new ReconciliationValidationException('SEVERITY_INVALID', 'severity must be one of: '.implode(', ', self::EXCEPTION_SEVERITIES).'.');
        }

        $assignedOfficerId = $request->query('assigned_officer_id') ? trim((string) $request->query('assigned_officer_id')) : null;
        $unassignedOnly = $request->query('unassigned_only') === 'true';
        if ($assignedOfficerId !== null && $unassignedOnly) {
            throw new ReconciliationValidationException('ASSIGNMENT_FILTER_CONFLICT', 'assigned_officer_id and unassigned_only=true cannot both be set.');
        }

        $minAgeDays = self::parseAgeDays($request->query('min_age_days'), 'min_age_days');
        $maxAgeDays = self::parseAgeDays($request->query('max_age_days'), 'max_age_days');
        if ($minAgeDays !== null && $maxAgeDays !== null && $minAgeDays > $maxAgeDays) {
            throw new ReconciliationValidationException('AGE_RANGE_INVALID', 'min_age_days must not exceed max_age_days.');
        }

        $limit = self::DEFAULT_WORK_QUEUE_LIMIT;
        if ($request->query('limit') !== null) {
            $raw = $request->query('limit');
            if (! ctype_digit((string) $raw) || (int) $raw < 1 || (int) $raw > self::MAX_WORK_QUEUE_LIMIT) {
                throw new ReconciliationValidationException('LIMIT_INVALID', 'limit must be an integer between 1 and '.self::MAX_WORK_QUEUE_LIMIT.'.');
            }
            $limit = (int) $raw;
        }

        $offset = 0;
        if ($request->query('offset') !== null) {
            $raw = $request->query('offset');
            if (! ctype_digit((string) $raw)) {
                throw new ReconciliationValidationException('OFFSET_INVALID', 'offset must be a non-negative integer.');
            }
            $offset = (int) $raw;
        }

        return [
            'status' => $status, 'severity' => $severity, 'assigned_officer_id' => $assignedOfficerId,
            'unassigned_only' => $unassignedOnly, 'min_age_days' => $minAgeDays, 'max_age_days' => $maxAgeDays,
            'limit' => $limit, 'offset' => $offset,
        ];
    }

    private static function parseAgeDays(mixed $raw, string $field): ?int
    {
        if ($raw === null) {
            return null;
        }
        if (! ctype_digit((string) $raw)) {
            throw new ReconciliationValidationException('AGE_INVALID', "{$field} must be a non-negative integer.");
        }

        return (int) $raw;
    }

    /**
     * RunMatch's decision logic: given what the invoice declares and what
     * was actually posted, does everything tie out? Checks independently
     * of one another so every discrepancy is reported, not just the first:
     *  1. The invoice's own OUTPUT_VAT posting equals its declared tax amount.
     *  2. If it has an identified buyer, an equal INPUT_VAT posting exists --
     *     and if it does NOT, that no INPUT_VAT posting leaked through anyway
     *     (re-verifying the unidentified-buyer guarantee as an ongoing
     *     control, not just a one-time code review).
     *  3. If the invoice is CANCELLED, an equal reversing OUTPUT_VAT
     *     posting exists.
     *
     * @return array{status: string, mismatches: list<string>}
     */
    public static function evaluateInvoiceMatch(
        int $invoiceTaxCents,
        ?int $outputVatLedgerCents,
        bool $hasIdentifiedBuyer,
        ?int $inputVatLedgerCents,
        bool $isCancelled,
        ?int $cancellationOutputVatLedgerCents,
    ): array {
        $mismatches = [];
        $expected = abs($invoiceTaxCents);

        if ($outputVatLedgerCents === null) {
            $mismatches[] = "No OUTPUT_VAT ledger entry was found for this invoice's certification transaction.";
        } elseif ($outputVatLedgerCents !== $expected) {
            $mismatches[] = "The OUTPUT_VAT ledger entry ({$outputVatLedgerCents}) does not equal the invoice's declared tax amount ({$expected}).";
        }

        if ($hasIdentifiedBuyer) {
            if ($inputVatLedgerCents === null) {
                $mismatches[] = 'The invoice has an identified buyer but no INPUT_VAT ledger entry was found.';
            } elseif ($inputVatLedgerCents !== $expected) {
                $mismatches[] = "The INPUT_VAT ledger entry ({$inputVatLedgerCents}) does not equal the invoice's declared tax amount ({$expected}).";
            }
        } elseif ($inputVatLedgerCents !== null) {
            $mismatches[] = 'An INPUT_VAT ledger entry exists despite the invoice having no identified buyer, violating the unidentified-buyer guarantee.';
        }

        if ($isCancelled) {
            if ($cancellationOutputVatLedgerCents === null) {
                $mismatches[] = 'The invoice is CANCELLED but no reversing OUTPUT_VAT ledger entry was found.';
            } elseif ($cancellationOutputVatLedgerCents !== $expected) {
                $mismatches[] = "The cancellation's reversing OUTPUT_VAT entry ({$cancellationOutputVatLedgerCents}) does not equal the invoice's declared tax amount ({$expected}).";
            }
        }

        return ['status' => count($mismatches) ? 'EXCEPTION' : 'MATCHED', 'mismatches' => $mismatches];
    }
}
