<?php

namespace App\Support\Invoice;

/** Ported from lib/format.ts's maskInvoiceNumber -- used only by the public, unauthenticated verification surface. */
class InvoiceNumberMask
{
    public static function mask(string $value): string
    {
        if (mb_strlen($value) <= 4) {
            return '****';
        }

        return mb_substr($value, 0, 2).str_repeat('*', min(8, mb_strlen($value) - 4)).mb_substr($value, -2);
    }
}
