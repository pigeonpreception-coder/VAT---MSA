import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Security remediation (2026-08-27), item #1 of SECURITY_GAP_ASSESSMENT.md's
 * prioritised list: linkIdentity/revokeIdentityLink previously performed NO
 * scope check on the *target* user at all. `administration:manage` is a
 * tenant-grantable permission (TAXPAYER_OWNER/TAXPAYER_ADMIN hold it for
 * ordinary organisation administration), so any tenant admin could link a
 * platform identity subject they control to any app_users row — including
 * a national-scope account — and authenticate as it on the very next
 * request; the same gap let a tenant admin revoke any other user's session
 * platform-wide. This suite proves the fix: a non-national actor may only
 * link/revoke identities for users within their own taxpayer's
 * organisation, while a genuinely national-scope actor (the intended real
 * identity administrator) remains unrestricted. Proven through the real
 * route handlers (app/api/v1/identity/links/...) and
 * lib/data/identity-repository.ts. See
 * tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const OWNER_A: FixtureUser = { userId: "usr-sec-owner-a", externalUserId: "ext-sec-owner-a", email: "owner-a@sec-test.test" };
const COLLEAGUE_A: FixtureUser = { userId: "usr-sec-colleague-a", externalUserId: "ext-sec-colleague-a", email: "colleague-a@sec-test.test" };
const OWNER_B: FixtureUser = { userId: "usr-sec-owner-b", externalUserId: "ext-sec-owner-b", email: "owner-b@sec-test.test" };
const NATIONAL_ADMIN: FixtureUser = { userId: "usr-sec-national", externalUserId: "ext-sec-national", email: "national@sec-test.test" };
// The identity subject an attacking tenant admin controls (e.g. a second ChatGPT/OpenAI account they own).
const ATTACKER_SUBJECT = "ext-sec-attacker-controlled-subject";

/** Also grants a fresh, server-verified step-up (step_up_events row) for the acting user — every command this file exercises is step-up gated, and there is no longer a header shortcut around lib/security/step-up.ts's real requireStepUp check. */
async function actingAs(user: FixtureUser): Promise<void> {
  __setRequestHeaders({ "oai-authenticated-user-id": user.externalUserId, "oai-authenticated-user-email": user.email });
  const now = new Date();
  await env.DB.prepare("INSERT INTO step_up_events (id,user_id,method,verified_at,expires_at) VALUES (?,?,?,?,?)")
    .bind(crypto.randomUUID(), user.userId, "TOTP", now.toISOString(), new Date(now.getTime() + 5 * 60_000).toISOString()).run();
}

function jsonRequest(url: string, body: unknown): Request {
  return new Request(url, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(body) });
}

async function linkIdentityRoute(targetUserId: string, subject: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/identity/links/route");
  return POST(jsonRequest("https://vat-msa.local/api/v1/identity/links", { user_id: targetUserId, provider_key: "SITES_WORKSPACE", subject }));
}

async function revokeIdentityLinkRoute(identityLinkId: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/identity/links/[id]/revocation/route");
  return POST(new Request(`https://vat-msa.local/api/v1/identity/links/${identityLinkId}/revocation`, { method: "POST" }), { params: Promise.resolve({ id: identityLinkId }) });
}

async function seedFixture(): Promise<void> {
  const db = env.DB;
  const now = "2026-08-01T00:00:00.000Z";
  await db.batch([
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-sec-a", "VAT-SEC-A01", "TIN-SEC-A01", "Security Test A (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Security Street", "finance@sec-a.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-sec-a", "tp-sec-a", "Security Test A (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-sec-b", "VAT-SEC-B01", "TIN-SEC-B01", "Security Test B (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "2 Security Street", "finance@sec-b.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-sec-b", "tp-sec-b", "Security Test B (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-sec-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OWNER_A.userId, OWNER_A.externalUserId, OWNER_A.email, "Owner A", "TAXPAYER_OWNER", "tp-sec-a", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(COLLEAGUE_A.userId, COLLEAGUE_A.externalUserId, COLLEAGUE_A.email, "Colleague A", "TAXPAYER_ACCOUNTANT", "tp-sec-a", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OWNER_B.userId, OWNER_B.externalUserId, OWNER_B.email, "Owner B", "TAXPAYER_OWNER", "tp-sec-b", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NATIONAL_ADMIN.userId, NATIONAL_ADMIN.externalUserId, NATIONAL_ADMIN.email, "National Admin", "NAMRA_SYSTEM_ADMIN", null, "ACTIVE", now),
    ...[OWNER_A, COLLEAGUE_A, OWNER_B, NATIONAL_ADMIN].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-sec-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
    // A second, already-established identity link for OWNER_B — the target of the illegitimate cross-tenant revocation attempt.
    db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
      VALUES (?,?,?,?,?,?,?,?,?)`).bind("ilink-owner-b-second", OWNER_B.userId, "idp-sec-workspace", "ext-owner-b-second-device", OWNER_B.email, "PILOT", "ACTIVE", now, now),
  ]);
}

describe("Security fix: linkIdentity/revokeIdentityLink tenant-scope enforcement", () => {
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

  it("denies a tenant admin linking an identity to a user in a different taxpayer (the account-takeover path)", async () => {
    await actingAs(OWNER_A);
    const response = await linkIdentityRoute(OWNER_B.userId, ATTACKER_SUBJECT);
    expect(response.status).toBe(403);
    // Confirm no link was actually created for the attacker-controlled subject.
    const created = await env.DB.prepare("SELECT id FROM identity_links WHERE subject=?").bind(ATTACKER_SUBJECT).first<{ id: string }>();
    expect(created).toBeNull();
  });

  it("denies a tenant admin linking an identity to a national-scope account", async () => {
    await actingAs(OWNER_A);
    const response = await linkIdentityRoute(NATIONAL_ADMIN.userId, ATTACKER_SUBJECT);
    expect(response.status).toBe(403);
  });

  it("allows a tenant admin linking an identity to a colleague within their own taxpayer", async () => {
    await actingAs(OWNER_A);
    const response = await linkIdentityRoute(COLLEAGUE_A.userId, "ext-colleague-a-second-device");
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.link.subject).toBe("ext-colleague-a-second-device");
  });

  it("denies a tenant admin revoking another taxpayer's identity link", async () => {
    await actingAs(OWNER_A);
    const response = await revokeIdentityLinkRoute("ilink-owner-b-second");
    expect(response.status).toBe(403);
    const link = await env.DB.prepare("SELECT status FROM identity_links WHERE id=?").bind("ilink-owner-b-second").first<{ status: string }>();
    expect(link?.status).toBe("ACTIVE");
  });

  it("allows a tenant admin revoking a colleague's identity link within their own taxpayer", async () => {
    await actingAs(OWNER_A);
    const response = await revokeIdentityLinkRoute(`ilink-${COLLEAGUE_A.userId}`);
    expect(response.status).toBe(200);
    const link = await env.DB.prepare("SELECT status FROM identity_links WHERE id=?").bind(`ilink-${COLLEAGUE_A.userId}`).first<{ status: string }>();
    expect(link?.status).toBe("REVOKED");
  });

  it("leaves a genuinely national-scope actor unrestricted across taxpayers", async () => {
    await actingAs(NATIONAL_ADMIN);
    const linkResponse = await linkIdentityRoute(OWNER_B.userId, "ext-owner-b-national-linked-device");
    expect(linkResponse.status).toBe(201);
    const revokeResponse = await revokeIdentityLinkRoute("ilink-owner-b-second");
    expect(revokeResponse.status).toBe(200);
  });
});
