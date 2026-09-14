# VAT-MSA Sidebar Audit — Missing Link and Empty-Group Findings

**Date:** 2026-09-14
**Scope:** User-directed: asked why the sidebar for a `SUPER_ADMIN`-type
account "worked properly" in a separate (non-dev) environment, then, once
it became clear that environment couldn't be inspected directly, asked
to "fix the sidebar for all users." This pass re-audits
`resources/views/layouts/app.blade.php` from scratch rather than assuming
the earlier UX Failure Discovery pass (RT-021, 2026-09-14) caught
everything -- it fixed two permission-gate mismatches; this pass looks for
what that one didn't cover: routes with no sidebar entry at all, and
group-level (not just per-link) dead ends.
**Method:** A systematic, non-sampled cross-reference: every `route()`
call inside every sidebar `@can` block was matched against its
destination controller's actual `authorize('permission', ...)` call (or,
for `$plannedRoute` placeholders, the permission literal passed to that
helper) -- the same method RT-021 used, but this time also run in the
other direction: every page-rendering `Route::get(...)->name(...)` in
`routes/web.php` was checked for a corresponding sidebar link, to catch a
route with *no* link at all rather than only a mismatched one. Findings
were then verified live with a real Chromium browser (Playwright) against
running demo accounts, including a direct screenshot comparison of a
`SUPER_ADMIN` account's sidebar before and after the fix.
**Environment:** This session's own sandboxed dev stack -- PHP
(`artisan serve`), real MySQL, a real headless Chromium browser, full
suite (643 tests before this pass, 645 after).
**Tester:** Claude (Anthropic), acting as the migration engineer, at the
user's explicit request.

---

## 0. Why a `SUPER_ADMIN`-type account's sidebar "worked" while others didn't

The user's opening question referred to an account in an environment this
session has no access to (a different domain than this dev stack's demo
data), so it couldn't be inspected directly. But the underlying mechanism
is fully explained by `Permissions::ROLE_PERMISSIONS`
(`app/Support/Access/Permissions.php`): `SUPER_ADMIN` holds only 10
permissions total (`dashboard:read`, `platform:read`, `platform:manage`,
`integrations:read`, `integrations:manage`, `security:read`,
`security:manage`, `developer:read`, `developer:manage`,
`authority-governance:read`, `access-rights:read`,
`access-rights:manage`) -- none of the tenant-facing ones
(`invoices:read`, `expenses:read`, `commercial:read`, etc.) that most of
the sidebar's links are gated on. A role this permission-light simply has
very little sidebar surface to be broken *in the way RT-021 found* (a
link rendered but 403s on click): almost every link legitimately doesn't
render for it at all, so there was little exposed to that specific class
of bug. What section 2 below found is the flip side of that same fact --
a *different* problem this narrow permission set exposes far more
visibly than a broader role would.

## 1. Finding: the Inventory Module (POS) page had no sidebar link at all

> **Status: FIXED (2026-09-14).**

| | |
|---|---|
| **Severity** | Low (a real, working, permission-correct page was simply unreachable from navigation) |
| **Affected roles** | Every role holding `inventory:read`: `NAMRA_SYSTEM_SUPPORT`, `TAXPAYER_OWNER`, `TAXPAYER_ADMIN`, `TAXPAYER_STAFF`, `TAXPAYER_VIEWER`, `SELLER_ADMIN`, `SELLER_OPERATOR`, `SELLER_VIEWER` |
| **Feature** | `App\Http\Controllers\Operations\PosViewController::index()`, route `operations.inventory` (`/operations/inventory`) |

`PosViewController` is a real, fully built page -- ported from the
source's own `app/operations/inventory/{page.tsx,PosTerminal.tsx}`,
"Operations > Inventory Module, functioning like a Point-of-Sale System"
per the NamRA e-VAT MS master prompt (section 16E) -- correctly gated on
`inventory:read`, with a working checkout flow. Unlike RT-021's findings
(a link rendered under the *wrong* gate), this route had *no* sidebar
entry under any gate: reachable only by typing `/operations/inventory`
directly. Confirmed by cross-referencing every `Route::get` page route
against the sidebar's `route()` calls -- this was the only orphaned one.

