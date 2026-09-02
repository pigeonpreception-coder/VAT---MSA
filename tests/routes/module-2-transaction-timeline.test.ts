import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 2 Phase D GetTransactionTimeline, proven through the real route
 * handler: a plain certification, a certification-plus-correction lineage
 * (reachable from either end), and a certification-plus-cancellation
 * lineage, each producing the right chronological VATTransaction events
 * with the right reference_transaction_id linkage. See
 * tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const SELLER_OWNER: FixtureUser = { userId: "usr-tl-seller-owner", externalUserId: "ext-tl-seller-owner", email: "owner@tl-seller.test" };
const NAMRA_ADMIN: FixtureUser = { userId: "usr-tl-namra", externalUserId: "ext-tl-namra", email: "namra@tl.test" };

/** Also grants a fresh, server-verified step-up (step_up_events row) for the acting user — CancelInvoice is step-up gated and there is no longer a header shortcut around lib/security/step-up.ts's real requireStepUp check. */
async function actingAs(user: FixtureUser): Promise<void> {
  __setRequestHeaders({ "oai-authenticated-user-id": user.externalUserId, "oai-authenticated-user-email": user.email });
  await env.DB.prepare("INSERT INTO step_up_events (id,user_id,method,verified_at,expires_at) VALUES (?,?,?,?,?)")
    .bind(crypto.randomUUID(), user.userId, "TOTP", new Date().toISOString(), new Date(Date.now() + 5 * 60_000).toISOString()).run();
}

function invoicePayload(input: { sourceDocumentId: string; invoiceNumber: string }) {
  return {
    schema_version: "1.0.0",
    document_type: "TAX_INVOICE",
    source: { system_id: "TEST-ERP", document_id: input.sourceDocumentId, submitted_at: new Date().toISOString() },
    supplier: { name: "Timeline Seller Co", identifiers: [{ type: "VAT_NUMBER", value: "VAT-TL-SELLER-001" }] },
    customer: { name: "Walk-in customer", identifiers: [{ type: "OTHER", value: "walk-in" }] },
    invoice_number: input.invoiceNumber,
    issue_date: "2026-08-25",
    currency: "NAD",
    lines: [{
      line_number: 1, description: "Consulting services", quantity: "1", unit_code: "EA",
      unit_price: "100.00", net_amount: "100.00",
      tax: { category: "STANDARD", rate: "15.00", taxable_amount: "100.00", tax_amount: "15.00" },
    }],
    totals: { line_net_amount: "100.00", tax_exclusive_amount: "100.00", tax_amount: "15.00", tax_inclusive_amount: "115.00", payable_amount: "115.00" },
  };
}

function submitRequest(body: unknown): Request {
  return new Request("https://vat-msa.local/api/v1/invoices", {
    method: "POST",
    headers: { "content-type": "application/json", "idempotency-key": crypto.randomUUID() },
    body: JSON.stringify(body),
  });
}

function timelineRequest(id: string): [Request, { params: Promise<{ id: string }> }] {
  return [new Request(`https://vat-msa.local/api/v1/invoices/${id}/transaction-timeline`), { params: Promise.resolve({ id }) }];
}

