# VAT-MSA Red Team Assessment — Resilience to User Errors (Phase 8)

**Date:** 2026-09-14
**Scope:** Phase 8 of the user's original 10-phase brief. Rather than
malformed *input* (already covered by Phase 5/RT-017), this pass hunts for
"a normal user does something out of order" failure modes: missing
date-range checks, invalid/unbounded field values, unhandled exceptions on
plausible user IDs, and -- the confirmed finding below -- status
transitions that don't survive two different action buttons being clicked
from the same stale page.
**Method:** A broad, read-only recon sweep (delegated to an Explore
subagent given the codebase's size) across every `App\Domain\**Validator.php`
file and every service managing a status/state machine, followed by
independent verification of every candidate by reading the actual code,
then live-verified regression tests using real MySQL through the full
HTTP/middleware/controller/service stack.
**Environment:** This session's own sandboxed dev stack. Full suite: 629
tests before this pass, 636 after.
**Tester:** Claude (Anthropic), acting as the migration engineer, at the
user's explicit request.

---

## 1. Confirmed finding

### RT-020 — Seven status-transition services could silently corrupt state (one double-count a VAT reversal) under a double-click race

> **Status: FIXED (2026-09-14).** Seven services managing a status/state
> machine (`FixedAssetService`, `LogisticsService`, `AuditCaseService`,
> `RefundService` (two methods), `RiskService` (three call sites),
> `AuthorityGovernanceService`, and `InvoiceService::cancel()`) all read a
> resource's current status once, validated the requested action against
> that in-memory value, then wrote the new status with a plain
> `Model::where('id', $id)->update(...)` -- never re-checking that the row
> was still in the status it was read as. This is the exact same class of
> bug this session's own `PlatformChangeService::decideChange()` (and
> siblings in `QuotationService`/`VatLifecycleService`/`WorkflowService`)
> already guard against correctly, just not applied consistently across
> the codebase. `InvoiceService::cancel()` is the most severe instance:
> its own pre-check ("already cancelled? short-circuit") only protects a
> *sequential* double-cancel, not a concurrent one -- two overlapping
> cancellations of the same invoice could each create their own reversing
> `VatTransaction`/`LedgerEntry` pair, double-counting the VAT reversal on
> the taxpayer's own ledger. Fixed by adding the identical
> `->where('status', ...)` guard plus an affected-row check to every one
> of the nine call sites, throwing `RepositoryConflictException` before
> any side effect (transition record, ledger, outbox event, audit entry)
> is written.

| | |
|---|---|
| **Severity** | **High** for `InvoiceService::cancel()` (a real, double-countable financial-ledger effect); **Medium** for the other eight sites (status/audit-trail corruption, not a privilege escalation -- every path is still correctly permission- and tenant-scope-gated) |
| **User role** | Any role holding the relevant `*:manage`/`invoices:cancel` permission for the affected resource -- fixed-asset custodians, logistics coordinators, NamRA audit officers, refund officers, risk analysts, authority-governance reviewers, invoice-cancelling pilot admins |
| **Feature** | Fixed-asset maintenance/restore/dispose, logistics dispatch/deliver/cancel, audit-case transitions, refund-claim transitions and disputes, risk-indicator review assignment and decisions, tax-authority onboarding-case decisions, invoice cancellation |

**Reproduction steps (pre-fix, live-verified with real MySQL through the
full HTTP/middleware/controller/service stack):**

A genuinely concurrent double-click (two browser tabs, or one user
clicking two different action buttons in quick succession from the same
stale page) cannot be produced by a single synchronous PHPUnit process --
by the time a second sequential call reads the row, it always sees the
first call's already-committed write, which the *pre-existing* validation
correctly rejects. That would only prove the trivial case already worked,
not the actual race window this finding is about. Instead, each
regression test uses a real Laravel `DB::listen()` hook to fire a genuine,
separate `UPDATE` against the exact same row **at the precise instant**
between the service's own read query and its own write query --
reproducing exactly what a second, faster concurrent request would have
done, with no mocking of any business logic:

1. Create a resource at its initial status (e.g. a fixed asset at
   `ACTIVE`, a refund claim at `RECEIVED`, an onboarding case at
   `SUBMITTED`).
2. Arm a `DB::listen()` hook that, the moment it sees the service's own
   `SELECT ... WHERE id = ?` for that resource, immediately fires a real,
   separate `UPDATE` on the same row -- simulating a second action
   (dispose, cancel, reject) that a concurrent request completed first.
