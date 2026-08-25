import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 2 Phase E hardening, proven end-to-end through the real route
 * handler (not just the pure calculateAndValidateInvoice unit tests):
 *
 * 1. Idempotency under CONCURRENT retries — the same request in flight
 *    twice must never double-post. lib/data/repository.ts's submitInvoice
 *    used a SELECT-then-INSERT idempotency check that was not itself
 *    atomic; two identical requests racing could both pass it and both
 *    reach db.batch(statements), with the loser hitting a raw, unhandled
 *    UNIQUE constraint error instead of an idempotent response.
 * 2. The unidentified-buyer guarantee — an invoice to an unresolved buyer
 *    must never produce an INPUT_VAT ledger posting. The playbook flags any
 *    regression here as P0; the logic was already correct but had zero
 *    test coverage before this.
 *
 * See tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const SELLER_OWNER: FixtureUser = { userId: "usr-hard-seller-owner", externalUserId: "ext-hard-seller-owner", email: "owner@hard-seller.test" };

function actingAs(user: FixtureUser): void {
  __setRequestHeaders({ "oai-authenticated-user-id": user.externalUserId, "oai-authenticated-user-email": user.email });
}

function invoicePayload(input: { sourceDocumentId: string; invoiceNumber: string; customerVatNumber?: string }) {
  return {
    schema_version: "1.0.0",
    document_type: "TAX_INVOICE",
    source: { system_id: "TEST-ERP", document_id: input.sourceDocumentId, submitted_at: new Date().toISOString() },
    supplier: { name: "Hardening Seller Co", identifiers: [{ type: "VAT_NUMBER", value: "VAT-HARD-SELLER-001" }] },
    customer: input.customerVatNumber
      ? { name: "Registered Buyer Co", identifiers: [{ type: "VAT_NUMBER", value: input.customerVatNumber }] }
      : { name: "Walk-in customer", identifiers: [{ type: "OTHER", value: "walk-in" }] },
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

function submitRequest(body: unknown, idempotencyKey: string): Request {
  return new Request("https://vat-msa.local/api/v1/invoices", {
    method: "POST",
    headers: { "content-type": "application/json", "idempotency-key": idempotencyKey },
    body: JSON.stringify(body),
  });
}

async function seedFixture(): Promise<void> {
  const db = env.DB;
  const now = "2026-08-01T00:00:00.000Z";
  await db.batch([
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-hard-seller", "VAT-HARD-SELLER-001", "TIN-HARD-SELLER-001", "Hardening Seller Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Seller Street", "finance@hard-seller.test", now),
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-hard-buyer", "VAT-HARD-BUYER-001", "TIN-HARD-BUYER-001", "Registered Buyer Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Buyer Street", "finance@hard-buyer.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-hard-seller", "tp-hard-seller", "Hardening Seller Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-hard-buyer", "tp-hard-buyer", "Registered Buyer Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO organisation_capabilities (id,organisation_id,capability,status,effective_from,effective_to,approved_by,created_at)
      VALUES (?,?,?,?,?,NULL,?,?)`).bind("cap-hard-seller", "org-hard-seller", "SELLER", "ACTIVE", now, "SYSTEM_BOOTSTRAP", now),
    db.prepare(`INSERT INTO organisation_capabilities (id,organisation_id,capability,status,effective_from,effective_to,approved_by,created_at)
      VALUES (?,?,?,?,?,NULL,?,?)`).bind("cap-hard-buyer", "org-hard-buyer", "BUYER", "ACTIVE", now, "SYSTEM_BOOTSTRAP", now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-sites-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(SELLER_OWNER.userId, SELLER_OWNER.externalUserId, SELLER_OWNER.email, "Seller Owner", "TAXPAYER_OWNER", "tp-hard-seller", "ACTIVE", now),
    db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
      VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${SELLER_OWNER.userId}`, SELLER_OWNER.userId, "idp-sites-workspace", SELLER_OWNER.externalUserId, SELLER_OWNER.email, "PILOT", "ACTIVE", now, now),
  ]);
}

describe("Module 2 invoice hardening (Phase E)", () => {
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

  describe("idempotency under concurrent retries", () => {
    it("never double-posts when the same request is submitted twice in flight together", async () => {
      const { POST } = await import("@/app/api/v1/invoices/route");
      actingAs(SELLER_OWNER);
      const idempotencyKey = crypto.randomUUID();
      const payload = invoicePayload({ sourceDocumentId: "race-doc-001", invoiceNumber: "INV-RACE-001" });

      const [responseA, responseB] = await Promise.all([
        POST(submitRequest(payload, idempotencyKey)),
        POST(submitRequest(payload, idempotencyKey)),
      ]);

      expect(responseA.status).not.toBe(500);
      expect(responseB.status).not.toBe(500);
      const bodyA = await responseA.json();
      const bodyB = await responseB.json();
      expect(bodyA.invoice_id).toBeTruthy();
      expect(bodyA.invoice_id).toBe(bodyB.invoice_id);

      const count = await env.DB.prepare("SELECT COUNT(*) AS n FROM invoices WHERE source_system=? AND source_document_id=?")
        .bind("TEST-ERP", "race-doc-001").first<{ n: number }>();
      expect(count?.n).toBe(1);
    });
  });

  describe("unidentified-buyer guarantee (P0)", () => {
    it("never posts an INPUT_VAT ledger entry for an unresolved buyer", async () => {
      const { POST } = await import("@/app/api/v1/invoices/route");
      actingAs(SELLER_OWNER);
      const response = await POST(submitRequest(
        invoicePayload({ sourceDocumentId: "unreg-buyer-doc-001", invoiceNumber: "INV-UNREG-001" }),
        crypto.randomUUID(),
      ));
      expect(response.status).toBe(201);
      const body = await response.json();

      const inputVatEntries = await env.DB.prepare("SELECT COUNT(*) AS n FROM ledger_entries WHERE invoice_id=? AND entry_type='INPUT_VAT'")
        .bind(body.invoice_id).first<{ n: number }>();
      expect(inputVatEntries?.n).toBe(0);

      const outputVatEntries = await env.DB.prepare("SELECT COUNT(*) AS n FROM ledger_entries WHERE invoice_id=? AND entry_type='OUTPUT_VAT'")
        .bind(body.invoice_id).first<{ n: number }>();
      expect(outputVatEntries?.n).toBe(1);
    });

    it("does post an INPUT_VAT ledger entry when the buyer resolves to an active BUYER-capable organisation", async () => {
      const { POST } = await import("@/app/api/v1/invoices/route");
      actingAs(SELLER_OWNER);
      const response = await POST(submitRequest(
        invoicePayload({ sourceDocumentId: "reg-buyer-doc-001", invoiceNumber: "INV-REG-001", customerVatNumber: "VAT-HARD-BUYER-001" }),
        crypto.randomUUID(),
      ));
      expect(response.status).toBe(201);
      const body = await response.json();

      const inputVatEntries = await env.DB.prepare("SELECT COUNT(*) AS n FROM ledger_entries WHERE invoice_id=? AND entry_type='INPUT_VAT'")
        .bind(body.invoice_id).first<{ n: number }>();
      expect(inputVatEntries?.n).toBe(1);
    });
  });
});
