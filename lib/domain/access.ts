import type { UserContext } from "./types";

/**
 * Pure RBAC/ABAC policy for Module 1 (Identity, Taxpayer & Organisation
 * Foundation). No DB access, no request/header awareness — everything here
 * is a function of a `UserContext` already resolved by `lib/auth.ts`, which
 * re-exports this module's surface for backward compatibility. Kept
 * separate from `lib/auth.ts` (which imports `app/chatgpt-auth.ts` and
 * therefore `next/headers`) so this policy logic stays unit-testable
 * without a Next.js/edge runtime.
 */

export class AccessDeniedError extends Error {
  readonly status: number;

  constructor(message: string, status = 403) {
    super(message);
    this.name = "AccessDeniedError";
    this.status = status;
  }
}

const ROLE_PERMISSIONS: Record<string, ReadonlySet<string>> = {
  PILOT_ADMIN: new Set(["dashboard:read", "identity:read", "taxpayers:read", "taxpayers:suspend", "registrations:read", "registrations:submit", "registrations:approve", "organisations:manage", "invoices:read", "invoices:submit", "invoices:cancel", "exceptions:read", "returns:read", "returns:generate", "returns:approve", "returns:submit", "vat-adjustments:manage", "vat-rules:read", "vat-rules:manage", "reconciliation:manage", "compliance:read", "cases:manage", "cases:override-sod", "disputes:manage", "obligations:manage", "refunds:read", "refunds:request", "refunds:review", "risk:read", "risk:review", "communications:manage", "consents:manage", "integrations:read", "integrations:manage", "developer:read", "developer:manage", "offline:read", "offline:sync", "reports:read", "reports:run", "platform:read", "payments:read", "audit:read", "security:read", "commercial:read", "parties:manage", "quotations:manage", "accounting:read", "accounting:post", "expenses:read", "expenses:manage", "inventory:read", "inventory:manage", "projects:read", "projects:manage", "imports:read", "imports:manage", "documents:read", "documents:upload"]),
  TAXPAYER_OWNER: new Set(["dashboard:read", "identity:read", "taxpayers:read", "registrations:read", "registrations:submit", "organisations:manage", "invoices:read", "invoices:submit", "exceptions:read", "returns:read", "returns:generate", "returns:approve", "returns:submit", "vat-adjustments:manage", "compliance:read", "disputes:manage", "refunds:read", "refunds:request", "consents:manage", "integrations:read", "integrations:manage", "developer:read", "developer:manage", "offline:read", "offline:sync", "reports:read", "reports:run", "commercial:read", "parties:manage", "quotations:manage", "accounting:read", "accounting:post", "expenses:read", "expenses:manage", "inventory:read", "inventory:manage", "projects:read", "projects:manage", "imports:read", "imports:manage", "documents:read", "documents:upload"]),
  TAXPAYER_ADMIN: new Set(["dashboard:read", "identity:read", "taxpayers:read", "registrations:read", "organisations:manage", "invoices:read", "invoices:submit", "exceptions:read", "returns:read", "returns:generate", "returns:approve", "returns:submit", "vat-adjustments:manage", "compliance:read", "disputes:manage", "refunds:read", "refunds:request", "consents:manage", "integrations:read", "integrations:manage", "developer:read", "developer:manage", "offline:read", "offline:sync", "reports:read", "reports:run", "commercial:read", "parties:manage", "quotations:manage", "accounting:read", "expenses:read", "expenses:manage", "inventory:read", "inventory:manage", "projects:read", "projects:manage", "imports:read", "imports:manage", "documents:read", "documents:upload"]),
  TAXPAYER_ACCOUNTANT: new Set(["dashboard:read", "identity:read", "taxpayers:read", "invoices:read", "invoices:submit", "exceptions:read", "returns:read", "returns:generate", "returns:submit", "vat-adjustments:manage", "commercial:read", "parties:manage", "accounting:read", "accounting:post", "expenses:read", "expenses:manage", "projects:read", "imports:read", "imports:manage", "documents:read", "documents:upload"]),
  TAXPAYER_STAFF: new Set(["dashboard:read", "identity:read", "invoices:read", "invoices:submit", "exceptions:read", "commercial:read", "parties:manage", "quotations:manage", "expenses:read", "expenses:manage", "inventory:read", "inventory:manage", "projects:read", "documents:read", "documents:upload"]),
  TAXPAYER_VIEWER: new Set(["dashboard:read", "identity:read", "invoices:read", "returns:read", "commercial:read", "accounting:read", "expenses:read", "inventory:read", "projects:read", "imports:read", "documents:read"]),
  SELLER_ADMIN: new Set(["dashboard:read", "identity:read", "invoices:read", "invoices:submit", "exceptions:read", "returns:read", "commercial:read", "parties:manage", "quotations:manage", "inventory:read", "inventory:manage", "projects:read", "projects:manage"]),
  SELLER_OPERATOR: new Set(["dashboard:read", "identity:read", "invoices:read", "invoices:submit", "exceptions:read", "commercial:read", "parties:manage", "quotations:manage", "inventory:read", "inventory:manage", "projects:read"]),
  SELLER_VIEWER: new Set(["dashboard:read", "identity:read", "invoices:read", "returns:read", "commercial:read", "inventory:read", "projects:read"]),
  BUYER_ADMIN: new Set(["dashboard:read", "identity:read", "invoices:read", "exceptions:read", "returns:read", "parties:manage", "expenses:read", "expenses:manage", "imports:read", "imports:manage", "documents:read", "documents:upload"]),
  BUYER_USER: new Set(["dashboard:read", "identity:read", "invoices:read", "exceptions:read", "parties:manage", "expenses:read", "expenses:manage", "imports:read", "documents:read", "documents:upload"]),
  NAMRA_COMPLIANCE_OFFICER: new Set(["dashboard:read", "identity:read", "taxpayers:read", "registrations:read", "invoices:read", "exceptions:read", "returns:read", "reconciliation:manage", "compliance:read", "cases:manage", "disputes:manage", "obligations:manage", "refunds:read", "risk:read", "risk:review", "communications:manage", "integrations:read", "reports:read", "reports:run", "platform:read", "payments:read", "vat-rules:read"]),
  NAMRA_AUDITOR: new Set(["dashboard:read", "identity:read", "taxpayers:read", "registrations:read", "invoices:read", "exceptions:read", "returns:read", "audit:read", "reconciliation:manage", "compliance:read", "cases:manage", "disputes:manage", "obligations:manage", "refunds:read", "risk:read", "risk:review", "vat-rules:read"]),
  NAMRA_REFUND_OFFICER: new Set(["dashboard:read", "taxpayers:read", "returns:read", "compliance:read", "refunds:read", "refunds:review", "risk:read", "communications:manage"]),
  NAMRA_SUPERVISOR: new Set(["dashboard:read", "identity:read", "taxpayers:read", "registrations:read", "invoices:read", "exceptions:read", "returns:read", "reconciliation:manage", "compliance:read", "cases:manage", "cases:override-sod", "disputes:manage", "obligations:manage", "refunds:read", "refunds:review", "risk:read", "risk:review", "communications:manage", "integrations:read", "integrations:manage", "reports:read", "reports:run", "platform:read", "payments:read", "audit:read", "vat-rules:read"]),
  NAMRA_SYSTEM_ADMIN: new Set(["dashboard:read", "identity:read", "taxpayers:read", "taxpayers:suspend", "registrations:read", "registrations:approve", "organisations:manage", "administration:read", "administration:manage", "vat-rules:read", "vat-rules:manage", "invoices:cancel"]),
  SUPER_ADMIN: new Set(["dashboard:read", "platform:read", "platform:manage", "integrations:read", "integrations:manage", "security:read"]),
  INFRASTRUCTURE_ADMIN: new Set(["dashboard:read", "platform:read", "platform:manage", "integrations:read", "security:read"]),
  DEVELOPER_PARTNER: new Set(["dashboard:read", "developer:read", "developer:manage", "integrations:read"]),
  INTERNAL_AUDITOR: new Set(["dashboard:read", "audit:read"]),
  SECURITY_ANALYST: new Set(["dashboard:read", "security:read", "audit:read"]),
};