3. Call the original action (flag for maintenance, dispatch, authorize,
   approve, assign, approve-local-staging) against the now-stale
   in-flight request.

**Actual behavior (pre-fix), confirmed for `FixedAssetService::
transition()` by temporarily reverting the fix and re-running its own
regression test:** the guarded read's in-memory `$fromStatus` was
`ACTIVE`, correctly validated as an allowed source for `FLAG_MAINTENANCE`
-- but the plain `update()` with no `WHERE status = ...` clause
**succeeded anyway**, overwriting the concurrent winner's `DISPOSED`
status back to `UNDER_MAINTENANCE`, and wrote a transition record and an
`AUDIT_CASE_FLAG_MAINTENANCED`-shaped audit event claiming a maintenance
flag happened on an asset that had, in the same instant, actually been
disposed. The same structural gap was independently confirmed by reading
the other five services' code (all share the identical
read-validate-then-unconditionally-write shape).

**Business impact:** For a tax platform whose audit trail (`audit_events`,
transition tables, outbox events) is the record regulators and internal
compliance rely on, a lost-update race doesn't just risk a wrong status --
it produces an **audit trail that asserts something happened that the
database doesn't actually reflect**. Concretely: a disposed asset silently
reverting to "under maintenance," a delivered shipment silently reverting
to "dispatched," a rejected refund claim silently getting approved (and
its `RefundClaimTransition` row recording an "approve" that the final
status contradicts), or an audit case decision racing another reviewer's
decision on the same case with no conflict surfaced to either party. None
of this requires malice -- an impatient user double-clicking two different
buttons on a slow connection, or two legitimate officers acting on the
same queue item moments apart, is enough.

