# VAT-MSA Red Team Assessment — Authorization & Role Isolation (Phase 6)

**Date:** 2026-09-14
**Scope:** Phase 6 of the user's original 10-phase brief, picked as the
next self-directed deep-dive now that the duplicate-submission series
(covering Phase 1's "Business Abuse Testing" angle) is closed. This pass
hunts a different vulnerability class: horizontal privilege escalation /
IDOR (Insecure Direct Object Reference) -- whether a user scoped to one
taxpayer/organisation can read or act on **another** taxpayer's resources
by guessing or enumerating IDs, even while holding the correct
*permission* (e.g. `invoices:read`) but not the correct *scope*.
**Method:** Every controller method taking a route-bound resource ID was
traced to its backing service call, checking whether the lookup is scoped
to the actor's own `taxpayer_id`/`organisation_id` (a pre-scoped query,
e.g. `Invoice::where('supplier_taxpayer_id', $actor->taxpayer_id)`) or
followed by an explicit scope-check (`TenantScope::requireTaxpayer()`,
`OrganisationResolver::resolve()`, `EntitlementGate::assert()`) before any
data is returned or mutated. ~45 distinct read/action methods were
checked across Invoices, VAT Returns, Refund Claims, Audit Cases,
Disputes, Obligations, Communications, Notifications, Risk, Quotations,
Business Parties, Expenses, Fixed Assets, Logistics, Projects, Accounting,
Documents, Workflows, Identity/Organisations, Access Governance, Authority
Governance, Employees, Licensing, and the POS Invoice API.
**Environment:** Static read-through analysis of the real codebase at
this session's own working tree, followed by a live service-level
regression test against real MySQL for the one hardening applied.
**Tester:** Claude (Anthropic), acting as the migration engineer, at the
user's explicit request.

---

## Result: no exploitable finding

Unlike every prior pass in this series, this one did not turn up a
confirmed, live-reproducible vulnerability. Every route-bound-ID method
checked traced down to a service call that correctly scopes the lookup --
either as a pre-scoped query or via an explicit `TenantScope`/
`OrganisationResolver`/`EntitlementGate` check before any tenant-owned
data is read or written. This is a genuinely disciplined codebase on this
axis: nearly every service funnels its read/write paths through one
internal `loadForActor()`/`findOrFail($id, $organisationId)`-style helper
rather than leaving a second, careless code path for a future change to
bypass -- exactly the shape that IDOR bugs usually hide in, and it's
consistently absent here.

**Spot-checked and confirmed correctly scoped** (a representative sample,
not exhaustive -- see the full checklist retained in this session for the
complete ~45-method list):

| Resource | Method | Scoping mechanism |
|---|---|---|
| Invoice | `InvoiceController::show`/`cancel` | Pre-scoped query on `supplier_taxpayer_id`/`customer_taxpayer_id`; `TenantScope::requireTaxpayer()` on mutation |
| VAT Return | `VatLifecycleController::showReturn`/`decideApproval` | `TenantScope::requireTaxpayer($actor, $version->taxpayer_id)` |
| Refund Claim | `RefundController::checks`/`dispute` | `TenantScope::requireTaxpayer($actor, $claim->taxpayer_id)` + requester-identity check |
| Audit Case | `AuditCaseController::timeline`/`evidence` | `! TenantScope::isNational($actor) && $actor->taxpayer_id !== $case->taxpayer_id` throws 403 |
| Document | `DocumentController::versions`/`download`/`supersede` | Fetch-unscoped-then-`OrganisationResolver::resolve()` -- verified this actually throws, not just present |
| Quotation/Business Party/Expense/Fixed Asset/Logistics/Project | various | `OrganisationResolver::resolve()` + `where('organisation_id', ...)` |
| Workflow | `WorkflowController::decideTask`/`publishVersion` | `EntitlementGate::assert()` + `where('organisation_id', ...)` joins |

