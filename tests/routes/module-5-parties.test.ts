import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 5 Phase A: SearchCustomers/SearchSuppliers and VerifySupplier,
 * proven through the real route handlers (app/api/v1/business-parties,
 * app/api/v1/business-parties/[id]/verification, dispatched via
 * lib/api/business.ts) and lib/data/business-repository.ts. See
 * tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const OWNER: FixtureUser = { userId: "usr-party-owner", externalUserId: "ext-party-owner", email: "owner@party-test.test" };
const VIEWER: FixtureUser = { userId: "usr-party-viewer", externalUserId: "ext-party-viewer", email: "viewer@party-test.test" };

function actingAs(user: FixtureUser): void {
  __setRequestHeaders({ "oai-authenticated-user-id": user.externalUserId, "oai-authenticated-user-email": user.email });
}

function jsonRequest(url: string, body: unknown, idempotencyKey: string): Request {
  return new Request(url, {
    method: "POST",
    headers: { "content-type": "application/json", "idempotency-key": idempotencyKey },
    body: JSON.stringify(body),
  });
}

async function seedFixture(): Promise<void> {
  const db = env.DB;
  const now = "2026-08-01T00:00:00.000Z";
  await db.batch([
    // The taxpayer whose organisation owns the business_parties records under test.
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-party-taxpayer", "VAT-PARTY-001", "TIN-PARTY-001", "Party Test Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Party Street", "finance@party-test.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-party-taxpayer", "tp-party-taxpayer", "Party Test Co (Pty) Ltd", null, "ACTIVE", now, now),
    // A second, real, ACTIVE taxpayer in the national registry — used as the VAT number a "valid" supplier cites.
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-party-realsupplier", "VAT-PARTY-REAL", "TIN-PARTY-REAL", "Real Supplier Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Real Supplier Street", "finance@real-supplier.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-party-realsupplier", "tp-party-realsupplier", "Real Supplier Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-party-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OWNER.userId, OWNER.externalUserId, OWNER.email, "Owner", "TAXPAYER_OWNER", "tp-party-taxpayer", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(VIEWER.userId, VIEWER.externalUserId, VIEWER.email, "Viewer", "TAXPAYER_VIEWER", "tp-party-taxpayer", "ACTIVE", now),
    ...[OWNER, VIEWER].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-party-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
  ]);
}

async function createPartyRoute(body: Record<string, unknown>, actor: FixtureUser, key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/business-parties/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/business-parties", { schema_version: "1.0.0", relationships: ["CUSTOMER"], ...body }, key));
}

async function searchPartiesRoute(actor: FixtureUser, query: Record<string, string> = {}): Promise<Response> {
  const { GET } = await import("@/app/api/v1/business-parties/route");
  actingAs(actor);
  const params = new URLSearchParams(query);
  const qs = params.toString();
  return GET(new Request(`https://vat-msa.local/api/v1/business-parties${qs ? `?${qs}` : ""}`));
}

async function verifySupplierRoute(partyId: string, actor: FixtureUser, key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/business-parties/[id]/verification/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/business-parties/${partyId}/verification`, { schema_version: "1.0.0" }, key), { params: Promise.resolve({ id: partyId }) });
}

async function getVerificationHistoryRoute(partyId: string, actor: FixtureUser): Promise<Response> {
  const { GET } = await import("@/app/api/v1/business-parties/[id]/verification/route");
  actingAs(actor);
  return GET(new Request(`https://vat-msa.local/api/v1/business-parties/${partyId}/verification`), { params: Promise.resolve({ id: partyId }) });
}

