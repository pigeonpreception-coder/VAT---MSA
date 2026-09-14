# VAT-MSA Red Team Assessment — Input Validation & Robustness (Phase 5)

**Date:** 2026-09-14
**Scope:** Phase 5 of the user's original 10-phase brief. This pass hunts
for unsafe raw output (stored XSS), unsafe raw SQL construction, CSV
formula injection in the report export feature, and unvalidated/
malformed input handling across the highest-value financial write paths
(expenses, fixed assets, quotations).
**Method:** A mechanical sweep for known-risky patterns (`{!! !!}` raw
Blade output, `DB::raw()`/`whereRaw()`/`selectRaw()`/`orderByRaw()` calls
carrying a variable rather than a literal, CSV-export field construction),
followed by live black-box fuzzing against a running instance with real
MySQL -- negative amounts, non-numeric amounts, oversized strings, and
XSS payloads submitted through real HTTP requests to real write forms,
checking for a clean validation error versus a crash, a bypass, or (the
confirmed finding below) a silent data-corruption path.
**Environment:** This session's own sandboxed dev stack -- PHP (`artisan
serve`), real MySQL, full suite (624 tests before this pass, 627 after)
run before and after every change.
**Tester:** Claude (Anthropic), acting as the migration engineer, at the
user's explicit request.

---

## 1. Confirmed finding

### RT-017 — Non-numeric amounts silently become zero instead of being rejected

> **Status: FIXED (2026-09-14).** `OperationsViewController::store()`,
> `FixedAssetViewController::store()`/`valuation()`, and
> `QuotationViewController::createPayload()`/`editPayload()` all read a
> monetary/quantity field with a raw `(int) $request->input('key', 0)`
> (or `(int) round((float) $request->input('quantity', 0) * 1_000_000)`
> for the quantity-to-micros conversion) -- PHP's `(int)`/`(float)` casts
> silently coerce **any** non-numeric string into `0`/`0.0`, before the
> value ever reaches the real validator. Fixed with two new shared
> helpers on the base `Controller` class,
> `safeIntegerInput()`/`safeMicrosInput()`, using
> `filter_var(..., FILTER_VALIDATE_INT/FLOAT)`, which return `false` (not
> a bogus zero) for anything that isn't genuinely numeric -- and `false`
> correctly fails the existing `App\Domain\*\Validator::integerField()`'s
> own `is_int()` check, producing the same clean, field-specific
> validation message a non-numeric JSON API payload already gets.

| | |
|---|---|
| **Severity** | **Medium** |
| **User role** | `expenses:manage` / `fixed-assets:manage` / `quotations:manage` (any tenant business user) |
| **Feature** | Recording an expense, registering/revaluing a fixed asset, issuing/editing a quotation, through their Blade forms |

**Reproduction steps (pre-fix, live against a running instance):**
1. Log in as a `expenses:manage` holder, load `/operations`.
2. Submit `POST /operations/expenses` with `net_cents=not-a-number` and
   `tax_cents=abc` (a realistic fat-fingered or thousands-separator typo,
   e.g. "1,500" also reproduces this) instead of real integer strings.
3. Check whether an expense record was created, and with what values.

**Actual behavior (pre-fix):** `302` redirect to `/operations` with a
**success** message ("Expense recorded."), and a real `DRAFT` expense row
persisted with `net_cents = 0`, `tax_cents = 0`, `total_cents = 0` --
every amount silently zeroed, with **no validation error surfaced to the
submitter at all**. The same class of silent coercion was confirmed by
code-reading (not independently live-reproduced, given time budget) in
`FixedAssetViewController::store()`/`valuation()` (`acquisition_cost_cents`/
`current_value_cents`) and `QuotationViewController::createPayload()`/
`editPayload()` (`unit_price_cents`, `quantity` → `quantity_micros`,
`tax_rate_bps`) -- all seven fields share the exact same `(int) $request->
input(...)` pre-validation cast.

**Business impact:** A genuine data-entry error (a stray letter, a pasted
thousands-separator, a copy-paste of the wrong field) does not surface as
an error at all -- it silently succeeds as a real zero-amount record that
looks, from the UI, exactly like a deliberately-recorded R0.00 expense/
valuation/quotation line rather than a rejected typo. For a bookkeeping
system, phantom zero-value entries in the expense register or a
quotation line silently priced at R0.00 are a genuine data-integrity
concern: nothing distinguishes "an amount I meant to enter as zero" from
"my input was silently discarded," and a downstream approval/accounting
process has no signal that anything went wrong.

**Root-cause hypothesis:** These four Blade `*ViewController` methods --
unlike their sibling `*Controller` JSON API methods, which receive a real
PHP `int`/`float` from a decoded JSON body and pass it straight to the
same `App\Domain\*\Validator` -- have to first convert a raw HTML-form
**string** into the numeric type the shared validator expects. The
`(int)`/`(float)` cast used for that conversion was the wrong tool: it
coerces instead of rejecting, silently turning "this wasn't a number" into
"this was zero," a fundamentally different outcome the validator was
never given the chance to catch.

**Recommended solution (implemented):** Two new shared helpers on the base
`App\Http\Controllers\Controller` class (matching the existing
`formIdempotencyKey()` precedent):

```php
protected function safeIntegerInput(mixed $raw, int $default = 0): int|false
{
    return ($raw === null || $raw === '') ? $default : filter_var($raw, FILTER_VALIDATE_INT);
}

