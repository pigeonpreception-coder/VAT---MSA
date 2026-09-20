# VAT-MSA Red Team Assessment — Credit Note Cumulative-Cap Race

**Date:** 2026-09-20
**Scope:** A focused follow-up sweep on the modules built this session that
had no dedicated adversarial pass yet -- principally the New Credit Note /
New Debit Note flow (`App\Http\Controllers\Invoice\
InvoiceCorrectionViewController`), the newest money-moving code in the app,
and the Project Management status transitions (`ProjectService::activate()`/
`complete()`). Not a re-run of the user's original 10-phase brief (already
substantially complete per `docs/LAUNCH_READINESS_BACKLOG.md` item #10) --
a targeted check of what that brief's own passes couldn't have covered yet,
since this code didn't exist when they ran.
**Method:** Direct code reading of the full write path (View controller
through `InvoiceService::submit()`), the same live-reproduction discipline
as every prior red-team pass in this series: a `DB::listen()`-based
same-connection race simulation (this codebase's established technique,
first used in RT-020/Concurrent User Simulation) proving the defect and the
fix, plus manual tracing of the transaction/lock boundary.
**Environment:** This session's own sandboxed dev stack, real MySQL. Full
suite: 790 tests before this pass, 791 after.
**Tester:** Claude (Anthropic), acting as the migration engineer, at the
user's explicit request ("do a deeper security sweep").

---

## 1. Confirmed finding

### Credit note cumulative-credit-cap check ran before the transaction opened, with no lock — a genuine TOCTOU race, not backstopped by any UNIQUE constraint

> **Status: FIXED (2026-09-20).** `InvoiceService::resolveOriginalInvoice()`
> enforced the "a credit note (or a sequence of them) cannot cumulatively
> exceed the original invoice's own value/VAT" cap with a plain read —
> `SUM()` over every prior `ACTIVE` credit note against the same original,
> compared against the original's own totals — called from `submit()`
> *before* `DB::transaction()` even opens. Two concurrent credit-note
> submissions against the same original invoice, each individually within
> the cap, could both perform this read before either had committed its
> own new correction row, both pass, and together create a combined credit
> exceeding the original invoice's certified value and VAT — inflating the
> customer's apparent input-VAT position or reducing the supplier's output
> VAT beyond what the original, government-certified invoice ever
> supported. Unlike the idempotency-key race and the invoice-number race
> `submit()` already guards a few lines below (both backstopped by a real
> `UNIQUE` constraint, recovered from in the method's own
> `catch (QueryException $e)` block), there is no constraint that can catch
> an aggregate `SUM()` exceeding a cap — nothing would have caught this.
>
> Fixed by moving the check into `submit()`'s own `DB::transaction()`, as a
> new `InvoiceService::enforceCumulativeCreditCap()`, under
> `Invoice::whereKey($originalInvoice->id)->lockForUpdate()`. A concurrent
> submission against the same original now blocks on that lock until the
> first transaction commits or rolls back; a locking read in InnoDB always
> sees the latest committed data regardless of this transaction's own
> snapshot, so the second submission's re-check is guaranteed to see the
> first's newly committed credit note. This is a different shape of race
> than the nine this codebase's existing guarded-UPDATE/affected-row-count
> convention (RT-020) already covers — that pattern fits a single row's own
> state transition; this one is a cap over the *sum* of several other rows,
> which needs a lock, not a conditional `WHERE`.

| | |
|---|---|
| **Severity** | **High** — a real, VAT-revenue-relevant financial control (the amount a credit note can ever legitimately reduce output VAT by) that a race could silently defeat, on the newest write path in the app |
| **User role** | Any `TAXPAYER_OWNER`/similar role holding `invoices:submit` on their own taxpayer — no privilege escalation, no cross-tenant access; the race is purely temporal, against the actor's own original invoice |
| **Feature** | New Credit Note (`/new-registration/credit-note`) and any other caller of `POST /api/v1/invoices` with `document_type=CREDIT_NOTE` (the JSON API path existed before this session and was equally exposed; the Blade UI built this session is simply the first caller most users will actually reach) |

