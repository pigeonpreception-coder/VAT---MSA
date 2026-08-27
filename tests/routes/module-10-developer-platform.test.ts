import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 10 Phase D (the playbook's final phase): the Developer platform —
 * DeveloperAccount/APIClient/CredentialRef/TestRun via CreateClient/
 * RotateCredential/RevokeCredential/RunConformance. `api_clients` already
 * existed (seed-only, `PENDING_CREDENTIAL_PROVISIONING`) with zero write
 * paths anywhere in this codebase, confirmed via a full-repo grep before
 * this phase; `developer_accounts`/`credential_refs`/`test_runs` did not
 * exist at all. CreateClient get-or-creates a DeveloperAccount (no separate
 * verb is named for it) and issues a real client_key immediately, but
 * status stays honestly `PENDING_CREDENTIAL_PROVISIONING` — no external
 * secret manager is integrated in this environment to mint a live
 * credential, matching this column's own pre-existing seeded value.
 * RunConformance's harness genuinely passes today (unlike Module 10 Phase
 * C's PRODUCTION path or Phase B's ITAS calls), since it checks this
 * platform's own internal credential_refs bookkeeping rather than a live
 * external secret — with one deliberately non-blocking, honestly
 * NOT_CONFIGURED check for the missing secret manager itself.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const OWNER_A: FixtureUser = { userId: "usr-dev-owner-a", externalUserId: "ext-dev-owner-a", email: "owner-a@dev-test.test" };
const OWNER_B: FixtureUser = { userId: "usr-dev-owner-b", externalUserId: "ext-dev-owner-b", email: "owner-b@dev-test.test" };
const PLATFORM_ADMIN: FixtureUser = { userId: "usr-dev-platform", externalUserId: "ext-dev-platform", email: "platform@dev-test.test" };
const AUDITOR: FixtureUser = { userId: "usr-dev-auditor", externalUserId: "ext-dev-auditor", email: "auditor@dev-test.test" };

function actingAs(user: FixtureUser): void {
  __setRequestHeaders({ "oai-authenticated-user-id": user.externalUserId, "oai-authenticated-user-email": user.email });
}

function jsonRequest(url: string, method: string, body: unknown, idempotencyKey = crypto.randomUUID()): Request {
  return new Request(url, { method, headers: { "content-type": "application/json", "idempotency-key": idempotencyKey }, body: JSON.stringify(body) });
}

