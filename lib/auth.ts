import { getChatGPTUser } from "@/app/chatgpt-auth";
import { ensureDatabase } from "@/db/runtime";
import type { UserContext } from "./domain/types";

// Pure RBAC/ABAC policy (AccessDeniedError, permission resolution, scope
// checks, the GetUserAccess query) lives in lib/domain/access.ts so it stays
// unit-testable without pulling in app/chatgpt-auth.ts's next/headers
// dependency. Re-exported here so existing `@/lib/auth` imports are
// unaffected.
export {
  AccessDeniedError,
  resolveEffectivePermissions,
  hasPermission,
  requirePermission,
  isNationalScope,
  requireTaxpayerScope,
  getUserAccess,
  type EffectiveAccess,
} from "./domain/access";
import { AccessDeniedError } from "./domain/access";

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

