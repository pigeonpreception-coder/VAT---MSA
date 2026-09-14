# VAT-MSA Red Team Assessment — UX Failure Discovery (Phase 9)

**Date:** 2026-09-14
**Scope:** Phase 9 of the user's original 10-phase brief. This pass looks
for genuine user-experience failures reachable through the real rendered
UI -- broken links, dead-end clicks, confusing states -- rather than the
backend-focused correctness/security gaps prior passes covered.
**Method:** A real Chromium browser (Playwright) logged in as each of
several demo roles in turn and crawled every link the sidebar rendered for
that role, recording the HTTP status and any browser console error for
each. Any non-200 was investigated by reading the destination controller's
own `authorize('permission', ...)` call and comparing it against the
sidebar Blade template's `@can` gate for that link. Once the first
mismatch was found and understood, the remainder of the pass switched to
a faster, exhaustive static method: every `route()` call inside every
`@can('permission', 'X')` block in `resources/views/layouts/app.blade.php`
was cross-referenced against its destination's actual required permission
(read directly from the controller, or -- for `$plannedRoute` placeholder
pages -- from the permission literally passed to that helper), using
`Permissions::effectiveForRole()` as the authoritative per-role permission
set (not the literal `ROLE_PERMISSIONS` array alone, which omits
permissions a role receives only via the separate
`CONTROL_PLANE_PERMISSIONS` map -- a distinction an earlier, hastier pass
over this same file nearly got wrong).
**Environment:** This session's own sandboxed dev stack -- PHP
(`artisan serve`), real MySQL, a real headless Chromium browser for the
initial discovery crawl, full suite (636 tests before this pass, 642
after).
**Tester:** Claude (Anthropic), acting as the migration engineer, at the
user's explicit request.

---

## 1. Confirmed finding

### RT-021 — Two sidebar groups rendered links several roles could not actually use

> **Status: FIXED (2026-09-14).** The sidebar's "Invoice Management" and
> "Operations" groups each bundled multiple links under a single `@can`
> permission check, but some of those links' destination pages actually
> required a *different*, more specific permission. Seven roles across
> the two groups held the group's bundling permission without holding
> every link's real permission, so they saw sidebar entries that produced
> a clean `403 Forbidden` the instant they were clicked -- not a security
> hole (the controller's own gate correctly refused the request every
> time), but a dead-end click with no indication in the menu itself that
> the destination was unreachable.

| | |
|---|---|
| **Severity** | **Low** (pure UX -- no data exposed, no action performed; every affected controller's own authorization was already correct) |
| **User role** | RT-021a (Foreign Invoices): `TAXPAYER_STAFF`, `SELLER_ADMIN`, `SELLER_OPERATOR`, `SELLER_VIEWER`, `NAMRA_COMPLIANCE_OFFICER`, `NAMRA_VAT_AUDITOR`, `NAMRA_VAT_SUPERVISOR`. RT-021b (Operations group): `TAXPAYER_ACCOUNTANT`, `TAXPAYER_STAFF`, `TAXPAYER_VIEWER`, `BUYER_ADMIN`, `BUYER_USER`. |
| **Feature** | The "Invoice Management" and "Operations" sidebar groups, `resources/views/layouts/app.blade.php` |

**Reproduction steps (pre-fix, live against a running instance with a
real Chromium browser):**

1. Log in as `namra-auditor@vat-msa.test` (role `NAMRA_VAT_AUDITOR`).
2. Collect every link the rendered sidebar contains, then visit each one
   in turn with the same authenticated session.
3. Observe the HTTP status of each visit and any browser console error.

**Actual behavior (pre-fix):**
- **RT-021a.** The "Foreign Invoices" link (`/invoice-management/foreign`)
  was rendered under the same `@can('permission', 'invoices:read')` gate
  as its two siblings ("Local Invoices," "All Invoices"), but
  `ForeignInvoiceViewController::index()` actually requires
  `imports:read` -- a separate permission that pulls customs/E-Tariff
  import records, not invoice records. `NAMRA_VAT_AUDITOR` holds
  `invoices:read` without `imports:read`, so the link rendered and then
  returned `403 Forbidden` (confirmed both via the live crawl and a
  browser console error: `Failed to load resource: the server responded
  with a status of 403`). The same gap was confirmed, via
  `Permissions::effectiveForRole()`, to affect six further roles.
- **RT-021b.** The "Operations" group's six links (Expenses/Inventory
  register, Human Resources, Immovable Assets, Movable Assets, Logistics,
  ERP) were all rendered under a single `@can('permission',
  'expenses:read')` gate, but `HumanResourcesViewController::index()`
  requires `employees:read`, `FixedAssetViewController::indexImmovable
  ()`/`indexMovable()` require `fixed-assets:read`, and
  `LogisticsViewController::index()` requires `logistics:read` -- three
  more permissions distinct from `expenses:read`. Live-confirmed against
  a real `BUYER_ADMIN` fixture (a genuine `Organisation` +
  `OrganisationCapability` row, not a bare user -- an earlier attempt
  using a bare user produced a wall of unrelated 403s from missing
  tenant-resolution context entirely, corrected before drawing any
  conclusion): four of the group's six links (Human Resources, Immovable
  Assets, Movable Assets, Logistics) all 403'd, while the two `BUYER_ADMIN`
  genuinely has permission for (the register itself, ERP) loaded
  correctly.

