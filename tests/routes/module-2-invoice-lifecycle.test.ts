import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 2 Phase B, proven through the real route handlers: invoice_number
 * uniqueness per supplier (previously unenforced — see
 * MODULE_DEVELOPMENT_PLAYBOOK.md), CancelInvoice's officer-only, narrow-
 * eligibility lifecycle, and correction/cancellation lineage surfacing
 * through the public VerifyInvoice endpoint. See
 * tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const SELLER_OWNER: FixtureUser = { userId: "usr-lc-seller-owner", externalUserId: "ext-lc-seller-owner", email: "owner@lc-seller.test" };
const NAMRA_ADMIN: FixtureUser = { userId: "usr-lc-namra", externalUserId: "ext-lc-namra", email: "namra@lc.test" };

function actingAs(user: FixtureUser): void {
  __setRequestHeaders({ "oai-authenticated-user-id": user.externalUserId, "oai-authenticated-user-email": user.email });
}

function invoicePayload(input: { sourceDocumentId: string; invoiceNumber: string }) {
  return {
    schema_version: "1.0.0",
    document_type: "TAX_INVOICE",
    source: { system_id: "TEST-ERP", document_id: input.sourceDocumentId, submitted_at: new Date().toISOString() },
    supplier: { name: "Lifecycle Seller Co", identifiers: [{ type: "VAT_NUMBER", value: "VAT-LC-SELLER-001" }] },
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

function cancelRequest(reason: string, stepUp = true): Request {
  return new Request("https://vat-msa.local/api/v1/invoices/x/cancellation", {
    method: "POST",
    headers: {
      "content-type": "application/json",
      ...(stepUp ? { "x-vat-msa-auth-assurance": "MFA_STEP_UP", "x-vat-msa-reauthenticated-at": new Date().toISOString() } : {}),
    },
    body: JSON.stringify({ reason }),
  });
}

async function seedFixture(): Promise<void> {
  const db = env.DB;
  const now = "2026-08-01T00:00:00.000Z";
  await db.batch([
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-lc-seller", "VAT-LC-SELLER-001", "TIN-LC-SELLER-001", "Lifecycle Seller Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Seller Street", "finance@lc-seller.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-lc-seller", "tp-lc-seller", "Lifecycle Seller Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO organisation_capabilities (id,organisation_id,capability,status,effective_from,effective_to,approved_by,created_at)
      VALUES (?,?,?,?,?,NULL,?,?)`).bind("cap-lc-seller", "org-lc-seller", "SELLER", "ACTIVE", now, "SYSTEM_BOOTSTRAP", now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-sites-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(SELLER_OWNER.userId, SELLER_OWNER.externalUserId, SELLER_OWNER.email, "Seller Owner", "TAXPAYER_OWNER", "tp-lc-seller", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_ADMIN.userId, NAMRA_ADMIN.externalUserId, NAMRA_ADMIN.email, "NamRA Admin", "PILOT_ADMIN", null, "ACTIVE", now),
    ...[SELLER_OWNER, NAMRA_ADMIN].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-sites-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
  ]);
}

describe("Module 2 invoice lifecycle (Phase B)", () => {
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

  it("rejects a second invoice reusing the same invoice_number for the same supplier", async () => {
    const { POST } = await import("@/app/api/v1/invoices/route");
    actingAs(SELLER_OWNER);
    const first = await POST(submitRequest(invoicePayload({ sourceDocumentId: "num-doc-1", invoiceNumber: "INV-DUP-001" })));
    expect(first.status).toBe(201);

    const second = await POST(submitRequest(invoicePayload({ sourceDocumentId: "num-doc-2", invoiceNumber: "INV-DUP-001" })));
    expect(second.status).toBe(409);
  });

  describe("CancelInvoice", () => {
    it("denies the submitting taxpayer cancelling their own invoice (officer-only)", async () => {
      const { POST: submitPOST } = await import("@/app/api/v1/invoices/route");
      actingAs(SELLER_OWNER);
      const submitResponse = await submitPOST(submitRequest(invoicePayload({ sourceDocumentId: "cancel-doc-1", invoiceNumber: "INV-CANCEL-001" })));
      const invoiceId = (await submitResponse.json()).invoice_id;

      const { POST: cancelPOST } = await import("@/app/api/v1/invoices/[id]/cancellation/route");
      actingAs(SELLER_OWNER);
      const response = await cancelPOST(cancelRequest("Attempting self-cancellation."), { params: Promise.resolve({ id: invoiceId }) });
      expect(response.status).toBe(403);
    });

    it("cancels the invoice for a NamRA officer, reversing its ledger entries, and is idempotent", async () => {
      const { POST: submitPOST } = await import("@/app/api/v1/invoices/route");
      actingAs(SELLER_OWNER);
      const submitResponse = await submitPOST(submitRequest(invoicePayload({ sourceDocumentId: "cancel-doc-2", invoiceNumber: "INV-CANCEL-002" })));
      const invoiceId = (await submitResponse.json()).invoice_id;

      const { POST: cancelPOST } = await import("@/app/api/v1/invoices/[id]/cancellation/route");
      actingAs(NAMRA_ADMIN);
      const response = await cancelPOST(cancelRequest("Duplicate data entry by the taxpayer, confirmed with NamRA."), { params: Promise.resolve({ id: invoiceId }) });
      expect(response.status).toBe(200);
      expect((await response.json()).cancellation).toEqual({ invoiceId, status: "CANCELLED" });

      const ledgerRows = await env.DB.prepare("SELECT entry_type, direction, amount_cents FROM ledger_entries WHERE invoice_id=? ORDER BY entry_type, direction")
        .bind(invoiceId).all<{ entry_type: string; direction: string; amount_cents: number }>();
      expect(ledgerRows.results).toEqual([
        { entry_type: "OUTPUT_VAT", direction: "CREDIT", amount_cents: 1500 },
        { entry_type: "OUTPUT_VAT", direction: "DEBIT", amount_cents: 1500 },
      ]);

      const secondCancel = await cancelPOST(cancelRequest("Cancelling again."), { params: Promise.resolve({ id: invoiceId }) });
      expect(secondCancel.status).toBe(200);
      const ledgerRowsAfterRepeat = await env.DB.prepare("SELECT COUNT(*) AS n FROM ledger_entries WHERE invoice_id=?").bind(invoiceId).first<{ n: number }>();
      expect(ledgerRowsAfterRepeat?.n).toBe(2);
    });

    it("rejects cancelling an invoice that already has an active credit note against it", async () => {
      const { POST: submitPOST } = await import("@/app/api/v1/invoices/route");
      actingAs(SELLER_OWNER);
      const originalResponse = await submitPOST(submitRequest(invoicePayload({ sourceDocumentId: "cancel-doc-3", invoiceNumber: "INV-CANCEL-003" })));
      const original = await originalResponse.json();

      const creditPayload = {
        ...invoicePayload({ sourceDocumentId: "cancel-doc-3-credit", invoiceNumber: "INV-CANCEL-003-CN" }),
        document_type: "CREDIT_NOTE",
        original_document_reference: { source_document_id: "cancel-doc-3", vat_msa_invoice_id: original.invoice_id, reason_code: "PRICING_ERROR", reason: "Overcharged the customer on the original invoice." },
        lines: [{
          line_number: 1, description: "Consulting services", quantity: "1", unit_code: "EA",
          unit_price: "-100.00", net_amount: "-100.00",
          tax: { category: "STANDARD", rate: "15.00", taxable_amount: "-100.00", tax_amount: "-15.00" },
        }],
        totals: { line_net_amount: "-100.00", tax_exclusive_amount: "-100.00", tax_amount: "-15.00", tax_inclusive_amount: "-115.00", payable_amount: "-115.00" },
      };
      const creditResponse = await submitPOST(submitRequest(creditPayload));
      expect(creditResponse.status).toBe(201);

      const { POST: cancelPOST } = await import("@/app/api/v1/invoices/[id]/cancellation/route");
      actingAs(NAMRA_ADMIN);
      const response = await cancelPOST(cancelRequest("Trying to cancel after a credit note exists."), { params: Promise.resolve({ id: original.invoice_id }) });
      expect(response.status).toBe(409);
    });
  });

  describe("VerifyInvoice correction and cancellation lineage", () => {
    it("shows a cancelled invoice's status publicly", async () => {
      const { POST: submitPOST } = await import("@/app/api/v1/invoices/route");
      actingAs(SELLER_OWNER);
      const submitResponse = await submitPOST(submitRequest(invoicePayload({ sourceDocumentId: "verify-cancel-doc", invoiceNumber: "INV-VERIFY-CANCEL" })));
      const submitted = await submitResponse.json();

      const { POST: cancelPOST } = await import("@/app/api/v1/invoices/[id]/cancellation/route");
      actingAs(NAMRA_ADMIN);
      await cancelPOST(cancelRequest("Verifying public lineage after cancellation."), { params: Promise.resolve({ id: submitted.invoice_id }) });

      const { GET: verifyGET } = await import("@/app/api/v1/verify/[token]/route");
      const token = new URL(submitted.verification_url).pathname.split("/").pop()!;
      const verifyResponse = await verifyGET(new Request(`https://vat-msa.local/api/v1/verify/${token}`), { params: Promise.resolve({ token }) });
      const verification = await verifyResponse.json();
      expect(verification.status).toBe("CANCELLED");
    });

    it("shows correction lineage on both the original and the correction", async () => {
      const { POST: submitPOST } = await import("@/app/api/v1/invoices/route");
      actingAs(SELLER_OWNER);
      const originalResponse = await submitPOST(submitRequest(invoicePayload({ sourceDocumentId: "verify-credit-doc", invoiceNumber: "INV-VERIFY-ORIG" })));
      const original = await originalResponse.json();

      const creditPayload = {
        ...invoicePayload({ sourceDocumentId: "verify-credit-doc-cn", invoiceNumber: "INV-VERIFY-CN" }),
        document_type: "CREDIT_NOTE",
        original_document_reference: { source_document_id: "verify-credit-doc", vat_msa_invoice_id: original.invoice_id, reason_code: "PRICING_ERROR", reason: "Overcharged the customer, confidential internal note." },
        lines: [{
          line_number: 1, description: "Consulting services", quantity: "1", unit_code: "EA",
          unit_price: "-100.00", net_amount: "-100.00",
          tax: { category: "STANDARD", rate: "15.00", taxable_amount: "-100.00", tax_amount: "-15.00" },
        }],
        totals: { line_net_amount: "-100.00", tax_exclusive_amount: "-100.00", tax_amount: "-15.00", tax_inclusive_amount: "-115.00", payable_amount: "-115.00" },
      };
      const creditResponse = await submitPOST(submitRequest(creditPayload));
      const credit = await creditResponse.json();

      const { GET: verifyGET } = await import("@/app/api/v1/verify/[token]/route");
      const originalToken = new URL(original.verification_url).pathname.split("/").pop()!;
      const originalVerification = await (await verifyGET(new Request(`https://vat-msa.local/api/v1/verify/${originalToken}`), { params: Promise.resolve({ token: originalToken }) })).json();
      expect(originalVerification.is_correction).toBe(false);
      expect(originalVerification.corrections).toHaveLength(1);
      expect(originalVerification.corrections[0]).toMatchObject({ correction_type: "CREDIT_NOTE", status: "ACTIVE" });
      expect(originalVerification.corrections[0]).not.toHaveProperty("reason");
      expect(originalVerification.corrections[0]).not.toHaveProperty("reason_code");

      const creditToken = new URL(credit.verification_url).pathname.split("/").pop()!;
      const creditVerification = await (await verifyGET(new Request(`https://vat-msa.local/api/v1/verify/${creditToken}`), { params: Promise.resolve({ token: creditToken }) })).json();
      expect(creditVerification.is_correction).toBe(true);
      expect(creditVerification.correction_type).toBe("CREDIT_NOTE");
      expect(creditVerification.corrects_invoice_number_masked).toBeTruthy();
    });
  });
});
