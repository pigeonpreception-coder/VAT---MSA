<?php

namespace App\Support\Invoice;

use App\Models\VatRule;

/**
 * Ported from lib/data/vat-rule-repository.ts's getApplicableVatRule --
 * EvaluateVAT's core resolution: the single APPROVED rule governing a tax
 * category as of a given date, or null if none is bound. Single-sourced
 * here rather than duplicated, exactly as the source's own comment
 * requires ("Callers ... must fail closed on null -- never assume a
 * default rate"): both InvoiceService::submit (Phase 9) and
 * VatRuleService::evaluate (the standalone route this class was extracted
 * for) call this same method.
 */
class VatRuleResolver
{
    private const COUNTRY = 'NA';

    public static function applicable(string $taxCategory, string $isoDate): ?VatRule
    {
        return VatRule::where('tax_category', $taxCategory)->where('country', self::COUNTRY)->where('status', 'APPROVED')
            ->where('effective_from', '<=', $isoDate)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $isoDate))
            ->orderByDesc('effective_from')->first();
    }
}
