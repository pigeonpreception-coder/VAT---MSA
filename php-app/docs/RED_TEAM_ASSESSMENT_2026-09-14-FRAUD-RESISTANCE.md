# VAT-MSA Red Team Assessment — Fraud Resistance (Phase 6)

**Date:** 2026-09-14
**Scope:** Phase 6 of the user's original 10-phase brief. This pass asks
whether the invoice-certification path resists a taxpayer manufacturing
zero-substance, government-certified "transactions" against themselves,
rather than only checking that legitimately-different-party invoices are
computed and authorised correctly (already covered by the existing
`InvoiceCertificationTest` suite and prior passes).
**Method:** Read `InvoiceService::submit()` end-to-end and
`InvoiceCalculator::score()` (the risk engine) to map every check actually
performed against the supplier/customer relationship, then live-reproduced
a candidate gap with a real PHPUnit test hitting the real
`POST /api/v1/invoices` endpoint against real MySQL -- first with a large
amount (to see whether the risk engine's own value-based thresholds
coincidentally caught it), then with an ordinary amount to isolate the
actual, clean-bypass vulnerability from that coincidental flag.
**Environment:** This session's own sandboxed dev stack -- PHP (`artisan
serve`), real MySQL, full suite (627 tests before this pass, 628 after) run
before and after the change.
**Tester:** Claude (Anthropic), acting as the migration engineer, at the
user's explicit request.

---

## 1. Confirmed finding

### RT-018 — A taxpayer could certify an invoice to themselves (self-dealing) with zero detection

> **Status: FIXED (2026-09-14).** `InvoiceService::submit()` resolves the
> supplier and customer independently via `resolveCapableTaxpayer()` but
> never compared the two resolved `Taxpayer` IDs. `InvoiceCalculator::score()`
> (the sole gate deciding `risk_level`/`EXCEPTION` status) has no awareness
> of the supplier/customer relationship at all -- its checks are purely
> value- and category-based (total amount thresholds, unregistered buyer,
> mixed VAT categories, credit-note document type). A registered taxpayer
> submitting an invoice where their own VAT number appears as both supplier
> and customer was certified exactly like a legitimate transaction between
> two independent parties. Fixed with an explicit same-taxpayer check
> inserted immediately after customer resolution, failing closed with a
> dedicated `InvoiceValidationException` before any row is written.

| | |
|---|---|
| **Severity** | **High** |
| **User role** | Any `invoices:manage`-equivalent taxpayer user (e.g. `TAXPAYER_OWNER`) acting for their own registered taxpayer |
| **Feature** | Invoice certification, `POST /api/v1/invoices` |

**Reproduction steps (pre-fix, live against a running instance with real
MySQL, via PHPUnit's `postJson` -- real HTTP through the full middleware/
controller/service stack, not a unit-level call):**

1. Register one taxpayer/organisation (`VAT-SELF-0001`) with both `BUYER`
   and `SELLER` capability (the standard dynamic-capability grant every
   trading party in this system receives) and its `TAXPAYER_OWNER` user.
2. As that owner, `POST /api/v1/invoices` with `supplier.identifiers` and
   `customer.identifiers` **both** set to `VAT-SELF-0001` -- i.e. the buyer
   and seller on the invoice are the exact same legal taxpayer -- with an
   ordinary N$1,150.00 total (well under every risk-engine amount
   threshold), a real approved VAT rule, and a real, non-colliding invoice
   number.
3. Observe the response and the resulting database rows.

**Actual behavior (pre-fix):**
- **First probe (N$1,150,000 total):** `201 Created`,
  `status: CERTIFIED`, `processing_status: EXCEPTION`,
  `risk_level: CRITICAL` -- but tracing this through `InvoiceCalculator::
  score()` confirmed the flag was **coincidental**: it came purely from the
  `totalCents >= 100_000_000` (N$1,000,000) amount threshold (+80 points),
  with zero contribution from, or even awareness of, the fact that supplier
  and customer were the same party. A self-invoice under that threshold
  would not trigger this at all.
- **Second probe, ordinary amount (N$1,150.00 total):** `201 Created`,
  `status: CERTIFIED`, `processing_status: MATCHED`, `risk_level: LOW` --
  fully undetected. A real `certificate_id`, `verification_token`, and
  government-style `verification_url` were issued, exactly as for a
  legitimate two-party transaction. A matching `OUTPUT_VAT`/`CREDIT` ledger
  entry and `INPUT_VAT`/`DEBIT` ledger entry were both created for
  `VAT-SELF-0001`'s own `taxpayer_id`, in the same VAT period (since
  supplier and customer are the same party, this specific single-invoice
  pattern is VAT-neutral on that taxpayer's own return -- the output
  payable and input claimable both increase by the identical amount, so a
  lone self-invoice does not directly manufacture a refund by itself).

**Business impact:** Namibia's VAT-MSA is designed to be the authoritative,
government-certified record of real economic transactions -- every
certified invoice carries a real verification URL and QR payload meant to
prove a genuine supply occurred between two parties. Allowing a taxpayer to
certify a "sale" to themselves breaks that guarantee at its root:
- **Turnover/transaction-volume inflation.** A taxpayer could manufacture
  an arbitrary number of government-certified, zero-substance
  "transactions" against themselves to inflate apparent business activity
  (relevant to loan applications, tender qualification thresholds, investor
  due diligence, or any other process that trusts a VAT-MSA certification
  as evidence of real trade).
- **A building block for more sophisticated schemes.** While one lone
  self-invoice is VAT-neutral on its own (output and input land in the same
  period for the same taxpayer), the underlying self-dealing check being
  entirely absent means nothing stops it from being combined with other
  primitives this session already found and fixed elsewhere (e.g. it
  removes one of the natural signals a NamRA auditor would otherwise use to
  flag suspicious activity) or with future changes that split the
  supplier/customer periods apart (e.g. via a correction lineage).
- **Erodes the core trust property of the system.** The entire point of a
  government invoice-certification platform is that "certified" means "a
  real transaction between two real, distinct parties happened" -- a
  self-invoice silently certifying otherwise is a direct breach of that
  guarantee, independent of whether a specific downstream refund exploit
  can be chained from it today.

**Root-cause hypothesis:** `InvoiceService::submit()` correctly resolves
the supplier and customer as two independent lookups (`resolveCapableTaxpayer()`
called once per role) and correctly enforces tenant-scope on the supplier
(`TenantScope::requireTaxpayer($actor, $supplier->id)`), but never closes
the loop by comparing the two resolved IDs against each other. Because
VAT-MSA's own dynamic capability model allows a single organisation to hold
*both* `BUYER` and `SELLER` capability simultaneously (the correct, common
case for most real businesses, which are frequently both a buyer and a
seller), the system has no structural reason to assume supplier ≠ customer
-- it has to be checked explicitly, and it wasn't. Separately,
`InvoiceCalculator::score()`'s risk model was built purely around
transaction *value* and *category* signals (large amounts, unregistered
buyers, mixed tax categories, credit notes) -- a self-dealing relationship
between the two named parties was simply never in scope for that scorer,
so even the "flag it for manual review" fallback path provided no coverage
either.

**Recommended solution (implemented):** An explicit same-taxpayer check
in `InvoiceService::submit()`, inserted immediately after customer
resolution and before any duplicate/collision checks or database writes:

```php
$customer = $customerVat ? $this->resolveCapableTaxpayer($customerVat, 'BUYER', $now) : null;

