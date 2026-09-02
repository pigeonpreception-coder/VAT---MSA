import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 5 Phase B: the Quotation lifecycle's new DRAFT/SEND retrofit and
 * SearchQuotes, proven through the real route handlers
 * (app/api/v1/quotations, app/api/v1/quotations/[id]/*, dispatched via
 * lib/api/business.ts) and lib/data/business-repository.ts. Also proves the
 * pre-existing accept->convert pipeline still works unchanged now that
 * creation lands in DRAFT instead of ISSUED. See
 * tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const OWNER: FixtureUser = { userId: "usr-quote-owner", externalUserId: "ext-quote-owner", email: "owner@quote-test.test" };
const VIEWER: FixtureUser = { userId: "usr-quote-viewer", externalUserId: "ext-quote-viewer", email: "viewer@quote-test.test" };

function actingAs(user: FixtureUser): void {
  __setRequestHeaders({ "oai-authenticated-user-id": user.externalUserId, "oai-authenticated-user-email": user.email });
}

function jsonRequest(url: string, body: unknown, idempotencyKey = crypto.randomUUID()): Request {
  return new Request(url, {
    method: "POST",
    headers: { "content-type": "application/json", "idempotency-key": idempotencyKey },
    body: JSON.stringify(body),
  });
}

function noBodyRequest(url: string, idempotencyKey = crypto.randomUUID()): Request {
  return new Request(url, { method: "POST", headers: { "idempotency-key": idempotencyKey } });
}

function quotationPayload(quotationNumber: string, overrides: Record<string, unknown> = {}) {
  return {
    schema_version: "1.0.0",
    customer_party_id: "party-quote-customer",
    quotation_number: quotationNumber,
    currency: "NAD",
    issue_date: "2026-08-10",
    valid_until: "2026-09-10",
    lines: [{
      description: "Consulting services",
      quantity_micros: 1_000_000,
      unit_code: "EA",
      unit_price_cents: 10_000,
      tax_category: "STANDARD",
      tax_rate_bps: 1_500,
    }],
    ...overrides,
  };
}

async function seedFixture(): Promise<void> {
  const db = env.DB;
  const now = "2026-08-01T00:00:00.000Z";
  await db.batch([
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-quote-taxpayer", "VAT-QUOTE-001", "TIN-QUOTE-001", "Quote Test Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Quote Street", "finance@quote-test.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-quote-taxpayer", "tp-quote-taxpayer", "Quote Test Co (Pty) Ltd", null, "ACTIVE", now, now),
    // Convert exercises the real invoice-certification pipeline, which requires an active SELLER capability.
    db.prepare(`INSERT INTO organisation_capabilities (id,organisation_id,capability,status,effective_from,effective_to,approved_by,created_at)
      VALUES (?,?,?,?,?,NULL,?,?)`).bind("cap-quote-seller", "org-quote-taxpayer", "SELLER", "ACTIVE", now, "SYSTEM_BOOTSTRAP", now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-quote-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OWNER.userId, OWNER.externalUserId, OWNER.email, "Owner", "TAXPAYER_OWNER", "tp-quote-taxpayer", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(VIEWER.userId, VIEWER.externalUserId, VIEWER.email, "Viewer", "TAXPAYER_VIEWER", "tp-quote-taxpayer", "ACTIVE", now),
    ...[OWNER, VIEWER].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-quote-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
    db.prepare(`INSERT INTO business_parties (id,organisation_id,display_name,legal_name,vat_number,tin,email,phone,address,source_system,source_party_id,status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,'LOCAL',NULL,'ACTIVE',?,?)`).bind("party-quote-customer", "org-quote-taxpayer", "Quote Customer Co", null, null, null, null, null, null, now, now),
    db.prepare(`INSERT INTO party_relationships (id,organisation_id,party_id,relationship,status,effective_from,effective_to,created_at)
      VALUES (?,?,?,?,'ACTIVE',?,NULL,?)`).bind("prel-quote-customer", "org-quote-taxpayer", "party-quote-customer", "CUSTOMER", now, now),
  ]);
}

async function createQuotationRoute(quotationNumber: string, actor: FixtureUser, overrides: Record<string, unknown> = {}): Promise<Response> {
  const { POST } = await import("@/app/api/v1/quotations/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/quotations", quotationPayload(quotationNumber, overrides)));
}

async function sendQuotationRoute(id: string, actor: FixtureUser): Promise<Response> {
  const { POST } = await import("@/app/api/v1/quotations/[id]/sending/route");
  actingAs(actor);
  return POST(noBodyRequest(`https://vat-msa.local/api/v1/quotations/${id}/sending`), { params: Promise.resolve({ id }) });
}

async function acceptQuotationRoute(id: string, actor: FixtureUser): Promise<Response> {
  const { POST } = await import("@/app/api/v1/quotations/[id]/accept/route");
  actingAs(actor);
  return POST(noBodyRequest(`https://vat-msa.local/api/v1/quotations/${id}/accept`), { params: Promise.resolve({ id }) });
}

async function convertQuotationRoute(id: string, actor: FixtureUser, body: Record<string, unknown>): Promise<Response> {
  const { POST } = await import("@/app/api/v1/quotations/[id]/convert/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/quotations/${id}/convert`, body), { params: Promise.resolve({ id }) });
}

async function editQuotationRoute(id: string, actor: FixtureUser, body: Record<string, unknown>): Promise<Response> {
  const { PATCH } = await import("@/app/api/v1/quotations/[id]/route");
  actingAs(actor);
  return PATCH(jsonRequest(`https://vat-msa.local/api/v1/quotations/${id}`, body), { params: Promise.resolve({ id }) });
}

async function searchQuotationsRoute(actor: FixtureUser, query: Record<string, string> = {}): Promise<Response> {
  const { GET } = await import("@/app/api/v1/quotations/route");
  actingAs(actor);
  const params = new URLSearchParams(query);
  const qs = params.toString();
  return GET(new Request(`https://vat-msa.local/api/v1/quotations${qs ? `?${qs}` : ""}`));
}

describe("Module 5 quotation lifecycle and search (Phase B)", () => {
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

  it("creates a quotation in DRAFT status, not ISSUED", async () => {
    const response = await createQuotationRoute("Q-DRAFT-0001", OWNER);
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.resource.status).toBe("DRAFT");
  });

  it("sends a draft quotation to ISSUED and rejects sending it again", async () => {
    const created = await createQuotationRoute("Q-SEND-0001", OWNER);
    const id = (await created.json()).resource.id as string;

    const sent = await sendQuotationRoute(id, OWNER);
    expect(sent.status).toBe(200);
    expect((await sent.json()).resource.status).toBe("ISSUED");

    const sentAgain = await sendQuotationRoute(id, OWNER);
    expect(sentAgain.status).toBe(409);
  });

  it("allows editing a draft quotation and an issued quotation, but not once accepted", async () => {
    const created = await createQuotationRoute("Q-EDIT-0001", OWNER);
    const id = (await created.json()).resource.id as string;

    const editedDraft = await editQuotationRoute(id, OWNER, quotationPayload("Q-EDIT-0001", { notes: "Revised while still draft" }));
    expect(editedDraft.status).toBe(200);
    expect((await editedDraft.json()).resource.status).toBe("DRAFT");

    await sendQuotationRoute(id, OWNER);
    const editedIssued = await editQuotationRoute(id, OWNER, quotationPayload("Q-EDIT-0001", { notes: "Revised while issued" }));
    expect(editedIssued.status).toBe(200);
    expect((await editedIssued.json()).resource.status).toBe("ISSUED");

    await acceptQuotationRoute(id, OWNER);
    const editedAccepted = await editQuotationRoute(id, OWNER, quotationPayload("Q-EDIT-0001", { notes: "Should be rejected" }));
    expect(editedAccepted.status).toBe(409);
  });

  it("proves the full accept -> convert pipeline still works now that creation lands in DRAFT", async () => {
    const created = await createQuotationRoute("Q-CONVERT-0001", OWNER);
    const id = (await created.json()).resource.id as string;

    await sendQuotationRoute(id, OWNER);
    const accepted = await acceptQuotationRoute(id, OWNER);
    expect(accepted.status).toBe(200);
    expect((await accepted.json()).resource.status).toBe("ACCEPTED");

    const converted = await convertQuotationRoute(id, OWNER, { schema_version: "1.0.0", invoice_number: "INV-Q-CONVERT-0001", issue_date: "2026-08-11" });
    expect(converted.status).toBe(201);
    const convertedBody = await converted.json();
    expect(convertedBody.resource.invoiceNumber ?? convertedBody.resource.invoice_number).toBe("INV-Q-CONVERT-0001");
  });

  it("filters search results by status, customer_party_id and free-text quotation number", async () => {
    await createQuotationRoute("Q-SEARCH-0001", OWNER);

    const byStatus = await searchQuotationsRoute(OWNER, { status: "DRAFT" });
    expect(byStatus.status).toBe(200);
    const byStatusBody = await byStatus.json();
    expect(byStatusBody.quotations.every((q: { status: string }) => q.status === "DRAFT")).toBe(true);

    const byCustomer = await searchQuotationsRoute(OWNER, { customer_party_id: "party-quote-customer" });
    expect(byCustomer.status).toBe(200);
    expect((await byCustomer.json()).quotations.length).toBeGreaterThan(0);

    const byQuery = await searchQuotationsRoute(OWNER, { q: "Q-SEARCH-0001" });
    const byQueryBody = await byQuery.json();
    expect(byQueryBody.quotations).toHaveLength(1);
    expect(byQueryBody.quotations[0].quotation_number).toBe("Q-SEARCH-0001");
  });

  it("returns a real total_count independent of the page limit", async () => {
    const response = await searchQuotationsRoute(OWNER, { limit: "1" });
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.quotations).toHaveLength(1);
    expect(body.total_count).toBeGreaterThan(1);
  });

  it("rejects an invalid status filter", async () => {
    const response = await searchQuotationsRoute(OWNER, { status: "PENDING" });
    expect(response.status).toBe(422);
  });

  it("denies an actor without quotations:manage from creating or sending, but still allows search", async () => {
    const createResponse = await createQuotationRoute("Q-DENIED-0001", VIEWER);
    expect(createResponse.status).toBe(403);
    const sendResponse = await sendQuotationRoute(crypto.randomUUID(), VIEWER);
    expect(sendResponse.status).toBe(403);
    const searchResponse = await searchQuotationsRoute(VIEWER);
    expect(searchResponse.status).toBe(200);
  });
});