**Business impact:** Low in isolation -- no data was exposed and no
unauthorized action was possible, since every destination controller's
own permission gate already refused the request correctly. The cost is
purely in trust and efficiency: a user who sees a menu item has every
reason to expect clicking it does something, and a clean 403 with no
prior warning reads as "this system is broken" rather than "this menu
item shouldn't be here for me," a worse first impression than simply not
showing the item at all -- especially for roles like `NAMRA_VAT_AUDITOR`
and `NAMRA_VAT_SUPERVISOR`, whose daily workflow revolves around
navigating exactly this sidebar.

**Root-cause hypothesis:** Both groups were built by wrapping several
related links in one `@can` check for the group's *primary* link's
permission, an easy and usually-correct shortcut that breaks silently the
moment a later addition to the group needs a narrower permission than its
siblings -- `imports:read` for the customs-specific Foreign Invoices page
added after Local/All Invoices, and `employees:read`/`fixed-assets:read`/
`logistics:read` for four pages added to "Operations" after the shared
`expenses:read`-gated register that gave the group its name. Nothing
enforces that a sidebar link's gate matches its destination's actual
`authorize()` call -- the two are independent, hand-maintained facts that
can silently drift apart, exactly as they did here.

**Recommended solution (implemented):** Split each mismatched link out of
its sibling's `@can` block into its own, gated on the exact permission its
controller actually requires:

```blade
{{-- Invoice Management group --}}
@can('permission', 'invoices:read')
    <li>...Local Invoices...</li>
@endcan
@can('permission', 'imports:read')
    <li>...Foreign Invoices...</li>
@endcan
@can('permission', 'invoices:read')
    <li>...All Invoices...</li>
@endcan

{{-- Operations group --}}
@can('permission', 'expenses:read')
    <li>...Expenses, Inventory & Project Register...</li>
@endcan
@can('permission', 'employees:read')
    <li>...Human Resources Module...</li>
@endcan
@can('permission', 'fixed-assets:read')
    <li>...Immovable Asset Management...</li>
    <li>...Movable Asset Management...</li>
@endcan
@can('permission', 'logistics:read')
    <li>...Logistics Module...</li>
@endcan
@can('permission', 'expenses:read')
    <li>...ERP Module...</li>
@endcan
```

A systematic cross-reference of every remaining `@can`-gated link in the
sidebar against its destination's real permission (including every
`$plannedRoute` placeholder page, which passes its permission as a
literal constructor argument rather than an `authorize()` call) found no
further mismatches -- these were the only two.

**Regression risks:** None expected -- a role holding every permission a
group's links require (the common case; `TAXPAYER_OWNER` alone holds all
of `invoices:read`/`imports:read`/`expenses:read`/`employees:read`/
`fixed-assets:read`/`logistics:read`) sees and can use every link exactly
as before. Verified live for both directions (a role missing one
permission no longer sees that one link; a role holding everything still
sees and can use everything) and with 4 new permanent regression tests.