describe("Module 5 party search and supplier verification (Phase A)", () => {
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

  it("filters search results by relationship: SearchCustomers and SearchSuppliers are the same search with different filters", async () => {
    await createPartyRoute({ display_name: "Acme Customer", relationships: ["CUSTOMER"] }, OWNER);
    await createPartyRoute({ display_name: "Acme Supplier", vat_number: "VAT-PARTY-ACMESUPPLIER", relationships: ["SUPPLIER"] }, OWNER);
    await createPartyRoute({ display_name: "Acme Both", relationships: ["CUSTOMER", "SUPPLIER"] }, OWNER);

    const customers = await searchPartiesRoute(OWNER, { relationship: "CUSTOMER" });
    expect(customers.status).toBe(200);
    const customersBody = await customers.json();
    const customerNames = customersBody.parties.map((p: { display_name: string }) => p.display_name);
    expect(customerNames).toEqual(expect.arrayContaining(["Acme Customer", "Acme Both"]));
    expect(customerNames).not.toContain("Acme Supplier");

    const suppliers = await searchPartiesRoute(OWNER, { relationship: "SUPPLIER" });
    const suppliersBody = await suppliers.json();
    const supplierNames = suppliersBody.parties.map((p: { display_name: string }) => p.display_name);
    expect(supplierNames).toEqual(expect.arrayContaining(["Acme Supplier", "Acme Both"]));
    expect(supplierNames).not.toContain("Acme Customer");
  });

  it("filters search results by free-text query across name/VAT/TIN", async () => {
    await createPartyRoute({ display_name: "Unique Search Target", vat_number: "VAT-UNIQUE-9001" }, OWNER);
    const response = await searchPartiesRoute(OWNER, { q: "Unique Search" });
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.parties).toHaveLength(1);
    expect(body.parties[0].display_name).toBe("Unique Search Target");
  });

  it("returns a real total_count independent of the page limit", async () => {
    const response = await searchPartiesRoute(OWNER, { limit: "1" });
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.parties).toHaveLength(1);
    expect(body.total_count).toBeGreaterThan(1);
  });

  it("rejects an invalid relationship filter", async () => {
    const response = await searchPartiesRoute(OWNER, { relationship: "TAX_AUTHORITY" });
    expect(response.status).toBe(422);
  });

  it("verifies a supplier against a real, active taxpayer, records a snapshot, and writes a fresh one on each re-verification", async () => {
    // Reused for both assertions below — business_parties dedups VAT numbers
    // per organisation, so a second party citing the same VAT number would
    // be rejected at creation, not at verification.
    const created = await createPartyRoute({ display_name: "Verifiable Supplier", vat_number: "VAT-PARTY-REAL", relationships: ["SUPPLIER"] }, OWNER);
    const partyId = (await created.json()).resource.id as string;

    const verified = await verifySupplierRoute(partyId, OWNER);
    expect(verified.status).toBe(200);
    const verifiedBody = await verified.json();
    expect(verifiedBody.resource.taxpayer_active).toBe(1);
    expect(verifiedBody.resource.can_act_as_seller).toBe(0);

    const firstHistory = await getVerificationHistoryRoute(partyId, OWNER);
    expect(firstHistory.status).toBe(200);
    const firstHistoryBody = await firstHistory.json();
    expect(firstHistoryBody.snapshots).toHaveLength(1);
    expect(firstHistoryBody.snapshots[0].vat_number).toBe("VAT-PARTY-REAL");

    // Re-verifying writes a second, independent snapshot rather than reusing a cached result.
    const reVerified = await verifySupplierRoute(partyId, OWNER, crypto.randomUUID());
    expect(reVerified.status).toBe(200);
    const secondHistory = await getVerificationHistoryRoute(partyId, OWNER);
    const secondHistoryBody = await secondHistory.json();
    expect(secondHistoryBody.snapshots).toHaveLength(2);
  });

  it("verifies a supplier citing a VAT number that resolves to no taxpayer at all", async () => {
    const created = await createPartyRoute({ display_name: "Unverifiable Supplier", vat_number: "VAT-DOES-NOT-EXIST", relationships: ["SUPPLIER"] }, OWNER);
    const partyId = (await created.json()).resource.id as string;
    const verified = await verifySupplierRoute(partyId, OWNER);
    expect(verified.status).toBe(200);
    const body = await verified.json();
    expect(body.resource.taxpayer_active).toBe(0);
  });

  it("rejects verifying a party with no VAT number recorded", async () => {
    const created = await createPartyRoute({ display_name: "No VAT Supplier", relationships: ["SUPPLIER"] }, OWNER);
    const partyId = (await created.json()).resource.id as string;
    const response = await verifySupplierRoute(partyId, OWNER);
    expect(response.status).toBe(409);
  });

  it("rejects verifying a party that is not tagged as an active supplier", async () => {
    const created = await createPartyRoute({ display_name: "Customer Only", vat_number: "VAT-PARTY-CUSTOMERONLY", relationships: ["CUSTOMER"] }, OWNER);
    const partyId = (await created.json()).resource.id as string;
    const response = await verifySupplierRoute(partyId, OWNER);
    expect(response.status).toBe(409);
  });

  it("returns 404 verifying a non-existent business party", async () => {
    const response = await verifySupplierRoute(crypto.randomUUID(), OWNER);
    expect(response.status).toBe(404);
  });

  it("denies an actor without parties:manage from searching or verifying", async () => {
    const searchResponse = await searchPartiesRoute(VIEWER);
    expect(searchResponse.status).toBe(403);
    const verifyResponse = await verifySupplierRoute(crypto.randomUUID(), VIEWER);
    expect(verifyResponse.status).toBe(403);
  });
});
