<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * Every write action reached through a Blade form should call this
     * instead of generating `(string) Str::uuid()` inline -- a fresh
     * random key per request silently defeats App\Support\CommandLedger's
     * replay detection, which is the exact bug a red-team pass found
     * across every *ViewController (see docs/MIGRATION_MATRIX.md's
     * "Duplicate-submission hardening" section). The paired
     * <x-idempotency-key /> component renders one stable UUID into a
     * hidden field once per GET request; a double-click or a
     * browser-back-resubmit replays the same already-rendered form, so
     * the same key arrives here and CommandLedger::prior() finds the
     * match. The fallback only fires for a request that genuinely never
     * carried the field (a hand-crafted request, or a JSON API caller
     * hitting this same route directly) -- it deliberately provides zero
     * protection in that case, exactly matching pre-fix behaviour, rather
     * than accepting an attacker-supplied key that could collide with (or
     * be replayed against) another user's own real command.
     */
    protected function formIdempotencyKey(Request $request): string
    {
        $supplied = $request->input('idempotency_key');

        return is_string($supplied) && $supplied !== '' ? $supplied : (string) Str::uuid();
    }

    /**
     * A `*ViewController` reading a monetary/count field from a Blade
     * form should call this instead of `(int) $request->input('key', 0)`
     * -- a red-team pass (2026-09-14, Input Validation & Robustness)
     * found that raw `(int)` cast silently coerces any non-numeric
     * submission ("not-a-number", "1,500" with a thousands separator, a
     * fat-fingered typo) into `0` rather than being rejected, so a
     * genuine data-entry error passed validation as a legitimate
     * zero-amount record with no error shown to the submitter at all --
     * confirmed live: a `net_cents=not-a-number` expense submission
     * created a real `DRAFT` expense row with every amount silently
     * zeroed. `filter_var(..., FILTER_VALIDATE_INT)` returns `false` (not
     * `0`) for anything that isn't a genuine integer, and `false` fails
     * `App\Domain\*\*Validator::integerField()`'s own `is_int()` check
     * exactly like a non-numeric JSON API payload already would --
     * turning a silent data-corruption bug into the same clean,
     * field-specific validation message every other malformed input in
     * this codebase already gets. `$raw === null || $raw === ''` still
     * returns `$default` unchanged, preserving this method's original
     * "field not present on the form" behaviour for an optional amount.
     */
    protected function safeIntegerInput(mixed $raw, int $default = 0): int|false
    {
        return ($raw === null || $raw === '') ? $default : filter_var($raw, FILTER_VALIDATE_INT);
    }

    /**
     * Same defect, same fix, for a quantity field submitted as a decimal
     * (e.g. "12.5") and converted to a `_micros` integer column (`* 1_000_000`)
     * -- the pre-existing `(int) round((float) $request->input('quantity', 0) * 1_000_000)`
     * cast silently turned "not-a-number" into `0` the same way. Returns
     * `false` (not a bogus rounded value) for anything that isn't a
     * genuine number, so the validator's own `is_int()` check catches it.
     */
    protected function safeMicrosInput(mixed $raw, int $default = 0): int|false
    {
        if ($raw === null || $raw === '') {
            return $default;
        }
        $float = filter_var($raw, FILTER_VALIDATE_FLOAT);

        return $float === false ? false : (int) round($float * 1_000_000);
    }

    /**
     * RT-017 follow-up (2026-09-15): the same silent-zero coercion RT-017
     * fixed in OperationsViewController/FixedAssetViewController/
     * QuotationViewController turned up again, unnoticed by that pass's
     * own grep sweep (which searched for a literal `(int) $request->
     * input(...)` cast) because four other controllers -- AuditCaseView-
     * Controller, DisputeViewController, ObligationViewController,
     * VatLifecycleViewController -- each carried their own identically-
     * named private `centsFromDecimal()` helper doing
     * `(int) round(((float) $amount) * 100)`, the exact same PHP cast
     * behaviour under a different name. Same fix, same contract as
     * safeIntegerInput/safeMicrosInput: `false` for anything that isn't a
     * genuine number, so the existing `is_int()`/`is_numeric()` checks in
     * ComplianceValidator::safeInt() and VatLifecycleValidator's own
     * amount_cents check reject it cleanly instead of silently zeroing it.
     */
    protected function safeDecimalCentsInput(mixed $raw, int $default = 0): int|false
    {
        if ($raw === null || $raw === '') {
            return $default;
        }
        $float = filter_var($raw, FILTER_VALIDATE_FLOAT);

        return $float === false ? false : (int) round($float * 100);
    }
}
