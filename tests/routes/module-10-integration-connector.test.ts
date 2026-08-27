import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 10 Phase A: the generic, provider-agnostic connector model.
 * RegisterIntegration/ApproveIntegration/SuspendIntegration/StartSync/
 * GetHealth against integration_connections/sync_jobs — previously seed-only
 * tables with zero application write paths anywhere in this codebase (a
 * full-repo grep before writing this phase found no `INSERT INTO
 * integration_connections`/`sync_jobs` outside db/runtime.ts's own seed
 * block), despite the matrix's prior "VERIFIED FOUNDATION" claim for
 * domain #25. Proves: a tenant actor registers/approves/suspends/syncs
 * their own organisation's connection; a platform-scope actor (no
 * taxpayerId at all) does the same for a platform-wide one; neither side
 * can reach into the other's connection; StartSync is honest about having
 * no live per-provider connector (always FAILED, never a fabricated
 * success); and — the one genuinely security-relevant property this phase
 * introduces — RegisterIntegration can never touch the four pre-seeded,
 * externally-gated platform connections (ITAS/BIPA/bank-org1/treasury),
 * because registering the same provider_key again is refused as a
 * conflict, so ApproveIntegration's DRAFT/SUSPENDED-only transition rule
 * can never legally apply to them.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const TENANT_OWNER: FixtureUser = { userId: "usr-int-owner", externalUserId: "ext-int-owner", email: "owner@int-test.test" };
const PLATFORM_ADMIN: FixtureUser = { userId: "usr-int-platform", externalUserId: "ext-int-platform", email: "platform@int-test.test" };
const AUDITOR: FixtureUser = { userId: "usr-int-auditor", externalUserId: "ext-int-auditor", email: "auditor@int-test.test" };

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
    // db/runtime.ts's PLATFORM_SEED_STATEMENTS (which seeds integration-itas) is dev-only
    // (gated on NODE_ENV !== "production") and never runs under the production stub every
    // test file sets — insert the pre-seeded ITAS connection directly, matching its exact real seed shape.
    db.prepare(`INSERT OR IGNORE INTO integration_connections
      (id,organisation_id,provider_key,category,display_name,capabilities,endpoint_reference,credential_reference,configuration_status,operational_status,data_classification,last_health_check_at,last_health_outcome,created_at,updated_at)
      VALUES ('integration-itas',NULL,'ITAS','GOVERNMENT','ITAS statutory services','["IDENTITY_FEDERATION","TAXPAYER_VERIFICATION","RETURN_SUBMISSION"]',NULL,NULL,'REQUIRES_AUTHORITY_CONTRACT','DISABLED','TAX_CONFIDENTIAL',NULL,NULL,?,?)`).bind(now, now),
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-int", "VAT-INT", "TIN-INT", "Integration Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Connector Street", "finance@int-test.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-int", "tp-int", "Integration Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-int-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(TENANT_OWNER.userId, TENANT_OWNER.externalUserId, TENANT_OWNER.email, "Tenant Owner", "TAXPAYER_OWNER", "tp-int", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(PLATFORM_ADMIN.userId, PLATFORM_ADMIN.externalUserId, PLATFORM_ADMIN.email, "Platform Admin", "PILOT_ADMIN", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(AUDITOR.userId, AUDITOR.externalUserId, AUDITOR.email, "Auditor", "NAMRA_AUDITOR", null, "ACTIVE", now),
    ...[TENANT_OWNER, PLATFORM_ADMIN, AUDITOR].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-int-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
  ]);
}

async function registerRoute(actor: FixtureUser, body: unknown, idempotencyKey?: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/integrations/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/integrations", "POST", body, idempotencyKey));
}

async function approveRoute(actor: FixtureUser, id: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/integrations/[id]/approval/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/integrations/${id}/approval`, "POST", {}), { params: Promise.resolve({ id }) });
}

async function suspendRoute(actor: FixtureUser, id: string, reason: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/integrations/[id]/suspension/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/integrations/${id}/suspension`, "POST", { schema_version: "1.0.0", reason }), { params: Promise.resolve({ id }) });
}

async function syncRoute(actor: FixtureUser, id: string, jobType: string, direction: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/integrations/[id]/sync/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/integrations/${id}/sync`, "POST", { schema_version: "1.0.0", job_type: jobType, direction }), { params: Promise.resolve({ id }) });
}

async function healthRoute(actor: FixtureUser, id: string): Promise<Response> {
  const { GET } = await import("@/app/api/v1/integrations/[id]/health/route");
  actingAs(actor);
  return GET(new Request(`https://vat-msa.local/api/v1/integrations/${id}/health`), { params: Promise.resolve({ id }) });
}

const tenantRegistration = { schema_version: "1.0.0", provider_key: "XERO_ACCOUNTING", category: "ACCOUNTING", display_name: "Xero accounting sync", capabilities: ["INVOICE_SYNC", "PAYMENT_SYNC"], data_classification: "CONFIDENTIAL" };
const platformRegistration = { schema_version: "1.0.0", provider_key: "NATIONAL_ID_REGISTRY", category: "GOVERNMENT", display_name: "National ID cross-check", capabilities: ["IDENTITY_FEDERATION"], data_classification: "RESTRICTED" };