**Fix:** added a link ("Inventory Module") to the Operations group,
gated on `inventory:read` -- the exact permission the controller itself
checks, so no mismatch is possible by construction. Verified live: the
link renders for a role holding `inventory:read`, is absent for one that
doesn't (`BUYER_ADMIN`), and a real click-through returns `200`.

## 2. Finding: an empty permission group still rendered a clickable, misleadingly-empty header

> **Status: FIXED (2026-09-14).**

| | |
|---|---|
| **Severity** | Low (no data exposed, no dead-end click -- a UX inconsistency) |
| **Affected roles** | Any role holding none of a group's underlying permissions -- most visibly `SUPER_ADMIN`, `INFRASTRUCTURE_ADMIN`, `SECURITY_ANALYST`, `INTERNAL_AUDITOR`, `DEVELOPER_PARTNER`, `NAMRA_VAT_SENIOR_AUDITOR` (all hold few or none of the tenant-facing permissions the "VAT Management" / "Invoice Management" / "Accounting & Finance" / "Operations" / "Quotation" / "Project Management" / "Registered" / "New Registration" / "Administration" groups gate on) |
| **Feature** | The nine accordion-style sidebar groups in `resources/views/layouts/app.blade.php` |

Each sidebar group's individual links are correctly gated per-link (and,
after RT-021 and this pass, correctly *matched* to their destinations'
real permissions) -- but the group's own clickable header, the
`<button>` that expands the accordion, was never conditional on
anything. For a role holding none of a group's items' permissions, every
item inside correctly disappears, but the header itself still renders as
a normal-looking, clickable dropdown trigger. Clicking it expands to a
visibly empty list -- not a 403, not a broken link, just nothing, which
reads as the application glitching rather than as "this menu has nothing
for you."

Live-confirmed for `SUPER_ADMIN`: 8 of the 9 accordion groups (all
except "Administration," which -- also confirmed here -- `SUPER_ADMIN`
in fact holds none of `administration:read`/`identity:read`/
`workflows:read` for either, so it should also have been empty) expand
to reveal literally nothing.

**Fix:** each group's own `<li>` wrapper is now conditional on the same
OR-across-items relationship its contents already have -- a group with a
single shared gate (Accounting & Finance, Quotation, Project Management,
Registered) is wrapped in that same `@can`; a group whose items use
different permissions (VAT Management, Invoice Management, Operations,
New Registration, Administration) is wrapped in a computed boolean that
is true if the user holds *any* one of that group's underlying
permissions. This is the same "don't show what you can't use" principle
RT-021 already applied per link, now applied one level up, at the group
header.

**Regression risk:** a role holding every permission a group needs sees
that group exactly as before -- verified live for `TAXPAYER_OWNER`
(holds permissions for all nine groups; full-page screenshot comparison
before and after showed no visible difference) and for `SUPER_ADMIN`
(now shows only Dashboard, Platform, and Access Rights -- the three
things it actually holds).

## 3. What this pass did not find

A full re-run of RT-021's own cross-reference method (every sidebar
`@can` against its destination's real permission) found the two RT-021
fixes still correctly in place and no further per-link mismatches beyond
what's documented above.

## 4. Summary

| ID | Title | Severity | Outcome |
|---|---|---|---|
| — | `PosViewController`'s Inventory Module page had no sidebar link at all | Low — **FIXED** | New link added, gated correctly by construction |
| — | An empty permission group still rendered a clickable, misleadingly-empty accordion header | Low — **FIXED** | Group headers now conditional on holding at least one underlying permission |
| — | Every other sidebar link, re-checked against its destination's real permission | Checked, already correct (RT-021's fixes hold) | — |

**Verified:** 2 new permanent regression tests in the existing
`tests/Feature/Navigation/SidebarLinkPermissionTest.php` (one for the new
Inventory Module link's negative control, one for a `SUPER_ADMIN`-style
role no longer seeing any of the now-hidden empty group headers). Full
suite: 645 tests, 0 regressions.

**Overall assessment:** two genuine UX gaps found and fixed -- one a
missing link for a real, working page; the other a group-level version
of RT-021's own per-link finding, most visible for permission-light
platform/national roles like `SUPER_ADMIN` rather than the broader
tenant roles RT-021's own crawl focused on. No security exposure in
either case: every destination page's own authorization was already
correct throughout.
