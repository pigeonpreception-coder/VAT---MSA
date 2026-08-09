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
  PILOT_ADMIN: new Set(["dashboard:read", "identity:read", "taxpayers:read", "registrations:read", "registrations:submit", "organisations:manage", "invoices:read", "invoices:submit", "exceptions:read", "returns:read", "audit:read", "security:read"]),
  TAXPAYER_OWNER: new Set(["dashboard:read", "identity:read", "taxpayers:read", "registrations:read", "registrations:submit", "organisations:manage", "invoices:read", "invoices:submit", "exceptions:read", "returns:read"]),
  TAXPAYER_ADMIN: new Set(["dashboard:read", "identity:read", "taxpayers:read", "registrations:read", "organisations:manage", "invoices:read", "invoices:submit", "exceptions:read", "returns:read"]),
  TAXPAYER_ACCOUNTANT: new Set(["dashboard:read", "identity:read", "taxpayers:read", "invoices:read", "invoices:submit", "exceptions:read", "returns:read"]),
  TAXPAYER_STAFF: new Set(["dashboard:read", "identity:read", "invoices:read", "invoices:submit", "exceptions:read"]),
  TAXPAYER_VIEWER: new Set(["dashboard:read", "identity:read", "invoices:read", "returns:read"]),
  SELLER_ADMIN: new Set(["dashboard:read", "identity:read", "invoices:read", "invoices:submit", "exceptions:read", "returns:read"]),
  SELLER_OPERATOR: new Set(["dashboard:read", "identity:read", "invoices:read", "invoices:submit", "exceptions:read"]),
  SELLER_VIEWER: new Set(["dashboard:read", "identity:read", "invoices:read", "returns:read"]),
  BUYER_ADMIN: new Set(["dashboard:read", "identity:read", "invoices:read", "exceptions:read", "returns:read"]),
  BUYER_USER: new Set(["dashboard:read", "identity:read", "invoices:read", "exceptions:read"]),
  NAMRA_COMPLIANCE_OFFICER: new Set(["dashboard:read", "identity:read", "taxpayers:read", "registrations:read", "invoices:read", "exceptions:read", "returns:read"]),
  NAMRA_AUDITOR: new Set(["dashboard:read", "identity:read", "taxpayers:read", "registrations:read", "invoices:read", "exceptions:read", "returns:read", "audit:read"]),
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
  return user.taxpayerId === null && ["PILOT_ADMIN", "NAMRA_COMPLIANCE_OFFICER", "NAMRA_AUDITOR", "INTERNAL_AUDITOR", "SECURITY_ANALYST"].includes(user.role);
}

export function requireTaxpayerScope(user: UserContext, taxpayerId: string): void {
  if (!isNationalScope(user) && user.taxpayerId !== taxpayerId) {
    throw new AccessDeniedError("The requested record is outside your authorised taxpayer scope.");
  }
}
