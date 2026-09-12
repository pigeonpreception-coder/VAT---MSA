import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * NamRA e-VAT MS Registered Taxpayer Systems Framework (master prompt
 * section 6): RegisterTaxpayerSystem/ApproveTaxpayerSystem/
 * SuspendTaxpayerSystem/RecordTaxpayerSystemSync/ListTaxpayerSystems/
 * GetTaxpayerSystem against taxpayer_system_registrations. Proves: a
 * taxpayer registers their own ERP/POS system (never someone else's VAT
 * number/TIN); approval is restricted to a national-scope (NamRA) actor,
 * never the taxpayer's own registration act; suspension is self-service;
 * sync can only be recorded once approved; and list/get scoping mirrors
 * Module 10's tenant-vs-national ownership boundary. See
 * tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const OWNER_A: FixtureUser = { userId: "usr-ts-owner-a", externalUserId: "ext-ts-owner-a", email: "owner-a@ts-test.test" };
const OWNER_B: FixtureUser = { userId: "usr-ts-owner-b", externalUserId: "ext-ts-owner-b", email: "owner-b@ts-test.test" };
const NAMRA_SUPERVISOR: FixtureUser = { userId: "usr-ts-namra-supervisor", externalUserId: "ext-ts-namra-supervisor", email: "supervisor@ts-test.test" };
const NAMRA_COMPLIANCE: FixtureUser = { userId: "usr-ts-namra-compliance", externalUserId: "ext-ts-namra-compliance", email: "compliance@ts-test.test" };
const STAFF: FixtureUser = { userId: "usr-ts-staff", externalUserId: "ext-ts-staff", email: "staff@ts-test.test" };

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
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-ts-a", "VAT-TS-A01", "TIN-TS-A01", "Taxpayer System Co A (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 System Street", "finance@ts-a.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-ts-a", "tp-ts-a", "Taxpayer System Co A (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-ts-b", "VAT-TS-B01", "TIN-TS-B01", "Taxpayer System Co B (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "2 System Street", "finance@ts-b.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-ts-b", "tp-ts-b", "Taxpayer System Co B (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-ts-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OWNER_A.userId, OWNER_A.externalUserId, OWNER_A.email, "Owner A", "TAXPAYER_OWNER", "tp-ts-a", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OWNER_B.userId, OWNER_B.externalUserId, OWNER_B.email, "Owner B", "TAXPAYER_OWNER", "tp-ts-b", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_SUPERVISOR.userId, NAMRA_SUPERVISOR.externalUserId, NAMRA_SUPERVISOR.email, "NamRA Supervisor", "NAMRA_SUPERVISOR", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_COMPLIANCE.userId, NAMRA_COMPLIANCE.externalUserId, NAMRA_COMPLIANCE.email, "NamRA Compliance", "NAMRA_COMPLIANCE_OFFICER", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(STAFF.userId, STAFF.externalUserId, STAFF.email, "Staff", "TAXPAYER_STAFF", "tp-ts-a", "ACTIVE", now),
    ...[OWNER_A, OWNER_B, NAMRA_SUPERVISOR, NAMRA_COMPLIANCE, STAFF].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-ts-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
  ]);
}

async function registerRoute(actor: FixtureUser, body: unknown, idempotencyKey?: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/taxpayer-systems/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/taxpayer-systems", "POST", body, idempotencyKey));
}

async function approveRoute(actor: FixtureUser, id: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/taxpayer-systems/[id]/approval/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/taxpayer-systems/${id}/approval`, "POST", {}), { params: Promise.resolve({ id }) });
}

async function suspendRoute(actor: FixtureUser, id: string, reason: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/taxpayer-systems/[id]/suspension/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/taxpayer-systems/${id}/suspension`, "POST", { schema_version: "1.0.0", reason }), { params: Promise.resolve({ id }) });
}

async function syncRoute(actor: FixtureUser, id: string, apiStatus: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/taxpayer-systems/[id]/sync/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/taxpayer-systems/${id}/sync`, "POST", { schema_version: "1.0.0", api_status: apiStatus }), { params: Promise.resolve({ id }) });
}

async function getRoute(actor: FixtureUser, id: string): Promise<Response> {
  const { GET } = await import("@/app/api/v1/taxpayer-systems/[id]/route");
  actingAs(actor);
  return GET(new Request(`https://vat-msa.local/api/v1/taxpayer-systems/${id}`), { params: Promise.resolve({ id }) });
}

async function listRoute(actor: FixtureUser): Promise<Response> {
  const { GET } = await import("@/app/api/v1/taxpayer-systems/route");
  actingAs(actor);
  return GET(new Request("https://vat-msa.local/api/v1/taxpayer-systems"));
}

const registrationA = { schema_version: "1.0.0", vat_registration_number: "VAT-TS-A01", tin: "TIN-TS-A01", system_name: "Sage Evolution", system_vendor: "Sage", system_category: "ACCOUNTING" };

