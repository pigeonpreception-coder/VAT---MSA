# VAT-MSA Red Team Assessment — Older-Module Sweep for the Correction-Race Pattern

**Date:** 2026-09-20
**Scope:** User-requested follow-up ("another deep security sweep on the
older modules") to the same day's sweep of this session's own newest code
(`docs/RED_TEAM_ASSESSMENT_2026-09-20-CORRECTION-RACE.md`), which found and
fixed two TOCTOU races of a specific shape: a real side-effecting write
(certifying an invoice, creating an expense) running unconditionally after
a guarded status-transition update with no affected-row check. This pass
systematically searched the rest of the codebase -- including modules
built well before this session, some dating to the original migration
phases -- for the same shape, then triaged and fixed every genuine
instance found.
**Method:** A `grep`/`awk` sweep of every `App\Services\*` file for a
`DB::transaction()` closure containing a status-guarded `->update(...)`
call with no `$updated = ...` capture, cross-checked by direct reading of
each candidate's surrounding code (most were false positives -- either
already guarded a few lines later, or converging/benign rather than
creating a second resource). Each genuine finding reproduced live with the
same `DB::listen()` same-connection race-simulation technique as the
earlier pass, confirmed to fail against the pre-fix code and pass against
the fix.
**Environment:** This session's own sandboxed dev stack, real MySQL. Full
suite: 792 tests before this pass (post-merge of the newest-module sweep's
own PR), 795 after.
**Tester:** Claude (Anthropic), acting as the migration engineer, at the
user's explicit request.

---

## 1. Confirmed finding

### Three more unguarded "create a real resource, then flip a status with no affected-row check" races, in the VAT return approval pipeline, quotation-to-invoice conversion, and document supersession

> **Status: FIXED (2026-09-20).** All three follow the exact shape found
> and fixed earlier the same day in `PurchaseOrderService::
> convertToExpense()`: a real side-effecting row is created (or an
> external write is made) unconditionally, immediately after a status
> update that has no affected-row check -- so a concurrent request racing
> the same guard can each create their own real resource, with the
> loser's left orphaned while the request still reports success.

**1a. `VatLifecycleService::requestReturnApproval()`** -- the single most
critical write path in the app, the entry point to the VAT return approval
pipeline. `VatReturnVersion::where(...)->where('status','DRAFT')->update(...)`
had no affected-row check, and `ApprovalTask::create()` (a real,
CRITICAL-risk-tier governance object) ran unconditionally right after.
Two concurrent requests on the same DRAFT return version could each
create their own approval task -- breaking the workflow's own invariant
of at most one live PENDING task per return.

**1b. `VatLifecycleService::decideApproval()`** -- found while fixing 1a,
same file. None of its four status updates (the `ApprovalTask` itself,
`VatReturnVersion`, `VatPeriod`, `VatAdjustment`) had an affected-row
check. A concurrent decision on the same task could silently no-op while
the request still recorded a `CommandLedger`/outbox/audit trail claiming
its own decision took effect, and the `VatPeriod` lock/unlock ran
unconditionally regardless of whether the version transition above it
actually happened.

**1c. `QuotationService::convertToInvoice()`** -- same shape as
`PurchaseOrderService::convertToExpense()`, but worse: the unconditional
side effect here is a real, government-certified `TAX_INVOICE` (via
`InvoiceService::submit()`), the core fiscal document of the whole
system, not a supplier expense. Two concurrent conversions of the same
ACCEPTED quotation could each certify their own invoice, with the
loser's left orphaned.

**1d. `DocumentService::supersede()`** -- the new replacement
`DocumentMetadata` row (a real, QUARANTINED, pending-scan document) was
created unconditionally, and the original's SUPERSEDED update had no
affected-row check -- silently breaking this class's own doc comment's
claim that "a given document can only ever be superseded once."

| | |
|---|---|
| **Severity** | **High** for 1a/1b (the VAT return approval workflow itself) and 1c (a duplicate certified tax invoice); **Medium** for 1d (a duplicate document record, not directly financial, but a chain-of-custody integrity break) |
| **User role** | 1a/1b: `TAXPAYER_OWNER` (request) / any role with approval authority (decide) on their own taxpayer. 1c: `TAXPAYER_OWNER`/similar with `quotations:manage` on their own organisation. 1d: any role with `documents:upload` on their own organisation. No privilege escalation or cross-tenant access in any of the four -- every race is purely temporal, against the actor's own resource |
| **Feature** | VAT return approval requests/decisions (`/api/v1/vat-returns/{id}/approval-requests`, `/api/v1/approval-tasks/{id}/decision`); Quotation-to-invoice conversion (`/api/v1/quotations/{id}/convert`); Document supersession (`/api/v1/documents/{id}/supersession`) |