**Validation checklist:**
- [x] `NAMRA_VAT_AUDITOR` (has `invoices:read`, lacks `imports:read`) no
      longer sees or can dead-click "Foreign Invoices" -- re-verified live.
- [x] `TAXPAYER_OWNER` (has both) still sees and can use "Foreign
      Invoices" -- re-verified live.
- [x] `BUYER_ADMIN` (has `expenses:read`, lacks the other three) no
      longer sees or can dead-click Human Resources/Immovable/Movable/
      Logistics, while still seeing and using the register and ERP module
      -- re-verified live against a real `Organisation`+`OrganisationCapability`
      fixture.
- [x] `TAXPAYER_OWNER` (has all four Operations permissions, with a real
      license/entitlement setup) still sees and can use every one of the
      six Operations links -- re-verified live.
- [x] A systematic cross-check of every other sidebar `@can` block
      against its links' real controller/closure permissions found zero
      further mismatches.
- [x] 4 new permanent regression tests in a new
      `tests/Feature/Navigation/SidebarLinkPermissionTest.php`.
- [x] Full suite (642 tests) passes with zero regressions.

## 2. Positive controls confirmed (not findings)

- **No other broken links found across ten distinct roles' sidebars**
  (taxpayer owner, NamRA auditor/compliance/senior-auditor/supervisor,
  system admin, platform admin, infrastructure admin, developer partner,
  security analyst) in the initial live crawl, and zero further mismatches
  in the systematic static cross-check of the remaining ~40 sidebar links
  against their real controller/closure permissions.
- **Zero browser console errors** beyond the two 403s this pass itself
  found and fixed -- no JavaScript exceptions, no failed asset loads,
  across every page visited in this pass.
- **A methodology self-correction worth recording**: cross-referencing
  role permissions using only the literal `Permissions::ROLE_PERMISSIONS`
  array (rather than `Permissions::effectiveForRole()`, which also
  includes the separate `CONTROL_PLANE_PERMISSIONS` map) would have
  produced false positives -- several roles that *look* like they're
  missing a permission from the literal array alone actually hold it via
  the second map. Every finding in this report was confirmed against the
  authoritative combined set before being treated as real, and the
  `BUYER_ADMIN` live reproduction was itself redone with a proper
  `Organisation`/`OrganisationCapability` fixture after an initial,
  bare-user attempt produced a wall of unrelated tenant-resolution 403s
  that would have muddied the actual finding.

## 3. What this pass did not cover

- **Non-sidebar UX** -- form validation messaging clarity, loading
  states, empty-state design, mobile/responsive layout beyond the
  already-fixed sidebar-overflow work from an earlier session pass, and
  in-page (not sidebar) links and buttons were not crawled.
- **Every role** -- ten of the platform's roles were live-crawled; the
  remaining roles' sidebars were covered by the systematic static
  cross-check instead (which is exhaustive over links and permissions,
  independent of which specific roles hold them), not by an individual
  live click-through.
- **The remaining phases** of the user's original 10-phase brief (High
  Interaction Stress, Concurrent User Simulation, Performance Under Heavy
  Use) remain untouched, limited by this session's single-worker
  `php artisan serve` dev environment, a constraint noted consistently
  throughout this whole assessment.

## 4. Summary

| ID | Title | Severity |
|---|---|---|
| RT-021 | Two sidebar groups rendered dead-end links for seven roles | Low — **FIXED** |
| — | Every other sidebar link, across ten live-crawled roles and a systematic static check of the rest | Checked, already correct |

**Overall assessment:** one genuine UX gap found and fixed across two
sidebar groups -- links visible to a role that could not actually use
them, discovered by literally clicking through the real rendered UI as
several different users rather than reasoning about permissions in the
abstract. No security exposure (every destination page's own
authorization was already correct); the fix is purely about the menu
honestly reflecting what a given user can do.