async function seedFixture(): Promise<void> {
  const db = env.DB;
  const now = "2026-08-01T00:00:00.000Z";
  await db.batch([
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-dev-a", "VAT-DEV-A", "TIN-DEV-A", "Developer Org A (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Dev Street A", "finance@dev-a-test.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-dev-a", "tp-dev-a", "Developer Org A (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-dev-b", "VAT-DEV-B", "TIN-DEV-B", "Developer Org B (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Dev Street B", "finance@dev-b-test.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-dev-b", "tp-dev-b", "Developer Org B (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-dev-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OWNER_A.userId, OWNER_A.externalUserId, OWNER_A.email, "Owner A", "TAXPAYER_OWNER", "tp-dev-a", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OWNER_B.userId, OWNER_B.externalUserId, OWNER_B.email, "Owner B", "TAXPAYER_OWNER", "tp-dev-b", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(PLATFORM_ADMIN.userId, PLATFORM_ADMIN.externalUserId, PLATFORM_ADMIN.email, "Platform Admin", "PILOT_ADMIN", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(AUDITOR.userId, AUDITOR.externalUserId, AUDITOR.email, "Auditor", "NAMRA_AUDITOR", null, "ACTIVE", now),
    ...[OWNER_A, OWNER_B, PLATFORM_ADMIN, AUDITOR].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-dev-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
  ]);
}

const baseCreation = { schema_version: "1.0.0", name: "Order Sync Client", scopes: ["invoices.read", "invoices.write"], rate_limit_profile: "PILOT_STANDARD" };

async function createClientRoute(actor: FixtureUser, body: unknown, idempotencyKey?: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/developer/clients/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/developer/clients", "POST", body, idempotencyKey));
}

async function rotateRoute(actor: FixtureUser, clientId: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/developer/clients/[id]/rotation/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/developer/clients/${clientId}/rotation`, "POST", {}), { params: Promise.resolve({ id: clientId }) });
}

async function revokeRoute(actor: FixtureUser, clientId: string, body: unknown): Promise<Response> {
  const { POST } = await import("@/app/api/v1/developer/clients/[id]/revocation/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/developer/clients/${clientId}/revocation`, "POST", body), { params: Promise.resolve({ id: clientId }) });
}

async function conformanceRoute(actor: FixtureUser, clientId: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/developer/clients/[id]/conformance-runs/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/developer/clients/${clientId}/conformance-runs`, "POST", {}), { params: Promise.resolve({ id: clientId }) });
}

describe("Module 10 Developer platform: CreateClient/RotateCredential/RevokeCredential/RunConformance (Phase D)", () => {
  let clientId: string;

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

  it("denies CreateClient to a role without developer:manage", async () => {
    const response = await createClientRoute(AUDITOR, baseCreation);
    expect(response.status).toBe(403);
  });

  it("rejects CreateClient with a validation error for a malformed scope", async () => {
    const response = await createClientRoute(OWNER_A, { ...baseCreation, scopes: ["not-a-valid-scope"] });
    expect(response.status).toBe(422);
  });

  it("refuses CreateClient for an actor with no organisation (national/platform scope)", async () => {
    const response = await createClientRoute(PLATFORM_ADMIN, baseCreation);
    expect(response.status).toBe(403);
  });

  it("CreateClient issues a client_key immediately but honestly stays PENDING_CREDENTIAL_PROVISIONING — no external secret manager exists in this environment", async () => {
    const response = await createClientRoute(OWNER_A, baseCreation);
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.resource.status).toBe("PENDING_CREDENTIAL_PROVISIONING");
    expect(body.resource.client_key).toBeTruthy();
    expect(body.resource.developer_account_id).toBeTruthy();
    clientId = body.resource.id;

    const credential = await env.DB.prepare("SELECT status FROM credential_refs WHERE api_client_id=?").bind(clientId).first<{ status: string }>();
    expect(credential?.status).toBe("ACTIVE");
  });

  it("CreateClient is idempotent under a repeated idempotency key", async () => {
    const key = crypto.randomUUID();
    const first = await createClientRoute(OWNER_A, { ...baseCreation, name: "Idempotent Client" }, key);
    const second = await createClientRoute(OWNER_A, { ...baseCreation, name: "Idempotent Client" }, key);
    expect(first.status).toBe(201);
    expect(second.status).toBe(201);
    expect((await first.json()).resource.id).toBe((await second.json()).resource.id);
  });

  it("refuses an actor from a different organisation acting on this client", async () => {
    expect((await rotateRoute(OWNER_B, clientId)).status).toBe(403);
    expect((await revokeRoute(OWNER_B, clientId, { schema_version: "1.0.0", reason: "Not my client." })).status).toBe(403);
    expect((await conformanceRoute(OWNER_B, clientId)).status).toBe(403);
  });

  it("RunConformance genuinely PASSES for a freshly created client — the harness checks this platform's own internal bookkeeping, which is real today", async () => {
    const response = await conformanceRoute(OWNER_A, clientId);
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.resource.outcome).toBe("PASSED");
    const checks = JSON.parse(body.resource.checks);
    expect(checks.find((c: { code: string }) => c.code === "CREDENTIAL_ISSUED").status).toBe("PASS");
    expect(checks.find((c: { code: string }) => c.code === "EXTERNAL_CREDENTIAL_PROVISIONED").status).toBe("NOT_CONFIGURED");
  });

  it("RotateCredential marks the old credential ROTATED and issues a fresh ACTIVE one", async () => {
    const before = await env.DB.prepare("SELECT credential_reference FROM api_clients WHERE id=?").bind(clientId).first<{ credential_reference: string }>();
    const response = await rotateRoute(OWNER_A, clientId);
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.resource.credential_reference).not.toBe(before?.credential_reference);
    expect(body.resource.last_rotated_at).toBeTruthy();

    const rows = await env.DB.prepare("SELECT status FROM credential_refs WHERE api_client_id=? ORDER BY issued_at").bind(clientId).all<{ status: string }>();
    expect(rows.results.map((r) => r.status)).toEqual(["ROTATED", "ACTIVE"]);
  });

  it("RunConformance still PASSES after rotation, now evaluating the new credential row", async () => {
    const response = await conformanceRoute(OWNER_A, clientId);
    expect(response.status).toBe(201);
    expect((await response.json()).resource.outcome).toBe("PASSED");
  });

  it("RevokeCredential requires a reason", async () => {
    const response = await revokeRoute(OWNER_A, clientId, { schema_version: "1.0.0", reason: "" });
    expect(response.status).toBe(422);
  });

  it("RevokeCredential is terminal: revokes the client, and RunConformance now genuinely FAILS", async () => {
    const response = await revokeRoute(OWNER_A, clientId, { schema_version: "1.0.0", reason: "Client decommissioned by the integrating team." });
    expect(response.status).toBe(200);
    expect((await response.json()).resource.status).toBe("REVOKED");

    const conformance = await conformanceRoute(OWNER_A, clientId);
    expect(conformance.status).toBe(201);
    const conformanceBody = await conformance.json();
    expect(conformanceBody.resource.outcome).toBe("FAILED");
    const checks = JSON.parse(conformanceBody.resource.checks);
    expect(checks.find((c: { code: string }) => c.code === "CLIENT_OPERATIONAL").status).toBe("FAIL");
    expect(checks.find((c: { code: string }) => c.code === "CREDENTIAL_ISSUED").status).toBe("FAIL");
  });

  it("refuses a second revocation and refuses rotating a revoked client", async () => {
    expect((await revokeRoute(OWNER_A, clientId, { schema_version: "1.0.0", reason: "Trying again." })).status).toBe(409);
    expect((await rotateRoute(OWNER_A, clientId)).status).toBe(409);
  });

  it("a national-scope actor may act on any organisation's client (oversight)", async () => {
    const created = await createClientRoute(OWNER_B, { ...baseCreation, name: "Org B Client" });
    expect(created.status).toBe(201);
    const orgBClientId = (await created.json()).resource.id;
    const response = await conformanceRoute(PLATFORM_ADMIN, orgBClientId);
    expect(response.status).toBe(201);
    expect((await response.json()).resource.outcome).toBe("PASSED");
  });
});