**Reproduction steps (pre-fix, live-verified against real MySQL with a
`DB::listen()` same-connection race simulation — this codebase's own
established technique for these races, since a literal second connection
cannot see another connection's still-open `RefreshDatabase` test
transaction):**

1. Certify a `TAX_INVOICE` for NAD 1,150.00 (NAD 1,000.00 net + NAD 150.00
   VAT).
2. Submit a `CREDIT_NOTE` for NAD 690.00 against it. Hook `DB::listen()` on
   the query the fix's own lock issues (`... for update`) — on the pre-fix
   code path, no such query exists at all, so the hook never fires and the
   request simply succeeds, exactly as it would for a second, genuinely
   concurrent request racing the first.
3. On the fixed code path, the hook fires and inserts a second, fully-
   formed `ACTIVE` credit note for NAD 690.00 against the same original —
   modelling the concurrent request that, pre-fix, would have committed
   between this request's own stale read and its own write.
4. **Pre-fix**: the stale, pre-transaction read (computed before the
   concurrent credit note existed) sees `prior = 0`, the new request's own
   NAD 690.00 passes the cap check against a NAD 1,150.00 original, and
   the request would succeed — for a combined NAD 1,380.00 credited
   against a NAD 1,150.00 invoice, 20% over the original's own value.
   **Post-fix**: the lock-then-reread inside the transaction sees the
   concurrent NAD 690.00 credit note, correctly computes the combined
   NAD 1,380.00, and rejects with `409 Conflict` before either row is
   committed.

**Fix verification**: `tests/Feature/Invoice/InvoiceLifecycleTest.php`'s
`test_a_credit_note_that_races_a_concurrent_credit_note_does_not_jointly_exceed_the_original_invoice_value`
is a permanent regression test using this exact reproduction. Confirmed to
fail against the pre-fix code (`git stash` the fix, rerun: fails on
`assertTrue($raced, ...)` because the lock query — and so the hook trigger
— doesn't exist pre-fix) and pass against the fix. Full suite: 791 tests,
0 regressions.

## 2. Areas checked with no finding

- **`ProjectService::activate()`/`complete()`** (this session's own new
  status transitions): both already use the established guarded-UPDATE +
  affected-row-count pattern (`Project::where('id', $id)->where('status',
  'PLANNED')->update(...)`, checking `$updated === 0`) inside their own
  `DB::transaction()` — the correct pattern for a single row's own state
  transition, already proven race-safe by RT-020. No gap found.
- **New Debit Note** (`InvoiceCorrectionViewController::storeDebitNote()`):
  a debit note is a genuine new charge with no cap to race against (unlike
  a credit note, it isn't bounded by the original invoice's own value) —
  the same TOCTOU shape doesn't apply.
- **Per-submission credit-quantity validation**
  (`InvoiceCorrectionViewController::storeCreditNote()`'s own
  `$quantityMicros > $originalQuantityMicros` check): this is a UI
  usability safeguard against a single submission over-crediting one
  line's own quantity, not a security boundary — the class's own doc
  comment already says the dollar/VAT cap "is not a second source of
  truth for the cap itself." It does **not** track cumulative quantity
  credited across *multiple* credit notes on the same original line
  (`invoice_lines` carries no link back to which original line a
  correction line corresponds to, for any document type, matching the
  original TypeScript source's own aggregate-only cap design — not a
  migration-introduced gap). A supplier could still, across several
  credit notes, over-credit one specific line's quantity while staying
  within the aggregate dollar/VAT cap now correctly enforced above.
  **Not fixed here**: closing this precisely would need a schema change
  (an `original_line_id` linkage on `invoice_lines`, for every document
  type, not just corrections) disproportionate to a red-team follow-up:
  documented as a known, inherited limitation of the underlying cap
  design, worth a dedicated proposal if NamRA's own line-item audit
  requirements ever need it.
