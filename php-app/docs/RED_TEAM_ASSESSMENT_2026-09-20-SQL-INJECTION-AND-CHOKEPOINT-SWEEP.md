# VAT-MSA Red Team Assessment — SQL Injection & Tenant-Isolation Chokepoint Sweep

**Date:** 2026-09-20
**Scope:** A third deep security sweep the same day, at the user's request,
deliberately using different angles from the two already run (the
correction-race TOCTOU pattern, `docs/RED_TEAM_ASSESSMENT_2026-09-20-
CORRECTION-RACE.md` and `...-OLDER-MODULE-SWEEP.md`, both already merged).
This pass targeted: (1) raw SQL injection risk across every `selectRaw`/
`whereRaw`/`orderByRaw`/`DB::raw`/`DB::statement` call in the app, and (2)
the small number of shared chokepoints (`OrganisationResolver::resolve()`,
`TenantScope::isNational()`, `Permissions::NATIONAL_SCOPE_ROLES`) that
almost every controller and service depends on for tenant isolation --
a single flaw in any of these would affect the whole app at once, so they
warrant periodic direct re-reading even without a specific trigger.
**Method:** A `grep` sweep of every raw-SQL call site for variable
interpolation inside the SQL string itself (as opposed to a bound
parameter or a static literal), each candidate read in full; direct
reading of the tenant-isolation chokepoints; spot-checked every action in
this session's newest controllers for a missing `$this->authorize()`
call; checked purchase-order amount validation for negative-value
rejection as a sampled instance of the codebase's general validation
discipline.
**Environment:** This session's own sandboxed dev stack. No code changes
resulted from this pass -- see below.
**Tester:** Claude (Anthropic), acting as the migration engineer, at the
user's explicit request ("do another deep security sweep").

---

## Result: no finding

Every raw-SQL call site in the app (`app/**/*.php`, excluding tests) uses
a static string literal -- aggregate expressions (`SUM`, `COUNT`, `CASE
WHEN`), atomic increment/decrement expressions (`version + 1`,
`GREATEST(0, reserved_value - 1)`), or a fixed `ORDER BY` `CASE`
expression -- never a value interpolated from user input, a request
parameter, or even another variable derived from one. The one call that
passes a *variable* directly as the raw SQL argument
(`RiskService::list()`'s `$builder->orderByRaw($severityOrder)`) assigns
that variable from a hardcoded string literal two lines above it, not
from any input. No SQL injection vector found.

`OrganisationResolver::resolve()` -- the chokepoint nearly every
controller in the app calls to scope a request to "the acting user's own
organisation" -- correctly refuses a taxpayer-scoped actor's request for
any organisation other than their own (`AuthorizationException` if
`$requestedOrganisationId !== $organisation->id`), and only ever expands
scope for an actor already confirmed national via `TenantScope::
isNational()`. That check itself requires *both* `taxpayer_id === null`
*and* role membership in `Permissions::NATIONAL_SCOPE_ROLES` --
redundant by design, so a role misclassification alone couldn't grant
national scope to an account that also carries a `taxpayer_id`.
`NATIONAL_SCOPE_ROLES`'s own membership list contains only genuinely
national/technical roles (`NAMRA_*`, `INTERNAL_AUDITOR`,
`SECURITY_ANALYST`, `SUPER_ADMIN`, `INFRASTRUCTURE_ADMIN`) -- no
taxpayer-facing role present. No gap found in the shared tenant-isolation
chokepoint.

Every public action in this session's newest controllers
(`PurchaseOrderViewController`, `ProjectManagementViewController`,
`InvoiceCorrectionViewController`, `CashFlowViewController`,
`BudgetsViewController`, `QuotationViewController`) calls
`$this->authorize('permission', ...)` before doing any real work --
confirmed both by an automated sweep and by direct reading of the
purchase-order controller in full. No missing-authorization gap found.

`BusinessValidator::purchaseOrder()`'s amount fields
(`net_cents`/`tax_cents`/`total_cents`) go through `integerField()` with
its default `$min = 0`, correctly rejecting a negative submission with a
clean `INTEGER_INVALID` message rather than silently accepting it --
sampled as a representative instance of this codebase's validation
discipline (already the subject of a dedicated 2026-09-14 pass, RT-017,
across the rest of the app).

## Why report a pass with nothing fixed

Consistent with this codebase's own established practice (e.g. the
2026-09-14 Authorization & Role Isolation phase's own "no confirmed
finding -- a genuine positive result, not a skipped check"): a security
sweep that finds nothing is still worth recording, both so the ground
already covered is visible to whoever reads this file next, and so "we
checked and it's fine" is distinguishable from "we never checked." No
code changed as a result of this pass; no PR opened.
