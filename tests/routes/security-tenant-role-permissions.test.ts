import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { quarterlyAccessReviewWindow } from "@/lib/domain/control-plane";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Security remediation (2026-08-27), item #5 of SECURITY_GAP_ASSESSMENT.md's
 * prioritised list: `createOrganisationRole` (`POST /api/v1/organisations/roles`)
 * only ever checked a permission code against `PROTECTED_PERMISSION_PREFIXES`
 * (`lib/domain/control-plane.ts`), a denylist that in practice only blocked
 * the `platform:` prefix — a tenant admin could define a custom
 * organisation role holding `audit:read`, `security:read`,
 * `reconciliation:manage`, `refunds:review`, etc., none of which any
 * tenant-facing role has ever legitimately held. Replaced with
 * `TENANT_GRANTABLE_PERMISSIONS` (`lib/domain/access.ts`) — an allowlist
 * derived directly from the union of every real tenant role's own
 * permissions, so a tenant-defined role can never exceed what an existing
 * built-in tenant role already has. Proven through the real route handler
 * (`app/api/v1/organisations/roles/route.ts`, dispatched via
 * `lib/data/control-plane-repository.ts`'s `createOrganisationRole`). See
 * tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const OWNER: FixtureUser = { userId: "usr-role-owner", externalUserId: "ext-role-owner", email: "owner@role-test.test" };

async function actingAs(user: FixtureUser): Promise<void> {
  __setRequestHeaders({ "oai-authenticated-user-id": user.externalUserId, "oai-authenticated-user-email": user.email });
  await env.DB.prepare("INSERT INTO step_up_events (id,user_id,method,verified_at,expires_at) VALUES (?,?,?,?,?)")
    .bind(crypto.randomUUID(), user.userId, "TOTP", new Date().toISOString(), new Date(Date.now() + 5 * 60_000).toISOString()).run();
}

function jsonRequest(url: string, body: unknown): Request {
  return new Request(url, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(body) });
}

async function seedFixture(): Promise<void> {
  const db = env.DB;
  const now = "2026-08-01T00:00:00.000Z";
  const review = quarterlyAccessReviewWindow();
  await db.batch([
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-role", "VAT-ROLE-001", "TIN-ROLE-001", "Role Test Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Role Street", "finance@role-test.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-role", "tp-role", "Role Test Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-role-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OWNER.userId, OWNER.externalUserId, OWNER.email, "Role Test Owner", "TAXPAYER_OWNER", "tp-role", "ACTIVE", now),
    db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
      VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${OWNER.userId}`, OWNER.userId, "idp-role-workspace", OWNER.externalUserId, OWNER.email, "PILOT", "ACTIVE", now, now),
    // createOrganisationRole is an ADMIN_WRITE control-plane operation: it requires the organisation to hold an
    // enabled ADMINISTRATION entitlement *and* a current-quarter access review on record (assertEntitledOperation).
    db.prepare(`INSERT INTO license_plans (id,code,name,version,status,effective_from,effective_to,created_at)
      VALUES (?,?,?,?,?,?,NULL,?)`).bind("plan-role-test", "ROLE_TEST_PLAN", "Role Test Plan", 1, "ACTIVE", now, now),
    db.prepare(`INSERT INTO license_features VALUES ('ADMINISTRATION','Organisation administration','Employees roles access governance and security posture','USER_SEATS',1,?)`).bind(now),
    db.prepare(`INSERT INTO license_plan_entitlements VALUES ('ent-role-admin','plan-role-test','ADMINISTRATION',1,NULL,'{}')`),
    db.prepare(`INSERT INTO subscriptions (id,organisation_id,provider,provider_reference,status,activated_at,current_period_start,current_period_end,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("sub-role-a", "org-role", "LOCAL_SYNTHETIC", "synthetic-subscription-role-a", "ACTIVE", now, "2026-08-01", "2026-10-31", now, now),
    db.prepare(`INSERT INTO organisation_licenses (id,organisation_id,subscription_id,license_plan_id,state,state_version,effective_from,effective_to,grace_ends_at,retention_policy,updated_at)
      VALUES (?,?,?,?,?,?,?,NULL,NULL,?,?)`).bind("olic-role-a", "org-role", "sub-role-a", "plan-role-test", "ACTIVE", 1, now, "NON_DESTRUCTIVE_TAX_RETENTION", now),
    db.prepare(`INSERT INTO access_reviews VALUES (?,?,?,?,?,?,?,?,?,?)`)
      .bind("areview-role-a", "org-role", "Role test access review", "QUARTERLY", "COMPLETED", review.periodStart, review.dueAt, OWNER.userId, now, now),
    // createOrganisationRole also cross-checks every requested permission against the access_permissions
    // catalogue — real rows, not just domain-layer validation.
    db.prepare(`INSERT INTO access_permissions VALUES ('invoices:read','INVOICE','READ','Read authorised invoices','RESTRICTED',?)`).bind(now),
    db.prepare(`INSERT INTO access_permissions VALUES ('documents:read','DOCUMENT','READ','Read authorised document metadata','CONFIDENTIAL',?)`).bind(now),
  ]);
}

async function createRoleRoute(name: string, permissions: string[]): Promise<Response> {
  const { POST } = await import("@/app/api/v1/organisations/roles/route");
  return POST(jsonRequest(`https://vat-msa.local/api/v1/organisations/roles?organisation_id=org-role`, { name, permissions }));
}

describe("Security fix: tenant-defined organisation roles cannot hold national/platform-only permissions", () => {
  beforeAll(async () => {
    vi.stubEnv("NODE_ENV", "production");
    env.DB = createFakeD1();
    const { ensureDatabase } = await import("@/db/runtime");
    await ensureDatabase();
    await seedFixture();
  });

  afterAll(() => {
    vi.unstubAllEnvs();
  });

  it("creates a role holding only genuinely tenant-grantable permissions", async () => {
    await actingAs(OWNER);
    const response = await createRoleRoute("Invoice Reviewer", ["invoices:read", "documents:read"]);
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.role.permissions).toEqual(["invoices:read", "documents:read"]);
  });

  it("refuses audit:read — held only by national/platform roles, never a tenant role", async () => {
    await actingAs(OWNER);
    const response = await createRoleRoute("Sneaky Auditor Role", ["invoices:read", "audit:read"]);
    expect(response.status).toBe(422);
    const body = await response.json();
    expect(body.detail).toContain("audit:read");
  });

  it("refuses security:read and reconciliation:manage — the exact gap the 2026-08-27 audit found", async () => {
    await actingAs(OWNER);
    const first = await createRoleRoute("Sneaky Security Role", ["security:read"]);
    expect(first.status).toBe(422);
    const second = await createRoleRoute("Sneaky Reconciliation Role", ["reconciliation:manage"]);
    expect(second.status).toBe(422);
  });

  it("still refuses platform:manage (the one prefix the old denylist did catch)", async () => {
    await actingAs(OWNER);
    const response = await createRoleRoute("Sneaky Platform Role", ["platform:manage"]);
    expect(response.status).toBe(422);
  });
});