const WORKSPACE_READ = ["workspace:read", "search:read", "licensing:read"];
const ORGANISATION_CONTROL = [
  ...WORKSPACE_READ,
  "licensing:request",
  "licensing:manage",
  "administration:read",
  "administration:manage",
  "employees:read",
  "employees:manage",
  "roles:read",
  "roles:manage",
  "workflows:read",
  "workflows:manage",
  "workflows:decide",
  "access-governance:read",
  "access-governance:manage",
];
const CONTROL_PLANE_PERMISSIONS: Record<string, ReadonlySet<string>> = {
  PILOT_ADMIN: new Set(ORGANISATION_CONTROL),
  TAXPAYER_OWNER: new Set(ORGANISATION_CONTROL),
  TAXPAYER_ADMIN: new Set(ORGANISATION_CONTROL),
  TAXPAYER_ACCOUNTANT: new Set([...WORKSPACE_READ, "employees:read", "roles:read", "workflows:read", "workflows:decide", "access-governance:read"]),
  TAXPAYER_STAFF: new Set(["workspace:read", "search:read"]),
  TAXPAYER_VIEWER: new Set(["workspace:read", "search:read"]),
  NAMRA_SYSTEM_ADMIN: new Set(ORGANISATION_CONTROL),
};

/**
 * The full effective RBAC+ABAC predicate set for a session: static
 * role-derived permissions unioned with tenant-defined dynamic permissions,
 * deduped and sorted. This is the single source of truth `hasPermission`
 * checks against, and what `getUserAccess` exposes as its own query — every
 * module's authorization checks depend on this being complete and correct.
 */