async function seedFixture(): Promise<void> {
  const db = env.DB;
  const now = "2026-08-01T00:00:00.000Z";
  await db.batch([
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-tl-seller", "VAT-TL-SELLER-001", "TIN-TL-SELLER-001", "Timeline Seller Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Seller Street", "finance@tl-seller.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-tl-seller", "tp-tl-seller", "Timeline Seller Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO organisation_capabilities (id,organisation_id,capability,status,effective_from,effective_to,approved_by,created_at)
      VALUES (?,?,?,?,?,NULL,?,?)`).bind("cap-tl-seller", "org-tl-seller", "SELLER", "ACTIVE", now, "SYSTEM_BOOTSTRAP", now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-sites-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(SELLER_OWNER.userId, SELLER_OWNER.externalUserId, SELLER_OWNER.email, "Seller Owner", "TAXPAYER_OWNER", "tp-tl-seller", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_ADMIN.userId, NAMRA_ADMIN.externalUserId, NAMRA_ADMIN.email, "NamRA Admin", "PILOT_ADMIN", null, "ACTIVE", now),
    ...[SELLER_OWNER, NAMRA_ADMIN].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-sites-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
  ]);
}

describe("Module 2 GetTransactionTimeline (Phase D)", () => {
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

  it("shows a single CERTIFICATION event for a plain, uncorrected invoice", async () => {
    const { POST } = await import("@/app/api/v1/invoices/route");
    await actingAs(SELLER_OWNER);
    const submitResponse = await POST(submitRequest(invoicePayload({ sourceDocumentId: "tl-plain-doc", invoiceNumber: "INV-TL-PLAIN" })));
    const submitted = await submitResponse.json();

    const { GET } = await import("@/app/api/v1/invoices/[id]/transaction-timeline/route");
    const [request, context] = timelineRequest(submitted.invoice_id);
    const response = await GET(request, context);
    expect(response.status).toBe(200);
    const timeline = await response.json();
    expect(timeline.rootInvoiceId).toBe(submitted.invoice_id);
    expect(timeline.events).toHaveLength(1);
    expect(timeline.events[0]).toMatchObject({ transactionType: "CERTIFICATION", referenceTransactionId: null, invoiceId: submitted.invoice_id });
    expect(timeline.events[0].ledgerEntries).toEqual([{ taxpayerName: "Timeline Seller Co (Pty) Ltd", entryType: "OUTPUT_VAT", direction: "CREDIT", amountCents: 1500, period: "2026-08" }]);
  });

  it("shows CERTIFICATION and CORRECTION events, linked, reachable from either invoice id", async () => {
    const { POST } = await import("@/app/api/v1/invoices/route");
    await actingAs(SELLER_OWNER);
    const originalResponse = await POST(submitRequest(invoicePayload({ sourceDocumentId: "tl-orig-doc", invoiceNumber: "INV-TL-ORIG" })));
    const original = await originalResponse.json();

    const creditPayload = {
      ...invoicePayload({ sourceDocumentId: "tl-orig-doc-cn", invoiceNumber: "INV-TL-CN" }),
      document_type: "CREDIT_NOTE",
      original_document_reference: { source_document_id: "tl-orig-doc", vat_msa_invoice_id: original.invoice_id, reason_code: "PRICING_ERROR", reason: "Overcharged the customer on the original invoice." },
      lines: [{
        line_number: 1, description: "Consulting services", quantity: "1", unit_code: "EA",
        unit_price: "-100.00", net_amount: "-100.00",
        tax: { category: "STANDARD", rate: "15.00", taxable_amount: "-100.00", tax_amount: "-15.00" },
      }],
      totals: { line_net_amount: "-100.00", tax_exclusive_amount: "-100.00", tax_amount: "-15.00", tax_inclusive_amount: "-115.00", payable_amount: "-115.00" },
    };
    const creditResponse = await POST(submitRequest(creditPayload));
    const credit = await creditResponse.json();

    const { GET } = await import("@/app/api/v1/invoices/[id]/transaction-timeline/route");

    const [fromOriginalRequest, fromOriginalContext] = timelineRequest(original.invoice_id);
    const fromOriginal = await (await GET(fromOriginalRequest, fromOriginalContext)).json();
    expect(fromOriginal.rootInvoiceId).toBe(original.invoice_id);
    expect(fromOriginal.events).toHaveLength(2);
    expect(fromOriginal.events[0]).toMatchObject({ transactionType: "CERTIFICATION", invoiceId: original.invoice_id, referenceTransactionId: null });
    expect(fromOriginal.events[1]).toMatchObject({ transactionType: "CORRECTION", invoiceId: credit.invoice_id, referenceTransactionId: fromOriginal.events[0].transactionId });

    const [fromCreditRequest, fromCreditContext] = timelineRequest(credit.invoice_id);
    const fromCredit = await (await GET(fromCreditRequest, fromCreditContext)).json();
    expect(fromCredit).toEqual(fromOriginal);
  });

  it("shows CERTIFICATION and CANCELLATION events, linked", async () => {
    const { POST: submitPOST } = await import("@/app/api/v1/invoices/route");
    await actingAs(SELLER_OWNER);
    const submitResponse = await submitPOST(submitRequest(invoicePayload({ sourceDocumentId: "tl-cancel-doc", invoiceNumber: "INV-TL-CANCEL" })));
    const submitted = await submitResponse.json();

    const { POST: cancelPOST } = await import("@/app/api/v1/invoices/[id]/cancellation/route");
    await actingAs(NAMRA_ADMIN);
    await cancelPOST(new Request("https://vat-msa.local/api/v1/invoices/x/cancellation", {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify({ reason: "Confirmed duplicate submission with the taxpayer." }),
    }), { params: Promise.resolve({ id: submitted.invoice_id }) });

    const { GET } = await import("@/app/api/v1/invoices/[id]/transaction-timeline/route");
    const [request, context] = timelineRequest(submitted.invoice_id);
    const timeline = await (await GET(request, context)).json();
    expect(timeline.events).toHaveLength(2);
    expect(timeline.events[0].transactionType).toBe("CERTIFICATION");
    expect(timeline.events[1]).toMatchObject({ transactionType: "CANCELLATION", referenceTransactionId: timeline.events[0].transactionId });
    expect(timeline.events[1].ledgerEntries).toEqual([{ taxpayerName: "Timeline Seller Co (Pty) Ltd", entryType: "OUTPUT_VAT", direction: "DEBIT", amountCents: 1500, period: "2026-08" }]);
  });

  it("returns 404 for an unknown invoice id", async () => {
    const { GET } = await import("@/app/api/v1/invoices/[id]/transaction-timeline/route");
    const [request, context] = timelineRequest("does-not-exist");
    const response = await GET(request, context);
    expect(response.status).toBe(404);
  });
});
