# Commercial licence enforcement workflows

## Central decision

Every commercial page loader, API route, search, export and business command declares its `feature_id` and operation class. The central License & Entitlement Service evaluates:

1. canonical organisation and active membership;
2. commercial subscription/licence state and effective dates;
3. `feature.authority_domain = COMMERCIAL_SAAS`;
4. plan entitlement/module and any quantitative limit;
5. operation policy (`READ`, `EXPORT`, `SEARCH`, `COMMAND`, `ADMIN`);
6. capacity exception and user/usage constraints;
7. RBAC/ABAC and workflow policy.

No module may reimplement or cache an allow decision beyond its evidence expiry. Government tax features are routed away before commercial licence evaluation.

## Expiry continuity

Licence expiry is non-destructive. The default policy denies commercial mutations and new business commands, preserves immutable/history records, and permits approved read, export, audit and compliance continuity for authorized existing members. Search is treated as read only when its result set remains tenant-scoped and export policy is satisfied. Government tax access is not expired by the commercial event.

## Seat transaction

`FINITE`: reserve a seat for accepted counting states using a serialized transaction and `usage < limit` database trigger. `UNLIMITED`: explicitly skip only the numeric comparison. `NOT_APPLICABLE`: cannot be used for the user-seat feature. Invitations expire/revoke and release reservations. Deactivation releases a seat; deletion is prohibited.

## Upgrade and downgrade

An upgrade is visible only after approved activation and entitlement-version increment. A downgrade below use creates a capacity exception, blocks new seat consumption, never deletes users and retains administrator remediation/export access. The exception resolves only after measured usage fits the active finite limit or an approved upgrade supersedes it.

## Stable denial codes

- `COMMERCIAL_LICENSE_REQUIRED`
- `COMMERCIAL_LICENSE_INACTIVE`
- `COMMERCIAL_FEATURE_NOT_ENTITLED`
- `USER_LICENSE_LIMIT_REACHED`
- `LICENSE_CAPACITY_EXCEPTION`
- `AUTHORITY_DOMAIN_MISMATCH`
- `ORGANISATION_SCOPE_DENIED`
- `STEP_UP_REQUIRED`

Denials disclose no cross-tenant counts or plan details to unauthorized callers.