**Fix**: each guarded update now captures its affected-row count and
throws `RepositoryConflictException` when it's `0`, before any further
write -- the same established pattern this codebase's RT-020 pass already
applied to nine other transitions, extended here to four more sites plus
one full restructuring (1c, which also needed the certification call
moved inside the guarded transaction, not just a check added after it,
since the side effect itself had to stop happening for a losing request,
not merely get flagged after the fact). None needed `lockForUpdate()`
(unlike the earlier pass's credit-note fix): InnoDB's own row-level
locking on the `UPDATE` statement itself is what makes a plain guarded
update safe against a genuinely concurrent second `UPDATE` on the same
row -- the same reasoning behind every pre-existing RT-020 fix. The
`lockForUpdate()` technique was needed for the credit-note fix
specifically because that check was a `SUM()` aggregate `SELECT`, not a
single row's own `UPDATE`.

**Fix verification**: three permanent regression tests, each confirmed to
fail against the pre-fix code and pass against the fix (`git stash` the
fix, rerun, confirm failure; restore, rerun, confirm pass):
- `tests/Feature/VatLifecycle/VatReturnLifecycleTest.php::test_requesting_approval_that_races_a_concurrent_request_does_not_create_a_duplicate_approval_task`
- `tests/Feature/Business/BusinessPartyAndQuotationTest.php::test_a_quotation_that_races_a_concurrent_conversion_does_not_create_an_orphaned_duplicate_invoice`
- `tests/Feature/Document/DocumentTest.php::test_superseding_a_document_that_races_a_concurrent_supersession_does_not_create_a_second_orphaned_replacement`

(1b has no dedicated race test of its own -- its four checks are
defense-in-depth, closing the same shape at each of `decideApproval()`'s
own writes rather than reproducing a distinct exploitable race; 1a's own
test already exercises the code path immediately upstream of it.)

Full suite: 795 tests, 0 regressions.

## 2. Documented, not fixed: lower-severity instances of the same missing-check pattern

The same sweep found several more sites with the identical
"status-guarded `update()`, no affected-row check" shape, in
`BusinessPartyService` (update/deactivate), `QuotationService`
(reject/edit/send/accept/expire), `CommunicationService` (close thread),
and `NotificationService` (cancel/mark read). Read individually and
triaged against the same standard as the four findings above: none of
them creates a second real resource or a second side-effecting external
write the way 1a-1d do. Under a race, the worst outcome converges to the
same end state regardless of ordering (e.g. a party ends up `INACTIVE`
either way; a quotation ends up `REJECTED` either way), with the only
real effect being a second, technically-misleading
`CommandLedger`/outbox/`AuditService::append()` entry recorded for a
write that didn't actually apply -- an audit-trail-precision nit, not a
data-integrity or financial-correctness gap. Not fixed in this pass to
keep it proportionate to what was actually found broken; worth a
dedicated, lower-priority follow-up (the same guarded-update pattern
RT-020 already established, just applied more completely) if audit-trail
precision under concurrent access becomes a stated requirement.

## 3. Areas checked with no finding

- **`AuditCaseService`'s own evidence-supersession check** (a `grep` hit
  that looked identical to 1a-1d at first glance): already correctly
  guarded with an affected-row check, per a prior red-team punch-list item
  (its own doc comment names it: "Red-team punch list #8"). Confirmed on
  reading the surrounding code, not fixed again.
- **`PosService::checkout()`**: creates a real invoice via
  `InvoiceService::submit()`, superficially similar to the pattern above,
  but has no prior "source row" being transitioned at all -- it's a
  direct one-shot checkout from a cart, not a conversion of an existing
  guarded resource, and its invoice number is randomly generated (no
  realistic collision to race). Matches the original source's own
  deliberate partial-failure design (invoice certifies first, stock
  movements are independently idempotent and never rolled back). No gap
  found.