describe("NamRA e-VAT MS Registered Taxpayer Systems: RegisterTaxpayerSystem/ApproveTaxpayerSystem/SuspendTaxpayerSystem/RecordTaxpayerSystemSync", () => {
  let registrationId: string;

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

  it("registers a taxpayer's own system as DRAFT/NOT_CONNECTED, defaulting to that actor's own organisation", async () => {
    const response = await registerRoute(OWNER_A, registrationA);
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.resource.organisation_id).toBe("org-ts-a");
    expect(body.resource.registration_status).toBe("DRAFT");
    expect(body.resource.api_status).toBe("NOT_CONNECTED");
    expect(body.resource.security_status).toBe("NOT_ASSESSED");
    registrationId = body.resource.id;
  });

  it("refuses registering a system claiming a VAT registration number that isn't the actor's own", async () => {
    const response = await registerRoute(OWNER_A, { ...registrationA, system_name: "Someone Else's System", vat_registration_number: "VAT-TS-B01" });
    expect(response.status).toBe(422);
  });

  it("refuses registering a system claiming a TIN that isn't the actor's own", async () => {
    const response = await registerRoute(OWNER_A, { ...registrationA, system_name: "Mismatched TIN System", tin: "TIN-TS-B01" });
    expect(response.status).toBe(422);
  });

  it("refuses a duplicate registration for the same system name/vendor in the same organisation", async () => {
    const response = await registerRoute(OWNER_A, registrationA);
    expect(response.status).toBe(409);
  });

  it("rejects registration with a validation error for an invalid system_category", async () => {
    const response = await registerRoute(OWNER_A, { ...registrationA, system_name: "Bad Category Co", system_category: "SPACESHIP" });
    expect(response.status).toBe(422);
  });

  it("denies registration to a role without taxpayer-systems:manage", async () => {
    const response = await registerRoute(STAFF, { ...registrationA, system_name: "Staff Attempt" });
    expect(response.status).toBe(403);
  });

  it("refuses a tenant actor approving their own registration", async () => {
    const response = await approveRoute(OWNER_A, registrationId);
    expect(response.status).toBe(403);
  });

  it("refuses another taxpayer's owner reaching into org A's registration", async () => {
    const response = await getRoute(OWNER_B, registrationId);
    expect(response.status).toBe(403);
  });

  it("refuses recording synchronization before approval", async () => {
    const response = await syncRoute(OWNER_A, registrationId, "CONNECTED");
    expect(response.status).toBe(409);
  });

  it("a national-scope actor holding only taxpayer-systems:read cannot approve", async () => {
    const response = await approveRoute(NAMRA_COMPLIANCE, registrationId);
    expect(response.status).toBe(403);
  });

  it("ApproveTaxpayerSystem moves DRAFT -> APPROVED for a national-scope (NamRA) actor", async () => {
    const response = await approveRoute(NAMRA_SUPERVISOR, registrationId);
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.resource.registration_status).toBe("APPROVED");
  });

  it("RecordTaxpayerSystemSync updates api_status and last_synchronization_at once approved", async () => {
    const response = await syncRoute(OWNER_A, registrationId, "CONNECTED");
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.resource.api_status).toBe("CONNECTED");
    expect(body.resource.last_synchronization_at).toBeTruthy();
  });

  it("rejects an invalid api_status with a validation error", async () => {
    const response = await syncRoute(OWNER_A, registrationId, "TELEPORTED");
    expect(response.status).toBe(422);
  });

  it("GetTaxpayerSystem returns the registration to its own organisation", async () => {
    const response = await getRoute(OWNER_A, registrationId);
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.resource.id).toBe(registrationId);
  });

  it("ListTaxpayerSystems scopes a tenant actor to their own organisation only", async () => {
    const response = await listRoute(OWNER_A);
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.resources.map((r: { id: string }) => r.id)).toEqual([registrationId]);
  });

  it("ListTaxpayerSystems shows every taxpayer's registrations to a national-scope actor", async () => {
    const response = await listRoute(NAMRA_SUPERVISOR);
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.resources.map((r: { id: string }) => r.id)).toContain(registrationId);
  });

  it("SuspendTaxpayerSystem requires a reason and is self-service (the owning taxpayer may pause their own system)", async () => {
    const missingReason = await suspendRoute(OWNER_A, registrationId, "");
    expect(missingReason.status).toBe(422);

    const response = await suspendRoute(OWNER_A, registrationId, "Decommissioning this system for a replacement.");
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.resource.registration_status).toBe("SUSPENDED");
  });

  it("refuses recording synchronization against a suspended registration", async () => {
    const response = await syncRoute(OWNER_A, registrationId, "CONNECTED");
    expect(response.status).toBe(409);
  });

  it("ApproveTaxpayerSystem reactivates SUSPENDED -> APPROVED", async () => {
    const response = await approveRoute(NAMRA_SUPERVISOR, registrationId);
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.resource.registration_status).toBe("APPROVED");
  });

  it("RegisterTaxpayerSystem is idempotent under a repeated idempotency key", async () => {
    const key = crypto.randomUUID();
    const first = await registerRoute(OWNER_A, { ...registrationA, system_name: "Idempotent POS" }, key);
    const second = await registerRoute(OWNER_A, { ...registrationA, system_name: "Idempotent POS" }, key);
    expect(first.status).toBe(201);
    expect(second.status).toBe(201);
    expect((await first.json()).resource.id).toBe((await second.json()).resource.id);
  });
});