if ($customer && $customer->id === $supplier->id) {
    throw new InvoiceValidationException([
        ['code' => 'SELF_DEALING_NOT_PERMITTED', 'path' => '/customer/identifiers', 'message' => 'Supplier and customer cannot be the same taxpayer.'],
    ]);
}
```

This follows the exact established pattern already used in this same
method for `NO_APPROVED_VAT_RULE`/`VAT_RATE_RULE_MISMATCH`/
`SUPPLIER_NOT_AUTHORISED` -- fails closed with a specific, actionable
error code before the transaction, ledger entries, certificate, or outbox
event are ever written. An unregistered-buyer invoice (`$customer === null`)
is correctly unaffected, since there is no second party to compare against
in that case (that scenario is already separately flagged as
`UNREGISTERED_BUYER` by the existing reconciliation-exception path).

**Regression risks:** None expected for legitimate traffic -- every real
transaction in the existing test suite involves two genuinely distinct
taxpayers, and the new check only rejects the specific case where the
resolved supplier and customer `Taxpayer` IDs are identical. Verified with
a live re-reproduction of the original exploit (now correctly rejected)
plus the full existing test suite.

**Validation checklist:**
- [x] Supplier and customer identifiers both resolving to the same
      taxpayer → `422` with `errors.0.code == SELF_DEALING_NOT_PERMITTED`,
      zero invoice/ledger rows created (re-verified live post-fix via the
      permanent regression test, which asserts both
      `assertDatabaseMissing('invoices', ...)` and
      `assertDatabaseMissing('ledger_entries', ['taxpayer_id' => ...])`).
- [x] A genuine two-party invoice (the existing, large `InvoiceCertificationTest`
      suite) is entirely unaffected -- confirmed via the full suite run.
- [x] An unregistered-buyer invoice (`customer === null`) is unaffected,
      since the new check only fires when a customer taxpayer actually
      resolves.
- [x] 1 new permanent regression test added to
      `tests/Feature/Invoice/InvoiceCertificationTest.php`.
- [x] Full suite (628 tests) passes with zero regressions.

## 2. Positive controls confirmed (not findings)

- **Tenant-scope enforcement on the supplier side is correct and
  unaffected.** `TenantScope::requireTaxpayer($actor, $supplier->id)`
  already correctly prevents a user from submitting on behalf of a
  supplier they are not scoped to (confirmed by the existing
  `test_a_user_scoped_to_a_different_taxpayer_cannot_submit_on_behalf_of_the_supplier`
  test) -- this pass did not find any way to combine that with the
  self-dealing gap to reach a worse outcome than the self-dealing gap
  itself already represents.
- **A single self-invoice is VAT-neutral on its own, not a direct refund
  exploit.** Both the `OUTPUT_VAT`/`CREDIT` and `INPUT_VAT`/`DEBIT` ledger
  entries a self-invoice generates land in the identical period for the
  identical taxpayer -- confirmed by reading the ledger-entry construction
  in `InvoiceService::submit()`'s transaction block. This pass did not find
  a way to split those two entries into different periods or different
  taxpayers from a single `submit()` call, which would be the more directly
  dangerous variant of this bug.
- **The idempotency and duplicate-detection paths are unaffected by this
  fix.** The new check runs before the duplicate-source-document and
  invoice-number-collision checks, so it fails fast and cleanly rather than
  interacting with either of those existing guards.

## 3. What this pass did not cover

- **Correction lineage (credit/debit notes) against a self-invoice.**
  Whether a `CREDIT_NOTE`/`DEBIT_NOTE` referencing an original self-invoice
  (had one existed pre-fix) could have split ledger entries across periods
  was not investigated, since the root self-invoice is now rejected at
  creation and cannot exist in the first place.
- **Multi-invoice or circular self-dealing chains** (taxpayer A invoices
  shell taxpayer B which invoices back to A, both controlled by the same
  beneficial owner) are a materially different, KYC/beneficial-ownership
  class of fraud that this platform has no data model for detecting and
  which was out of scope for a code-level red-team pass.
- **The risk-scoring engine's broader coverage** (`InvoiceCalculator::score()`)
  was read and understood but not otherwise audited or extended in this
  pass beyond confirming its blind spot for this specific finding --
  whether its value/category thresholds are otherwise well-calibrated is a
  separate question this pass does not answer.
- **The remaining phases** of the user's original 10-phase brief (High
  Interaction Stress, Concurrent User Simulation, Authentication & Session
  Robustness beyond RT-007, Performance Under Heavy Use, Resilience to
  User Errors beyond RT-017's data-entry-error angle, UX Failure Discovery)
  remain untouched.

## 4. Summary

| ID | Title | Severity |
|---|---|---|
| RT-018 | Self-dealing invoices (supplier == customer) fully undetected | High — **FIXED** |
| — | Tenant-scope enforcement, ledger period/taxpayer symmetry, idempotency interaction | Checked, already correct |

**Overall assessment:** one genuine, live-reproduced fraud-resistance gap
found and fixed -- a registered taxpayer could certify a government-backed
invoice to themselves with zero detection by either the explicit business
rules or the risk-scoring engine. The fix closes the gap at its source
(rejecting the certification outright, before any state is written) rather
than merely flagging it for manual review after the fact.
