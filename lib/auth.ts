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

export class AccessDeniedError extends Error {
  readonly status: number;

  constructor(message: string, status = 403) {
    super(message);
    this.name = "AccessDeniedError";
    this.status = status;
  }
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
    return {
      userId: row.id,
      email: row.email,
      displayName: row.display_name,
      role: row.role,
      taxpayerId: row.taxpayer_id,
      isDevelopmentIdentity: false,
    };
  }

  if (process.env.NODE_ENV === "production") {
    throw new AccessDeniedError("Authentication is required.", 401);
  }

  const row = await db.prepare(`SELECT u.* FROM app_users u
    JOIN identity_links l ON l.user_id=u.id AND l.status='ACTIVE'
    JOIN identity_providers p ON p.id=l.provider_id AND p.status='ACTIVE'
    WHERE u.status='ACTIVE' AND p.provider_key='SITES_WORKSPACE' AND l.subject='local-demo-user' LIMIT 1`).first<UserRow>();
  if (!row) throw new AccessDeniedError("The local pilot identity is unavailable.", 500);
  return {
    userId: row.id,
    email: row.email,
    displayName: row.display_name,
    role: row.role,
    taxpayerId: row.taxpayer_id,
    isDevelopmentIdentity: true,
  };
}

const ROLE_PERMISSIONS: Record<string, ReadonlySet<string>> = {
  PILOT_ADMIN: new Set(["dashboard:read", "identity:read", "taxpayers:read", "registrations:read", "registrations:submit", "organisations:manage", "invoices:read", "invoices:submit", "exceptions:read", "returns:read", "returns:generate", "returns:approve", "returns:submit", "vat-adjustments:manage", "reconciliation:manage", "compliance:read", "cases:manage", "disputes:manage", "refunds:read", "refunds:request", "refunds:review", "risk:read", "risk:review", "communications:manage", "consents:manage", "integrations:read", "integrations:manage", "developer:read", "developer:manage", "offline:read", "offline:sync", "reports:read", "reports:run", "platform:read", "payments:read", "audit:read", "security:read", "commercial:read", "quotations:manage", "accounting:read", "accounting:post", "expenses:read", "expenses:manage", "inventory:read", "inventory:manage", "projects:read", "projects:manage", "imports:read", "imports:manage", "documents:read", "documents:upload"]),
  TAXPAYER_OWNER: new Set(["dashboard:read", "identity:read", "taxpayers:read", "registrations:read", "registrations:submit", "organisations:manage", "invoices:read", "invoices:submit", "exceptions:read", "returns:read", "returns:generate", "returns:approve", "returns:submit", "vat-adjustments:manage", "compliance:read", "disputes:manage", "refunds:read", "refunds:request", "consents:manage", "integrations:read", "integrations:manage", "developer:read", "developer:manage", "offline:read", "offline:sync", "reports:read", "reports:run", "commercial:read", "quotations:manage", "accounting:read", "accounting:post", "expenses:read", "expenses:manage", "inventory:read", "inventory:manage", "projects:read", "projects:manage", "imports:read", "imports:manage", "documents:read", "documents:upload"]),
  TAXPAYER_ADMIN: new Set(["dashboard:read", "identity:read", "taxpayers:read", "registrations:read", "organisations:manage", "invoices:read", "invoices:submit", "exceptions:read", "returns:read", "returns:generate", "returns:approve", "returns:submit", "vat-adjustments:manage", "compliance:read", "disputes:manage", "refunds:read", "refunds:request", "consents:manage", "integrations:read", "integrations:manage", "developer:read", "developer:manage", "offline:read", "offline:sync", "reports:read", "reports:run", "commercial:read", "quotations:manage", "accounting:read", "expenses:read", "expenses:manage", "inventory:read", "inventory:manage", "projects:read", "projects:manage", "imports:read", "imports:manage", "documents:read", "documents:upload"]),
  TAXPAYER_ACCOUNTANT: new Set(["dashboard:read", "identity:read", "taxpayers:read", "invoices:read", "invoices:submit", "exceptions:read", "returns:read", "returns:generate", "returns:submit", "vat-adjustments:manage", "commercial:read", "accounting:read", "accounting:post", "expenses:read", "expenses:manage", "projects:read", "imports:read", "imports:manage", "documents:read", "documents:upload"]),
  TAXPAYER_STAFF: new Set(["dashboard:read", "identity:read", "invoices:read", "invoices:submit", "exceptions:read", "commercial:read", "quotations:manage", "expenses:read", "expenses:manage", "inventory:read", "inventory:manage", "projects:read", "documents:read", "documents:upload"]),
  TAXPAYER_VIEWER: new Set(["dashboard:read", "identity:read", "invoices:read", "returns:read", "commercial:read", "accounting:read", "expenses:read", "inventory:read", "projects:read", "imports:read", "documents:read"]),
  SELLER_ADMIN: new Set(["dashboard:read", "identity:read", "invoices:read", "invoices:submit", "exceptions:read", "returns:read", "commercial:read", "quotations:manage", "inventory:read", "inventory:manage", "projects:read", "projects:manage"]),
  SELLER_OPERATOR: new Set(["dashboard:read", "identity:read", "invoices:read", "invoices:submit", "exceptions:read", "commercial:read", "quotations:manage", "inventory:read", "inventory:manage", "projects:read"]),
  SELLER_VIEWER: new Set(["dashboard:read", "identity:read", "invoices:read", "returns:read", "commercial:read", "inventory:read", "projects:read"]),
  BUYER_ADMIN: new Set(["dashboard:read", "identity:read", "invoices:read", "exceptions:read", "returns:read", "expenses:read", "expenses:manage", "imports:read", "imports:manage", "documents:read", "documents:upload"]),
  BUYER_USER: new Set(["dashboard:read", "identity:read", "invoices:read", "exceptions:read", "expenses:read", "expenses:manage", "imports:read", "documents:read", "documents:upload"]),
  NAMRA_COMPLIANCE_OFFICER: new Set(["dashboard:read", "identity:read", "taxpayers:read", "registrations:read", "invoices:read", "exceptions:read", "returns:read", "reconciliation:manage", "compliance:read", "cases:manage", "disputes:manage", "refunds:read", "risk:read", "risk:review", "communications:manage", "integrations:read", "reports:read", "reports:run", "platform:read", "payments:read"]),
  NAMRA_AUDITOR: new Set(["dashboard:read", "identity:read", "taxpayers:read", "registrations:read", "invoices:read", "exceptions:read", "returns:read", "audit:read", "reconciliation:manage", "compliance:read", "cases:manage", "disputes:manage", "refunds:read", "risk:read", "risk:review"]),
  NAMRA_REFUND_OFFICER: new Set(["dashboard:read", "taxpayers:read", "returns:read", "compliance:read", "refunds:read", "refunds:review", "risk:read", "communications:manage"]),
  NAMRA_SUPERVISOR: new Set(["dashboard:read", "identity:read", "taxpayers:read", "registrations:read", "invoices:read", "exceptions:read", "returns:read", "reconciliation:manage", "compliance:read", "cases:manage", "disputes:manage", "refunds:read", "refunds:review", "risk:read", "risk:review", "communications:manage", "integrations:read", "integrations:manage", "reports:read", "reports:run", "platform:read", "payments:read", "audit:read"]),
  INTERNAL_AUDITOR: new Set(["dashboard:read", "audit:read"]),
  SECURITY_ANALYST: new Set(["dashboard:read", "security:read", "audit:read"]),
};

export function hasPermission(user: UserContext, permission: string): boolean {
  return ROLE_PERMISSIONS[user.role]?.has(permission) ?? false;
}

export function requirePermission(user: UserContext, permission: string): void {
  if (!hasPermission(user, permission)) {
    throw new AccessDeniedError(`Role ${user.role} does not have ${permission} permission.`);
  }
}

export function isNationalScope(user: UserContext): boolean {
  return user.taxpayerId === null && ["PILOT_ADMIN", "NAMRA_COMPLIANCE_OFFICER", "NAMRA_AUDITOR", "NAMRA_REFUND_OFFICER", "NAMRA_SUPERVISOR", "INTERNAL_AUDITOR", "SECURITY_ANALYST"].includes(user.role);
}

export function requireTaxpayerScope(user: UserContext, taxpayerId: string): void {
  if (!isNationalScope(user) && user.taxpayerId !== taxpayerId) {
    throw new AccessDeniedError("The requested record is outside your authorised taxpayer scope.");
  }
}
