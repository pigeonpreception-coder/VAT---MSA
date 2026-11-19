<?php

namespace App\Support\Ui;

/**
 * Turns stored enum codes (PENDING_VERIFICATION, NAMRA_OPERATIONS) into
 * readable text. Str::headline() can't be used: it splits all-caps input
 * into single letters ("A U D I T").
 */
final class Humanize
{
    private const ACRONYMS = [
        'VAT' => 'VAT', 'NAMRA' => 'NamRA', 'ITAS' => 'ITAS', 'API' => 'API', 'POS' => 'POS', 'ERP' => 'ERP',
        'ID' => 'ID', 'HR' => 'HR', 'PAYE' => 'PAYE', 'SMS' => 'SMS', 'PDF' => 'PDF', 'CSV' => 'CSV', 'NAD' => 'NAD', 'SAAS' => 'SaaS',
        'MFA' => 'MFA', 'TIN' => 'TIN', 'SOD' => 'SoD', 'ETARIFF' => 'E-Tariff', 'URL' => 'URL', 'IP' => 'IP',
        'OTP' => 'OTP', 'SLA' => 'SLA', 'KYC' => 'KYC', 'GL' => 'GL', 'IT' => 'IT', 'SBOM' => 'SBOM',
    ];

    /** Sentence case: "PENDING_VERIFICATION" -> "Pending verification". */
    public static function enum(?string $value): string
    {
        return self::convert($value, false);
    }

    /** Title case: "PENDING_VERIFICATION" -> "Pending Verification". */
    public static function title(?string $value): string
    {
        return self::convert($value, true);
    }

    private static function convert(?string $value, bool $titleCase): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '—';
        }

        $words = preg_split('/[_\s]+/', $value) ?: [];
        $out = [];
        foreach ($words as $i => $word) {
            $upper = strtoupper($word);
            if (isset(self::ACRONYMS[$upper])) {
                $out[] = self::ACRONYMS[$upper];

                continue;
            }
            $lower = strtolower($word);
            $out[] = ($titleCase || $i === 0) ? ucfirst($lower) : $lower;
        }

        return implode(' ', $out);
    }
}
