<?php

namespace App\Domain\VatRule;

use App\Exceptions\VatRuleValidationException;

/**
 * Direct port of lib/domain/vat-rules.ts's normalizeVatRuleProposal/
 * normalizeVatRuleApproval/normalizeVatRuleEvaluationQuery -- the standalone
 * VAT-rule evaluate/propose/approve routes, the last narrow gap Phase 9
 * (invoices and VAT) deferred. Every normalize* function throws
 * VatRuleValidationException (a list of {code, path, message}) on failure,
 * matching the source's own VatRuleValidationError exactly.
 */
class VatRuleValidator
{
    public const TAX_CATEGORIES = ['STANDARD', 'ZERO_RATED', 'EXEMPT', 'OUTSIDE_SCOPE', 'REVERSE_CHARGE', 'OTHER'];

    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /** @return array{taxCategory: string, rateBps: int, effectiveFrom: string, reason: string} */
    public static function proposal(mixed $input): array
    {
        if (! is_array($input) || array_is_list($input)) {
            throw new VatRuleValidationException([
                ['code' => 'DOCUMENT_INVALID', 'path' => '/', 'message' => 'A VAT rule proposal object is required.'],
            ]);
        }
        $messages = [];
        $taxCategory = mb_strtoupper(self::text($input['tax_category'] ?? null));
        if (! in_array($taxCategory, self::TAX_CATEGORIES, true)) {
            $messages[] = ['code' => 'TAX_CATEGORY_INVALID', 'path' => '/tax_category', 'message' => 'tax_category must be one of: '.implode(', ', self::TAX_CATEGORIES).'.'];
        }
        $rateBpsRaw = $input['rate_bps'] ?? null;
        $rateBpsValid = is_numeric($rateBpsRaw) && (int) $rateBpsRaw == $rateBpsRaw && (int) $rateBpsRaw >= 0 && (int) $rateBpsRaw <= 10_000;
        if (! $rateBpsValid) {
            $messages[] = ['code' => 'RATE_INVALID', 'path' => '/rate_bps', 'message' => 'rate_bps must be an integer between 0 and 10000 (basis points; 1500 = 15%).'];
        }
        $effectiveFrom = self::text($input['effective_from'] ?? null);
        if (! preg_match(self::DATE_PATTERN, $effectiveFrom)) {
            $messages[] = ['code' => 'EFFECTIVE_FROM_INVALID', 'path' => '/effective_from', 'message' => 'effective_from must use YYYY-MM-DD.'];
        }
        $reason = trim((string) preg_replace('/\s+/', ' ', self::text($input['reason'] ?? null)));
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 400) {
            $messages[] = ['code' => 'REASON_INVALID', 'path' => '/reason', 'message' => 'Provide a 10 to 400 character statutory basis for this rate.'];
        }
        if ($messages) {
            throw new VatRuleValidationException($messages);
        }

        return ['taxCategory' => $taxCategory, 'rateBps' => (int) $rateBpsRaw, 'effectiveFrom' => $effectiveFrom, 'reason' => $reason];
    }

    /** @return array{reason: string} */
    public static function approval(mixed $input): array
    {
        if (! is_array($input) || array_is_list($input)) {
            throw new VatRuleValidationException([
                ['code' => 'DOCUMENT_INVALID', 'path' => '/', 'message' => 'A VAT rule approval object is required.'],
            ]);
        }
        $reason = trim((string) preg_replace('/\s+/', ' ', self::text($input['reason'] ?? null)));
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 240) {
            throw new VatRuleValidationException([
                ['code' => 'REASON_INVALID', 'path' => '/reason', 'message' => 'Provide a 5 to 240 character approval reason.'],
            ]);
        }

        return ['reason' => $reason];
    }

    /** @return array{taxCategory: string, effectiveDate: string} */
    public static function evaluationQuery(mixed $taxCategoryInput, mixed $dateInput): array
    {
        $messages = [];
        $taxCategory = mb_strtoupper(self::text($taxCategoryInput));
        if (! in_array($taxCategory, self::TAX_CATEGORIES, true)) {
            $messages[] = ['code' => 'TAX_CATEGORY_INVALID', 'path' => '/tax_category', 'message' => 'tax_category must be one of: '.implode(', ', self::TAX_CATEGORIES).'.'];
        }
        $effectiveDate = self::text($dateInput);
        if (! preg_match(self::DATE_PATTERN, $effectiveDate)) {
            $messages[] = ['code' => 'DATE_INVALID', 'path' => '/date', 'message' => 'date must use YYYY-MM-DD.'];
        }
        if ($messages) {
            throw new VatRuleValidationException($messages);
        }

        return ['taxCategory' => $taxCategory, 'effectiveDate' => $effectiveDate];
    }
}
