# Consolidated open items from the 14 red-team reports (2026-09-15)

`docs/LAUNCH_READINESS_BACKLOG.md`'s "Recommended next step" section names
"the handful of red-team reports above that named an explicit,
not-yet-individually-verified follow-up in their own 'what this pass did
not cover' section" as the buildable-now backlog once #1/#3/#4/#6/#7/#8/#10
were closed or blocked. This document makes that concrete: every one of
the 14 dated `docs/RED_TEAM_ASSESSMENT_*.md` reports was read in full and
checked against the current codebase and `docs/MIGRATION_MATRIX.md` for
what it explicitly flagged as not covered, still open, or a follow-up not
yet done. Findings already fixed within their own report, and items later
closed by a subsequent report or session (the duplicate-submission-sweep
follow-ups, closed by RT-014/015/016 the same day; TOTP/`password.confirm`
step-up, fully cut over 2026-09-15; sidebar per-permission gating, reverted
2026-09-15 per explicit user request) are not carried forward here.

Not a new red-team pass -- no new reproduction was attempted beyond
confirming each item is still true in the code as it stands today. Treat
each line as a pointer back to its source report, not a restatement of it.

## Buildable now, small

1. **`WorkflowService::createDelegation()`/`revokeDelegation()` have no
   duplicate-name/pairing guard** (source: `RED_TEAM_ASSESSMENT_2026-09-13-
   DUPLICATE-SUBMISSION-SWEEP.md`). No `CommandLedger` call or uniqueness
   pre-check at `app/Services/Workflow/WorkflowService.php`
   (`createDelegation`/`revokeDelegation`). A double-submit creates two
   identical delegation rows -- a data-quality nuisance, not a security
   bypass.
2. **`WorkflowService::decideWorkflowTask()`'s audit-log write isn't
   guarded by the assignment update's affected-row count** (source: same
   report). The `workflow_approvals` insert runs before the guarded
   `workflow_assignments` UPDATE and never checks its affected-row count,
   so a genuine concurrent double-decide can still write two
   `workflow_approvals` rows even though only one assignment-status change
   wins. Final status stays correct; the approval audit trail doesn't.
   Same one-line affected-row-check pattern already used elsewhere in this
   codebase would close it.
3. **`RefundService::dispute()`/`RiskService::approveAction()` got the
   RT-020 stale-read fix by pattern parity, never their own regression
   test** (source: `RED_TEAM_ASSESSMENT_2026-09-14-RESILIENCE-TO-USER-
   ERRORS.md`). Low risk, but unverified independently -- add 2-3 tests.
4. **The other ~6 `*ViewController` classes were only grep-checked, not
   live-reproduced, for the RT-017 int-cast silent-coercion bug** (source:
   `RED_TEAM_ASSESSMENT_2026-09-14-INPUT-VALIDATION.md`). Believed
   complete but not proven by reproduction.
5. **"Remember me" cookie lifecycle was never independently audited**
   beyond confirming the RT-019 session-wipe fix also covers it (source:
   `RED_TEAM_ASSESSMENT_2026-09-14-AUTHENTICATION-SESSION-ROBUSTNESS.md`).
   Audit only, no fix known to be needed yet.

## Buildable now, medium

6. **No self-service "log out my other sessions"** (source: same
   authentication/session report). No active-sessions list or
   terminate-session action exists; a user who suspects a stale session
   elsewhere has no way to check or kill it short of a full password
   reset. Needs new UI plus a backend query against the `sessions` table
   -- the query pattern already exists from RT-019's own fix.
7. **Authorization-check TOCTOU races (a scope check racing the write it
   gates) were named as a gap but never actually probed under true
   concurrency** (source: `RED_TEAM_ASSESSMENT_2026-09-14-AUTHORIZATION-
   ISOLATION.md`). The later Concurrent-User-Simulation pass did unlock
   true concurrency in this environment but tested status-transition/
   idempotency races, not this specific pattern -- genuinely untested.
8. **No exhaustive sweep of every `Model::update()` call for the RT-020
   stale-read race pattern** (source: the same resilience report). RT-020
   covered every `transition()`-shaped method a recon sweep surfaced plus
   one found ad hoc; undiscovered sibling instances may exist.
9. **Malformed/oversized JSON API payloads were never fuzzed** against the
   `*Controller` JSON API siblings of the fixed Blade forms (source: the
   input-validation report). Lower suspicion (same validators), genuinely
   untested.
10. **`InvoiceCalculator::score()`'s risk-threshold calibration overall
    was never audited**, only its blind spot for self-dealing (source:
    `RED_TEAM_ASSESSMENT_2026-09-14-FRAUD-RESISTANCE.md`). Needs a
    calibration review, ideally against real transaction data.

## Blocked on external access (already tracked in the backlog)

11. **Production OPcache config was never independently verified**
    (source: `RED_TEAM_ASSESSMENT_2026-09-02.md`, RT-004; tracked as
    backlog item #6). If prod mirrors the dev config, every request
    re-compiles the full stack (~500ms-23s per request observed in dev).
    Small fix once host access exists; blocked until then.
12. **Real query/N+1 performance at production-representative data
    volumes was never tested** (source: `RED_TEAM_ASSESSMENT_2026-09-14-
    CONCURRENT-USER-SIMULATION.md`; tracked as backlog item #7). Dev seed
    data (0 invoices, 5 fixed assets, 12 users) is too small to be
    meaningful. Needs a large synthetic seed or a staging environment
    closer to production sizing.

## Out of scope for a code-only fix

- **Multi-invoice circular self-dealing between colluding taxpayers**
  (source: the fraud-resistance report). Two taxpayers under common
  control trading invoices back and forth to inflate turnover would not
  trigger the same-taxpayer check RT-018 added. Needs a
  beneficial-ownership data model this platform doesn't have -- a KYC
  capability, not a bug fix.