export function resolveEffectivePermissions(user: UserContext): string[] {
  const combined = new Set<string>(user.dynamicPermissions);
  for (const permission of ROLE_PERMISSIONS[user.role] ?? []) combined.add(permission);
  for (const permission of CONTROL_PLANE_PERMISSIONS[user.role] ?? []) combined.add(permission);
  return [...combined].sort();
}

export function hasPermission(user: UserContext, permission: string): boolean {
  return (ROLE_PERMISSIONS[user.role]?.has(permission) ?? false)
    || (CONTROL_PLANE_PERMISSIONS[user.role]?.has(permission) ?? false)
    || user.dynamicPermissions.includes(permission);
}

export function requirePermission(user: UserContext, permission: string): void {
  if (!hasPermission(user, permission)) {
    throw new AccessDeniedError(`Role ${user.role} does not have ${permission} permission.`);
  }
}

export function isNationalScope(user: UserContext): boolean {
  return user.taxpayerId === null && ["PILOT_ADMIN", "NAMRA_COMPLIANCE_OFFICER", "NAMRA_AUDITOR", "NAMRA_REFUND_OFFICER", "NAMRA_SUPERVISOR", "NAMRA_SYSTEM_ADMIN", "INTERNAL_AUDITOR", "SECURITY_ANALYST"].includes(user.role);
}

export function requireTaxpayerScope(user: UserContext, taxpayerId: string): void {
  if (!isNationalScope(user) && user.taxpayerId !== taxpayerId) {
    throw new AccessDeniedError("The requested record is outside your authorised taxpayer scope.");
  }
}

export type EffectiveAccess = {
  userId: string;
  organisationId: string | null;
  taxpayerId: string | null;
  role: string;
  isNationalScope: boolean;
  isDevelopmentIdentity: boolean;
  capabilities: string[];
  permissions: string[];
};

/**
 * Module 1 `GetUserAccess` query: the full effective RBAC+ABAC predicate set
 * for the current session, computed live from the actor's UserContext on
 * every call — never cache this, downstream modules trust it to reflect the
 * current membership/role/capability state, not a stale snapshot.
 */
export function getUserAccess(user: UserContext): EffectiveAccess {
  return {
    userId: user.userId,
    organisationId: user.organisationId,
    taxpayerId: user.taxpayerId,
    role: user.role,
    isNationalScope: isNationalScope(user),
    isDevelopmentIdentity: user.isDevelopmentIdentity,
    capabilities: [...user.capabilities].sort(),
    permissions: resolveEffectivePermissions(user),
  };
}