## One defense-in-depth hardening applied anyway

`App\Services\Access\UserRoleScopeGrantService::grant()`/`revoke()` and
`App\Http\Controllers\Access\AccessRightsViewController::index()` had
**no** query- or service-level tenant-scope check of their own -- `grant()`
can assign any role including `SUPER_ADMIN` itself, `revoke()` accepts any
`UserRoleScopeGrant` by id with no ownership check, and `index()` lists
every user and grant system-wide. All three relied entirely on
`access-rights:read`/`access-rights:manage` being held only by
national-scope roles in the static permission map.

**Verified not exploitable today**: `Permissions::ROLE_PERMISSIONS` grants
`access-rights:read`/`manage` to exactly two roles, `NAMRA_SYSTEM_ADMIN`
and `SUPER_ADMIN`, both listed in `Permissions::NATIONAL_SCOPE_ROLES` --
so no tenant-scoped actor can reach this screen through any role
currently defined. Not a live finding, and not written up as one.

**Hardened regardless**: for a screen that can grant `SUPER_ADMIN` itself,
resting entirely on the permission map staying correct forever is too
large a blast radius to leave unasserted at the point of use. A single
future role addition or edit to `Permissions::ROLE_PERMISSIONS` that gave
`access-rights:manage` to a tenant-scoped role would otherwise silently
reopen this screen to that tenant with zero other signal. `grant()` and
`revoke()` now assert `TenantScope::isNational($actor)` directly and throw
`AuthorizationException` (clean 403) if it fails; `index()` asserts the
same via `abort_unless()`. This mirrors the same "fix cheap, strictly-
more-correct hardening the same day" precedent this codebase's own prior
red-team passes established for `publishWorkflowVersion()` and friends,
just for an authorization gap rather than a duplicate-submission one.

**Validation:**
- [x] New regression test calls `UserRoleScopeGrantService::grant()`
      directly (bypassing the controller's own permission gate entirely,
      simulating a hypothetical future permission-map drift) with an
      actor holding `role = SUPER_ADMIN` but a real, non-null
      `taxpayer_id` -- confirms the service's own guard throws
      `AuthorizationException` independent of what the permission map
      says (`test_the_service_itself_refuses_a_tenant_scoped_actor_regardless_of_the_permission_map`).
- [x] All 16 pre-existing `AccessRightsViewTest` cases (every one driven
      through the real national-scope demo roles) still pass unchanged --
      confirms the hardening adds no new restriction for any actor who
      could legitimately reach this screen before.
- [x] Full suite: 624 tests, 0 regressions.

## What this pass did not cover

- **Vertical privilege escalation** (a lower-privileged role reaching a
  higher-privileged action) was not this pass's focus -- that's already
  covered by this codebase's own existing `tests/Feature/Security/
  TenantRoleEscalationTest.php`, which predates this session's work.
- **True concurrent-actor race conditions** in authorization checks
  (e.g. a TOCTOU window between a scope check and the write it gates)
  were not probed -- a different vulnerability class from IDOR, and this
  environment's single-worker dev server can't produce genuine
  concurrency to test it against anyway (the same limitation noted in
  every prior pass in this series).
- **The remaining 8 phases** of the user's original 10-phase brief (High
  Interaction Stress, Concurrent User Simulation, Authentication &
  Session Robustness beyond what RT-007 already covered, Input Validation
  & Robustness, Performance Under Heavy Use, Resilience to User Errors,
  Fraud Resistance, UX Failure Discovery) remain untouched.

## Summary

No confirmed finding this pass -- a genuinely positive result after a
real, thorough check (~45 methods across every tenant-owned resource
type in the app), not a skipped or superficial one. One defense-in-depth
hardening applied regardless, for the single highest-blast-radius screen
in the app (a screen that can grant `SUPER_ADMIN`), on the same
"cheap and strictly more correct" basis this series has applied
throughout.
