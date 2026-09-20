# VAT-MSA Red Team Assessment — New-Module TOCTOU Races (Credit Notes, Purchase Orders)

**Date:** 2026-09-20
**Scope:** A focused follow-up sweep on the modules built this session that
had no dedicated adversarial pass yet -- principally the New Credit Note /
New Debit Note flow (`App\Http\Controllers\Invoice\
InvoiceCorrectionViewController`), Purchase Orders
(`App\Services\Business\PurchaseOrderService`), and the Project Management
status transitions (`ProjectService::activate()`/`complete()`). Not a
re-run of the user's original 10-phase brief (already substantially
complete per `docs/LAUNCH_READINESS_BACKLOG.md` item #10) -- a targeted
check of what that brief's own passes couldn't have covered yet, since
this code didn't exist when they ran.
**Method:** Direct code reading of every write path in each module, plus a
systematic `grep` sweep of every `App\Services\*` for the same aggregate-
read-before-cap-check shape once the first finding below made the pattern
concrete. Live-reproduction discipline matching every prior red-team pass
in this series: a `DB::listen()`-based same-connection race simulation
(this codebase's established technique, first used in RT-020/Concurrent
User Simulation) proving each defect and its fix, plus manual tracing of
each transaction/lock boundary.
**Environment:** This session's own sandboxed dev stack, real MySQL. Full
suite: 790 tests before this pass, 792 after (both fixes).
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

## 2. Confirmed finding

### Purchase order conversion was missing the affected-row check every sibling transition has, and could create an orphaned duplicate Expense under a race

> **Status: FIXED (2026-09-20).**
> `PurchaseOrderService::convertToExpense()` is the ISSUED→CONVERTED
> transition, and the only one of six transitions in the file
> (create/submit/approve/reject/issue/cancel are the other five) that
> creates a real side-effect row of its own — a new `Expense`, via
> `ExpenseService::create()` — before writing the purchase order's own new
> status. Unlike its five siblings, which all capture the guarded update's
> affected-row count and throw `RepositoryConflictException` when it's
> `0`, this one called `PurchaseOrder::where(...)->where('status',
> 'ISSUED')->update([...])` and ignored the result entirely. Two
> concurrent conversion attempts on the same `ISSUED` order (different
> idempotency keys — two browser tabs, not a same-key double-click replay,
> which `CommandLedger::prior()` already dedupes) could both pass the
> pre-check, both call `ExpenseService::create()` and each get back a
> real, distinct `Expense` row, and the loser's unguarded update would
> silently affect zero rows while the method carried on as if it had
> succeeded — recording a `CommandLedger`/outbox/audit trail for a
> conversion that never actually took effect on the purchase order, and
> leaving a second, real, fully valid Expense with no purchase-order
> reference at all: an orphaned duplicate financial record, invisible to
> anyone looking at the purchase order itself (its own
> `converted_expense_id` only ever points at the winner's expense).
>
> Fixed by wrapping the lock, the expense creation, and the now-guarded
> update in one transaction:
> `PurchaseOrder::whereKey($id)->lockForUpdate()` is acquired *before*
> `ExpenseService::create()` is ever called, so a concurrent attempt on
> the same order blocks on the lock until the winner's transaction
> commits, then re-reads a status that is no longer `ISSUED` and throws
> before creating any Expense at all — closing the orphaned-duplicate
> case entirely, not just converting it into an honest error while the
> side effect still happens. This also makes expense creation and
> purchase-order linkage one atomic unit; the method's old doc comment
> described a deliberate two-phase "expense commits, then linkage
> completes on retry" crash-recovery design, which this fix supersedes
> with something simpler and strictly safer (a crash now leaves nothing
> committed at all, and the same idempotency key retries clean from
> `CommandLedger::prior()`), not a regression of that intent.

| | |
|---|---|
| **Severity** | **Medium** — a real duplicate financial record (a genuine `Expense`, real money, real VAT-relevant total) could be created invisibly, though it requires a genuine two-tab/two-idempotency-key race rather than an ordinary double-click, and doesn't itself grant unauthorized access |
| **User role** | Any role holding `accounting:post` on their own organisation — no privilege escalation, no cross-tenant access |
| **Feature** | Purchase Orders → Convert to Expense (`/accounting/purchase-orders/{id}/conversion`), the newest domain model in the app (built 2026-09-19) |

**Reproduction steps (pre-fix, live-verified with the same `DB::listen()`
same-connection race-simulation technique as finding #1 above):**

1. Create a purchase order and drive it through DRAFT→SUBMITTED→APPROVED→
   ISSUED via the real Blade routes.
2. POST a conversion request. Hook `DB::listen()` on the query the fix's
   own lock issues (`... for update`) — on the pre-fix code path, no such
   query exists, so the hook never fires and the request simply succeeds,
   exactly as an unguarded concurrent request would.
3. On the fixed code path, the hook fires and updates the purchase order
   directly to `CONVERTED` with a fake `converted_expense_id` — modelling
   a concurrent conversion that, pre-fix, would have fully committed
   (including its own real Expense) between this request's own stale
   pre-check and its own write.
4. **Pre-fix**: the unguarded update silently affects zero rows (the
   order is already `CONVERTED`), but the method has no way to notice —
   it already called `ExpenseService::create()` moments earlier and
   created a real, now-orphaned Expense, then reports success. **Post-fix**:
   the lock blocks behind the simulated winner, re-reads `status !=
   'ISSUED'`, and throws *before* `ExpenseService::create()` is ever
   called — no Expense, orphaned or otherwise, is created at all.

**Fix verification**: `tests/Feature/Business/PurchaseOrderViewTest.php`'s
`test_converting_a_purchase_order_that_races_a_concurrent_conversion_does_not_create_an_orphaned_duplicate_expense`
is a permanent regression test using this exact reproduction. Confirmed to
fail against the pre-fix code (same `git stash`-and-rerun check as finding
#1) and pass against the fix. Full suite: 792 tests, 0 regressions.

## 3. Areas checked with no finding

- **Systematic sweep for the same shape elsewhere**: `grep`ed every
  `App\Services\*` for `SUM(`/`->sum(`/`selectRaw.*SUM` used ahead of a
  write. The only other candidate,
  `RefundService::transition()`'s own pre-transaction read of a
  taxpayer's total `PENDING` `TaxObligation` debt (used to compute a
  refund claim's own `offset_amount_cents`/`net_payable_cents`), does
  **not** have the same defect: that read is never written back to
  `TaxObligation` by this or any code path reachable from refund approval
  (`ObligationService::markSatisfied()` is the only place a
  `TaxObligation` status ever changes, and it is a fully independent,
  separately-invoked action) — two concurrent refund approvals for the
  same taxpayer could each display an offset computed against the same
  debt snapshot, but neither actually consumes or double-spends that
  debt, so there is no real financial-correctness violation to fix, only
  a cosmetic staleness in what each claim's own `offset_amount_cents`
  displays at the moment of approval. Every other `SUM()`/aggregate use
  found is read-only reporting (dashboards, ledger statements, export
  snapshots) with no write gated on it at all.
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
