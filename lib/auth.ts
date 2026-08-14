import { getChatGPTUser } from "@/app/chatgpt-auth";
import { ensureDatabase } from "@/db/runtime";
import type { UserContext } from "./domain/types";

type UserRow = {
  id: string;
  external_user_id: string;
  email: string;
  display_name: string;
  role: string;
  taxpayer_id: string | null;
  status: string;
};

type MembershipRow = { organisation_id: string };
type CodeRow = { code: string };

export class AccessDeniedError extends Error {
  readonly status: number;

  constructor(message: string, status = 403) {
    super(message);
    this.name = "AccessDeniedError";
    this.status = status;
  }
}

async function buildUserContext(db: D1Database, row: UserRow, isDevelopmentIdentity: boolean): Promise<UserContext> {
  const membership = await db
    .prepare("SELECT organisation_id FROM organisation_memberships WHERE user_id=? AND status='ACTIVE' ORDER BY created_at LIMIT 1")
    .bind(row.id)
    .first<MembershipRow>();
  const organisationId = membership?.organisation_id ?? null;
  const capabilities = organisationId
    ? (await db
        .prepare("SELECT capability_code AS code FROM user_capability_assignments WHERE user_id=? AND organisation_id=? AND status='ACTIVE'")
        .bind(row.id, organisationId)
        .all<CodeRow>()).results.map((item) => item.code)
    : [];
  const dynamicPermissions = organisationId
    ? (await db
        .prepare(`SELECT DISTINCT rp.permission_code AS code
          FROM user_role_assignments ura
          JOIN organisation_roles r ON r.id=ura.organisation_role_id AND r.status='ACTIVE'
          JOIN organisation_role_permissions rp ON rp.organisation_role_id=r.id
          WHERE ura.user_id=? AND ura.organisation_id=? AND ura.status='ACTIVE'`)
        .bind(row.id, organisationId)
        .all<CodeRow>()).results.map((item) => item.code)
    : [];

  return {
    userId: row.id,
    email: row.email,
    displayName: row.display_name,
    role: row.role,
    taxpayerId: row.taxpayer_id,
    organisationId,
    capabilities,
    dynamicPermissions,
    isDevelopmentIdentity,
  };
}

export async function getCurrentUser(): Promise<UserContext> {
  const chatGptUser = await getChatGPTUser();
  const db = await ensureDatabase();

  if (chatGptUser) {
    const row = await db
      .prepare(`SELECT u.* FROM app_users u
        JOIN identity_links l ON l.user_id=u.id AND l.status='ACTIVE'
        JOIN identity_providers p ON p.id=l.provider_id AND p.status='ACTIVE'
        WHERE u.status='ACTIVE' AND p.provider_key='SITES_WORKSPACE' AND l.subject=? LIMIT 1`)
      .bind(chatGptUser.userId)
      .first<UserRow>();
    if (!row) {
      throw new AccessDeniedError("Your identity is authenticated but has not been provisioned for VAT-MSA.");
    }
    return buildUserContext(db, row, false);
  }

  if (process.env.NODE_ENV === "production") {
    throw new AccessDeniedError("Authentication is required.", 401);
  }

  const row = await db.prepare(`SELECT u.* FROM app_users u
    JOIN identity_links l ON l.user_id=u.id AND l.status='ACTIVE'
    JOIN identity_providers p ON p.id=l.provider_id AND p.status='ACTIVE'
    WHERE u.status='ACTIVE' AND p.provider_key='SITES_WORKSPACE' AND l.subject='local-demo-user' LIMIT 1`).first<UserRow>();
  if (!row) throw new AccessDeniedError("The local pilot identity is unavailable.", 500);
  return buildUserContext(db, row, true);
}

const ROLE_PERMISSIONS: Record<string, ReadonlySet<string>> = {
  PILOT_ADMIN: new Set(["dashboard:read", "identity:read", "taxpayers:read", "registrations:read", "registrations:submit", "organisations:manage", "invoices:read", "invoices:submit", "exceptions:read", "returns:read", "returns:generate", "returns:approve", "returns:submit", "vat-adjustments:manage", "reconciliation:manage", "compliance:read", "cases:manage", "disputes:manage", "refunds:read", "refunds:request", "refunds:review", "risk:read", "risk:review", "communications:manage", "consents:manage", "integrations:read", "integrations:manage", "developer:read", "developer:manage", "offline:read", "offline:sync", "reports:read", "reports:run", "platform:read", "payments:read", "audit:read", "security:read", "commercial:read", "parties:manage", "quotations:manage", "accounting:read", "accounting:post", "expenses:read", "expenses:manage", "inventory:read", "inventory:manage", "projects:read", "projects:manage", "imports:read", "imports:manage", "documents:read", "documents:upload"]),
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
  NAMRA_COMPLIANCE_OFFICER: new Set(["dashboard:read", "identity:read", "taxpayers:read", "registrations:read", "invoices:read", "exceptions:read", "returns:read", "reconciliation:manage", "compliance:read", "cases:manage", "disputes:manage", "refunds:read", "risk:read", "risk:review", "communications:manage", "integrations:read", "reports:read", "reports:run", "platform:read", "payments:read"]),
  NAMRA_AUDITOR: new Set(["dashboard:read", "identity:read", "taxpayers:read", "registrations:read", "invoices:read", "exceptions:read", "returns:read", "audit:read", "reconciliation:manage", "compliance:read", "cases:manage", "disputes:manage", "refunds:read", "risk:read", "risk:review"]),
  NAMRA_REFUND_OFFICER: new Set(["dashboard:read", "taxpayers:read", "returns:read", "compliance:read", "refunds:read", "refunds:review", "risk:read", "communications:manage"]),
  NAMRA_SUPERVISOR: new Set(["dashboard:read", "identity:read", "taxpayers:read", "registrations:read", "invoices:read", "exceptions:read", "returns:read", "reconciliation:manage", "compliance:read", "cases:manage", "disputes:manage", "refunds:read", "refunds:review", "risk:read", "risk:review", "communications:manage", "integrations:read", "integrations:manage", "reports:read", "reports:run", "platform:read", "payments:read", "audit:read"]),
  NAMRA_SYSTEM_ADMIN: new Set(["dashboard:read", "identity:read", "taxpayers:read", "registrations:read", "organisations:manage", "administration:read", "administration:manage"]),
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