`InvoiceService::cancel()` is the one instance with a direct financial
consequence rather than only a status/audit-trail inconsistency: its own
"already cancelled" pre-check reads the invoice once, before its
transaction, so two overlapping cancel requests on the same still-active
invoice could both pass it and both reach the guarded write -- each
creating a full reversing `VatTransaction`/`LedgerEntry` pair. That
doubles the VAT reversal actually posted to the taxpayer's ledger for a
single cancelled invoice, silently understating their output VAT (or
overstating a customer's input VAT credit) by the invoice's tax amount a
second time over.

**Root-cause hypothesis:** This codebase has an established, correct
pattern for exactly this problem (`QuotationService`, `VatLifecycleService`,
`WorkflowService`, `PlatformChangeService`, `DocumentService`,
`NotificationService`, `CommunicationService`, `BusinessPartyService` all
guard their status-changing `UPDATE` with a `WHERE status = <expected>`
clause) -- but it was never made a documented convention or a shared
helper, so seven newer/less-visited services independently reinvented the
"read, validate in PHP, then blind-write" shape instead, each missing the
one line that closes the race.

**Recommended solution (implemented):** The exact same guard the working
services already use, applied to all nine call sites:

```php
$updated = Model::where('id', $id)->where('status', $fromStatus)->update([...]);
if ($updated === 0) {
    throw new RepositoryConflictException("{$resource} {$id} was changed by another action; reload and try again.");
}
```

placed immediately before any transition-record/ledger/outbox/audit side
effect, all still inside the same `DB::transaction()` so a caught race
rolls back cleanly with nothing partially written.

**Regression risks:** None expected for legitimate traffic -- the
guard's expected-status value is always the exact status the service
itself just read and validated the action against, so a genuinely
sequential, non-racing request is completely unaffected (confirmed by the
full existing suite passing unchanged; the existing "cancelling an
already-cancelled invoice is a clean no-op" idempotency test also still
passes unchanged). Verified with 7 new permanent regression tests (one
per affected service; `RiskService`'s three call sites share one
representative test plus code-review parity for the other two, and
`RefundService`'s `dispute()` shares `transition()`'s identical structure
and is covered by parity rather than a second dedicated test, given time
budget) using the `DB::listen()` race-simulation technique described
above, plus one of the seven (`FixedAssetService`) independently
confirmed to fail without the fix by temporarily reverting it and
re-running.

**Validation checklist:**
- [x] `FixedAssetService::transition()` -- race test passes with the fix,
      confirmed to fail without it (temporarily reverted and re-run).
- [x] `LogisticsService::transition()` -- race test passes.
- [x] `AuditCaseService::transition()` -- race test passes (via real HTTP,
      `/api/v1/audit-cases/{id}/transition`).
- [x] `RefundService::transition()` -- race test passes (via real HTTP,
      `/api/v1/refunds/{id}/transition`); `dispute()` shares the identical
      fix, verified by code review, not independently race-tested.
- [x] `RiskService::assignReview()` -- race test passes (via real HTTP,
      `/api/v1/risk-indicators/{id}/assignment`); `approveAction()`'s two
      branches (`DISMISS`/`ESCALATE_TO_CASE`) share the identical fix,
      verified by code review, not independently race-tested.
- [x] `AuthorityGovernanceService::decideOnboardingCase()` -- race test
      passes (via real HTTP, `/api/v1/tax-authority-onboarding-cases/{id}/decisions`).
- [x] `InvoiceService::cancel()` -- race test passes (via real HTTP,
      `/api/v1/invoices/{id}/cancellation`), confirming the ledger is
      reversed exactly once (`vat_transactions`/`ledger_entries` counts
      unchanged from the concurrent winner's own write).
- [x] Full suite (636 tests) passes with zero regressions.

## 2. Positive controls confirmed (not findings)

- **Every date-range field across all 17 `Domain/**Validator.php` files
  already has an explicit ordering check.** The Explore agent's sweep and
  my own follow-up reading found no missing "end must not be before
  start" check anywhere -- `issue_date`/`valid_until` (quotations),
  `issue_date`/`due_date`, `start_date`/`end_date`, `effective_from`/
  `effective_to` (`WorkflowValidator`), `sequence_from`/`sequence_to`, and
  others all correctly enforce ordering.
- **Numeric bounds are correctly enforced** for quantities, tax-rate
  basis points (0-10000), and every monetary field checked --
  no reachable unbounded-percentage or negative-quantity gap found.
- **Email/phone/format validation is applied consistently** across
  `BusinessValidator` and `OrganisationAdminValidator`.
- **`findOrFail`/`firstOrFail` on a plausible user-supplied ID does not
  produce a raw stack-trace page** -- Laravel's default `ModelNotFoundException`
  handling plus this app's own custom exception renderers (RT-001/RT-002)
  already convert every checked path to a clean, JSON- or view-appropriate
  404/403, not a debug page.

## 3. What this pass did not cover

- **`RefundService::dispute()`, `RiskService::approveAction()`'s two
  branches** received the identical mechanical fix and were verified by
  code review against the exact same pattern proven correct elsewhere in
  this pass, but were not each given an independent `DB::listen()`
  regression test, given time budget.
- **A genuinely concurrent (multi-process/multi-connection) reproduction**
  was not attempted -- this session's dev environment is a single-worker
  `php artisan serve` process, the same constraint repeatedly noted
  throughout this whole assessment for the concurrency-dependent phases.
  The `DB::listen()` technique used here is a deliberate, real-MySQL
  substitute that reproduces the same database-level race window without
  needing true OS concurrency, and was independently validated (fails
  without the fix, passes with it) rather than assumed correct.
- **Other status-changing services not flagged by the recon sweep** may
  share sibling patterns not yet found; this pass covered every
  `transition()`-shaped method the sweep surfaced plus one additional
  instance (`InvoiceService::cancel()`) found while writing up the
  findings, not an exhaustive audit of every `Model::update()` call in the
  codebase.
- **The remaining phases** of the user's original 10-phase brief (High
  Interaction Stress, Concurrent User Simulation, Performance Under Heavy
  Use, UX Failure Discovery) remain untouched, for the same single-worker
  constraint noted above.

## 4. Summary

| ID | Title | Severity |
|---|---|---|
| RT-020 | Seven services (nine call sites) vulnerable to a double-click/concurrent-action lost-update race, one with a direct double-reversal ledger effect | High — **FIXED** |
| — | Date-range ordering, numeric bounds, format validation, clean 404s on plausible IDs | Checked, already correct |

**Overall assessment:** one genuine, systemically-repeated pattern found
across seven services (nine call sites) -- a status-changing write that
never re-checked the status it was read as, unlike the ten-plus sibling
services elsewhere in this codebase that already get this right, one
instance of which (`InvoiceService::cancel()`) could double-count a real
VAT-ledger reversal. Fixed uniformly with the established, already-proven
pattern; independently verified with a real-MySQL race-simulation
technique for seven of the nine sites, including a positive control
(reverted-fix-fails) for one.