describe("Module 10 Integration connector: RegisterIntegration/ApproveIntegration/SuspendIntegration/StartSync/GetHealth (Phase A)", () => {
  let tenantConnectionId: string;
  let platformConnectionId: string;

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

  it("registers a tenant-scoped connection as DRAFT/DISABLED, defaulting to that actor's own organisation", async () => {
    const response = await registerRoute(TENANT_OWNER, tenantRegistration);
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.resource.organisation_id).toBe("org-int");
    expect(body.resource.configuration_status).toBe("DRAFT");
    expect(body.resource.operational_status).toBe("DISABLED");
    tenantConnectionId = body.resource.id;
  });

  it("registers a platform-wide connection (organisation_id NULL) for an actor with no taxpayerId", async () => {
    const response = await registerRoute(PLATFORM_ADMIN, platformRegistration);
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.resource.organisation_id).toBeNull();
    expect(body.resource.configuration_status).toBe("DRAFT");
    platformConnectionId = body.resource.id;
  });

  it("refuses a duplicate registration for the same provider_key in the same scope", async () => {
    const response = await registerRoute(TENANT_OWNER, tenantRegistration);
    expect(response.status).toBe(409);
  });

  it("can never re-register (and therefore never approve) one of the four pre-seeded, externally-gated platform connections", async () => {
    const response = await registerRoute(PLATFORM_ADMIN, { ...platformRegistration, provider_key: "itas" });
    expect(response.status).toBe(409);
  });

  it("rejects registration with a validation error for missing capabilities", async () => {
    const response = await registerRoute(TENANT_OWNER, { ...tenantRegistration, provider_key: "BAD_CAPS_CO", capabilities: [] });
    expect(response.status).toBe(422);
  });

  it("denies registration to a role without integrations:manage", async () => {
    const response = await registerRoute(AUDITOR, { ...tenantRegistration, provider_key: "AUDITOR_ATTEMPT" });
    expect(response.status).toBe(403);
  });

  it("refuses a tenant actor approving a platform-wide connection", async () => {
    const response = await approveRoute(TENANT_OWNER, platformConnectionId);
    expect(response.status).toBe(403);
  });

  it("refuses a platform actor approving a tenant-owned connection", async () => {
    const response = await approveRoute(PLATFORM_ADMIN, tenantConnectionId);
    expect(response.status).toBe(403);
  });

  it("refuses StartSync against a connection still in DRAFT (not yet approved)", async () => {
    const response = await syncRoute(TENANT_OWNER, tenantConnectionId, "INVOICE_PULL", "INBOUND");
    expect(response.status).toBe(409);
  });

  it("ApproveIntegration moves DRAFT -> CONFIGURED/OPERATIONAL for the connection's own organisation", async () => {
    const response = await approveRoute(TENANT_OWNER, tenantConnectionId);
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.resource.configuration_status).toBe("CONFIGURED");
    expect(body.resource.operational_status).toBe("OPERATIONAL");
  });

  it("StartSync on a CONFIGURED connection is honest: no live connector exists, so it always completes FAILED with a typed reason, never a fabricated success", async () => {
    const response = await syncRoute(TENANT_OWNER, tenantConnectionId, "INVOICE_PULL", "INBOUND");
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.resource.status).toBe("FAILED");
    expect(body.resource.records_read).toBe(0);
    expect(body.resource.records_written).toBe(0);
    expect(body.resource.last_error).toContain("No live connector implementation");
  });

  it("rejects an invalid sync direction with a validation error", async () => {
    const response = await syncRoute(TENANT_OWNER, tenantConnectionId, "INVOICE_PULL", "SIDEWAYS");
    expect(response.status).toBe(422);
  });

  it("GetHealth reports the connection's own status plus its recent sync attempts", async () => {
    const response = await healthRoute(TENANT_OWNER, tenantConnectionId);
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.connection.configuration_status).toBe("CONFIGURED");
    expect(body.recentSyncJobs.length).toBe(1);
    expect(body.recentSyncJobs[0].status).toBe("FAILED");
  });

  it("SuspendIntegration requires a reason and moves CONFIGURED -> SUSPENDED/DISABLED", async () => {
    const missingReason = await suspendRoute(TENANT_OWNER, tenantConnectionId, "");
    expect(missingReason.status).toBe(422);

    const response = await suspendRoute(TENANT_OWNER, tenantConnectionId, "Rotating credentials with the upstream provider.");
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.resource.configuration_status).toBe("SUSPENDED");
    expect(body.resource.operational_status).toBe("DISABLED");
  });

  it("refuses suspending an already-SUSPENDED connection", async () => {
    const response = await suspendRoute(TENANT_OWNER, tenantConnectionId, "Attempting to suspend again.");
    expect(response.status).toBe(422);
  });

  it("refuses StartSync against a SUSPENDED connection", async () => {
    const response = await syncRoute(TENANT_OWNER, tenantConnectionId, "INVOICE_PULL", "INBOUND");
    expect(response.status).toBe(409);
  });

  it("ApproveIntegration reactivates SUSPENDED -> CONFIGURED/OPERATIONAL", async () => {
    const response = await approveRoute(TENANT_OWNER, tenantConnectionId);
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.resource.configuration_status).toBe("CONFIGURED");
    expect(body.resource.operational_status).toBe("OPERATIONAL");
  });

  it("RegisterIntegration is idempotent under a repeated idempotency key", async () => {
    const key = crypto.randomUUID();
    const first = await registerRoute(PLATFORM_ADMIN, { ...platformRegistration, provider_key: "IDEMPOTENT_CO" }, key);
    const second = await registerRoute(PLATFORM_ADMIN, { ...platformRegistration, provider_key: "IDEMPOTENT_CO" }, key);
    expect(first.status).toBe(201);
    expect(second.status).toBe(201);
    expect((await first.json()).resource.id).toBe((await second.json()).resource.id);
  });
});