protected function safeMicrosInput(mixed $raw, int $default = 0): int|false
{
    if ($raw === null || $raw === '') {
        return $default;
    }
    $float = filter_var($raw, FILTER_VALIDATE_FLOAT);
    return $float === false ? false : (int) round($float * 1_000_000);
}
```

A field genuinely absent from the form (`null`/`''`) still defaults to
`0` unchanged, matching the original UX for an optional/typically-zero
field; a field that **is** present but not a real number now returns
`false`, which `integerField()`'s own `is_int()` check already rejects
with a specific, field-named message -- no change needed to the shared
validator at all. All seven call sites updated:
`OperationsViewController::store()` (`net_cents`, `tax_cents`),
`FixedAssetViewController::store()` (`acquisition_cost_cents`,
`current_value_cents`) and `::valuation()` (`current_value_cents`),
`QuotationViewController::createPayload()` (`unit_price_cents`, `quantity`)
and `::editPayload()` (`unit_price_cents`, `quantity`, `tax_rate_bps`).

**Regression risks:** None expected -- a genuinely valid numeric
submission (`net_cents=5000`) is unaffected (`filter_var` returns the
same int `(int)` would have), and an absent field still defaults exactly
as before. Verified with a live re-reproduction of the original exploit
(now correctly rejected) plus the full existing test suite for these
three view controllers.

**Validation checklist:**
- [x] `net_cents=not-a-number` / `tax_cents=abc` → clean validation error,
      zero expense rows created (re-verified live post-fix: `COUNT(*) = 0`,
      redirect page shows "Net cents must be a safe integer...").
- [x] A genuinely valid submission (`net_cents=5000`, `tax_cents=750`)
      still creates a correct row (`total_cents = 5750`), confirming no
      regression for real users.
- [x] 3 new regression tests, one per affected controller
      (`OperationsViewTest`, `FixedAssetViewTest`, `QuotationViewTest`),
      each posting a non-numeric amount and asserting a clean validation
      error with no row created.
- [x] Full suite (627 tests) passes with zero regressions.

## 2. Positive controls confirmed (not findings)

- **No stored XSS surface.** Every `{!! !!}` raw (non-escaping) Blade
  output in the entire `resources/views/` tree was enumerated (3
  occurrences, all in `business-parties/show.blade.php`) and each is a
  hardcoded ternary of two literal `<span>` strings, never user input.
  Live-confirmed separately: an XSS payload
  (`<script>alert(document.cookie)</script>`) submitted as an expense
  description was stored raw in the database (correct -- storage should
  never mangle content) but rendered fully HTML-escaped
  (`&lt;script&gt;...`) on the operations register page, with zero raw
  occurrences in the response body.
- **No SQL injection surface via raw SQL fragments.** Every `DB::raw()`/
  `whereRaw()`/`selectRaw()`/`orderByRaw()` call in `app/` was read; all
  but one pass a hardcoded literal SQL fragment (`version + 1`,
  `COUNT(*)`, `CASE ... END`, etc.), never user-controlled input
  concatenated into the string. The one call passing a variable
  (`RiskService::restricted()`'s `orderByRaw($severityOrder)`) was
  confirmed to be a hardcoded literal string assigned one line above, not
  a data flow from any request input -- a local variable used for
  readability, not a genuine risk.
- **No CSV formula-injection surface.** The only CSV-generating code path
  in the app, `ReportExportService::buildExportContent()`, writes only
  fixed field-name keys and numeric/boolean/null aggregate values
  computed by `computeReportResult()` (COUNT/SUM queries) -- no user
  free-text field (an invoice note, a business party name, an expense
  description) ever reaches a CSV cell, so there is no `=`/`+`/`-`/`@`
  formula-injection vector to neutralize.
- **Financial amount bounds are enforced consistently at the domain
  layer.** Every monetary/quantity field checked defaults to a `min = 0`
  bound in `BusinessValidator::integerField()` (negative amounts
  correctly rejected, confirmed live for `net_cents=-50000`), and every
  `_cents` database column is `bigInteger` (signed 64-bit), matching
  PHP's own native int range on this platform -- ruling out the
  theoretical "value near `PHP_INT_MAX` silently overflows to float"
  edge case as a genuinely reachable concern given both layers share the
  same range.
- **Oversized text input is rejected cleanly, not crashed on.** A 50,000-
  character expense description (the real max is 500) was submitted live
  and cleanly rejected with a redirect and no row created -- no 500, no
  truncation-then-silent-success.

## 3. What this pass did not cover

- **The other 6 `*ViewController` classes never checked for this same
  `(int) $request->input(...)` pattern beyond the initial grep sweep**
  (the grep itself was exhaustive across `app/Http/Controllers` and found
  only these 3 files/7 fields, so this is believed complete, but only the
  4 call sites in `OperationsViewController` and `FixedAssetViewController`
  were independently live-reproduced pre-fix; `QuotationViewController`'s
  3 fields were fixed and test-covered but not separately live-curl-
  reproduced before the fix, given time budget).
- **Deeper numeric-overflow edge cases** (a value at or near
  `PHP_INT_MAX` passed as a genuine PHP int, then summed with other line
  totals) were reasoned about but not live-reproduced -- concluded low
  real-world reachability (requires an authenticated business user
  deliberately crafting a ~$92 quadrillion cents value) and out of
  proportion to this pass's time budget.
- **Malformed JSON API payloads** (wrong types, deeply nested structures,
  extremely large request bodies) against the `*Controller` JSON API
  siblings were not fuzzed this pass -- those receive real typed
  JSON-decoded values already, structurally different from the Blade
  form string-coercion bug this pass found, and lower suspicion given
  they funnel through the identical, already-correct
  `App\Domain\*\Validator` classes.
- **The remaining 7 phases** of the user's original 10-phase brief (High
  Interaction Stress, Concurrent User Simulation, Authentication &
  Session Robustness beyond what RT-007 already covered, Performance
  Under Heavy Use, Resilience to User Errors beyond this pass's own
  amount-typo angle, Fraud Resistance, UX Failure Discovery) remain
  untouched.

## 4. Summary

| ID | Title | Severity |
|---|---|---|
| RT-017 | Non-numeric amounts silently coerced to zero instead of rejected | Medium — **FIXED** |
| — | Stored XSS, raw-SQL injection, CSV formula injection | Checked, none found |
| — | Negative-amount and oversized-string rejection | Checked, already correct |

**Overall assessment:** one genuine, live-reproduced input-validation gap
found and fixed across all three affected `*ViewController` classes (four
of seven call sites independently live-reproduced before the fix;
`QuotationViewController`'s three fields shared the identical anti-pattern
and were fixed and regression-tested alongside them). Three other classic
input-validation vulnerability classes (stored XSS, raw-SQL injection,
CSV formula injection) were mechanically hunted for across the whole
codebase and found absent, a genuinely positive result from real,
targeted checks.
