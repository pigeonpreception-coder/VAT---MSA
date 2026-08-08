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
      .prepare("SELECT * FROM app_users WHERE status = 'ACTIVE' AND (external_user_id = ? OR email = ?) LIMIT 1")
      .bind(chatGptUser.userId, chatGptUser.email.toLowerCase())
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

  const row = await db.prepare("SELECT * FROM app_users WHERE external_user_id = 'local-demo-user' LIMIT 1").first<UserRow>();
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
  PILOT_ADMIN: new Set(["dashboard:read", "taxpayers:read", "invoices:read", "invoices:submit", "exceptions:read", "returns:read", "audit:read"]),
  SELLER_ADMIN: new Set(["dashboard:read", "invoices:read", "invoices:submit", "exceptions:read", "returns:read"]),
  SELLER_OPERATOR: new Set(["dashboard:read", "invoices:read", "invoices:submit", "exceptions:read"]),
  SELLER_VIEWER: new Set(["dashboard:read", "invoices:read", "returns:read"]),
  BUYER_ADMIN: new Set(["dashboard:read", "invoices:read", "exceptions:read", "returns:read"]),
  BUYER_USER: new Set(["dashboard:read", "invoices:read", "exceptions:read"]),
  NAMRA_COMPLIANCE_OFFICER: new Set(["dashboard:read", "taxpayers:read", "invoices:read", "exceptions:read", "returns:read"]),
  NAMRA_AUDITOR: new Set(["dashboard:read", "taxpayers:read", "invoices:read", "exceptions:read", "returns:read", "audit:read"]),
  INTERNAL_AUDITOR: new Set(["dashboard:read", "audit:read"]),
};

export function requirePermission(user: UserContext, permission: string): void {
  if (!ROLE_PERMISSIONS[user.role]?.has(permission)) {
    throw new AccessDeniedError(`Role ${user.role} does not have ${permission} permission.`);
  }
}

