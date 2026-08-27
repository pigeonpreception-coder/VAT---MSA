import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 2 Phase A: proves the VAT rule engine actually gates invoice
 * certification end-to-end, through the real route handlers — not just that
 * the pure normalize* functions accept/reject the right shapes (that's
 * tests/vat-rules-domain.test.ts). See tests/routes/module-1-access-control.test.ts
 * for why this needs the cloudflare:workers/next/headers fakes and the
 * node:sqlite-backed fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const SELLER_OWNER: FixtureUser = { userId: "usr-seller-owner", externalUserId: "ext-seller-owner", email: "owner@seller.test" };
const NAMRA_1: FixtureUser = { userId: "usr-namra-1", externalUserId: "ext-namra-1", email: "namra-1@example.test" };
const NAMRA_2: FixtureUser = { userId: "usr-namra-2", externalUserId: "ext-namra-2", email: "namra-2@example.test" };

/** Also grants a fresh, server-verified step-up (step_up_events row) for the acting user — ProposeVatRule/ApproveVatRule are step-up gated and there is no longer a header shortcut around lib/security/step-up.ts's real requireStepUp check. */
async function actingAs(user: FixtureUser): Promise<void> {
  __setRequestHeaders({ "oai-authenticated-user-id": user.externalUserId, "oai-authenticated-user-email": user.email });
  await env.DB.prepare("INSERT INTO step_up_events (id,user_id,method,verified_at,expires_at) VALUES (?,?,?,?,?)")
    .bind(crypto.randomUUID(), user.userId, "TOTP", new Date().toISOString(), new Date(Date.now() + 5 * 60_000).toISOString()).run();
}

function invoicePayload(overrides: { rate: string; taxAmount: string; category?: string }) {
  const category = overrides.category ?? "STANDARD";
  return {
    schema_version: "1.0.0",
    document_type: "TAX_INVOICE",
    source: { system_id: "TEST-ERP", document_id: `doc-${crypto.randomUUID()}`, submitted_at: new Date().toISOString() },
    supplier: { name: "Seller Co", identifiers: [{ type: "VAT_NUMBER", value: "VAT-SELLER-001" }] },
    customer: { name: "Walk-in customer", identifiers: [{ type: "OTHER", value: "walk-in" }] },
    invoice_number: `INV-${crypto.randomUUID().slice(0, 8)}`,
    issue_date: "2026-08-25",
    currency: "NAD",
    lines: [{
      line_number: 1, description: "Consulting services", quantity: "1", unit_code: "EA",
      unit_price: "100.00", net_amount: "100.00",
      tax: { category, rate: overrides.rate, taxable_amount: "100.00", tax_amount: overrides.taxAmount },
    }],
    totals: {
      line_net_amount: "100.00", tax_exclusive_amount: "100.00",
      tax_amount: overrides.taxAmount, tax_inclusive_amount: (100 + Number(overrides.taxAmount)).toFixed(2),
      payable_amount: (100 + Number(overrides.taxAmount)).toFixed(2),
    },
  };
}

function submitRequest(body: unknown): Request {
  return new Request("https://vat-msa.local/api/v1/invoices", {
    method: "POST",
    headers: { "content-type": "application/json", "idempotency-key": crypto.randomUUID() },
    body: JSON.stringify(body),
  });
}

async function seedFixture(): Promise<void> {
  const db = env.DB;
  const now = "2026-08-01T00:00:00.000Z";
  await db.batch([
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-seller", "VAT-SELLER-001", "TIN-SELLER-001", "Seller Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Seller Street", "finance@seller.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-seller", "tp-seller", "Seller Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO organisation_capabilities (id,organisation_id,capability,status,effective_from,effective_to,approved_by,created_at)
      VALUES (?,?,?,?,?,NULL,?,?)`).bind("cap-seller", "org-seller", "SELLER", "ACTIVE", now, "SYSTEM_BOOTSTRAP", now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-sites-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(SELLER_OWNER.userId, SELLER_OWNER.externalUserId, SELLER_OWNER.email, "Seller Owner", "TAXPAYER_OWNER", "tp-seller", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_1.userId, NAMRA_1.externalUserId, NAMRA_1.email, "NamRA One", "PILOT_ADMIN", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_2.userId, NAMRA_2.externalUserId, NAMRA_2.email, "NamRA Two", "PILOT_ADMIN", null, "ACTIVE", now),
    ...[SELLER_OWNER, NAMRA_1, NAMRA_2].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-sites-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
  ]);
}

describe("Module 2 route-level VAT rule engine (Phase A)", () => {
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

  describe("invoice submission against the approved rate", () => {
    it("rejects a STANDARD line taxed at a rate that doesn't match the approved rule", async () => {
      const { POST } = await import("@/app/api/v1/invoices/route");
      await actingAs(SELLER_OWNER);
      const response = await POST(submitRequest(invoicePayload({ rate: "20.00", taxAmount: "20.00" })));
      expect(response.status).toBe(422);
      const body = await response.json();
      expect(body.errors?.[0]?.code).toBe("VAT_RATE_RULE_MISMATCH");
    });

    it("rejects an OTHER-category line entirely, since no rule is approved for it (fails closed)", async () => {
      const { POST } = await import("@/app/api/v1/invoices/route");
      await actingAs(SELLER_OWNER);
      const response = await POST(submitRequest(invoicePayload({ rate: "0.00", taxAmount: "0.00", category: "OTHER" })));
      expect(response.status).toBe(422);
      const body = await response.json();
      expect(body.errors?.[0]?.code).toBe("NO_APPROVED_VAT_RULE");
    });

    it("certifies a STANDARD line taxed at the approved 15% rate, traceable via ExplainCalculation", async () => {
      const { POST } = await import("@/app/api/v1/invoices/route");
      await actingAs(SELLER_OWNER);
      const response = await POST(submitRequest(invoicePayload({ rate: "15.00", taxAmount: "15.00" })));
      expect(response.status).toBe(201);
      const body = await response.json();
      expect(body.vat_rules_applied).toEqual([{ tax_category: "STANDARD", vat_rule_id: "vrule-standard-na", vat_rule_version: 1 }]);

      const { GET } = await import("@/app/api/v1/invoices/[id]/vat-explanation/route");
      const explainResponse = await GET(new Request(`https://vat-msa.local/api/v1/invoices/${body.invoice_id}/vat-explanation`), { params: Promise.resolve({ id: body.invoice_id }) });
      expect(explainResponse.status).toBe(200);
      const explanation = await explainResponse.json();
      expect(explanation.lines).toEqual([expect.objectContaining({ taxCategory: "STANDARD", vatRuleId: "vrule-standard-na", vatRuleVersion: 1 })]);
    });
  });

  describe("ProposeVatRule / ApproveVatRule segregation of duties", () => {
    it("denies the proposing officer approving their own draft", async () => {
      const { POST: proposePOST } = await import("@/app/api/v1/vat-rules/route");
      await actingAs(NAMRA_1);
      const proposeResponse = await proposePOST(new Request("https://vat-msa.local/api/v1/vat-rules", {
        method: "POST",
        headers: {
          "content-type": "application/json",
          "idempotency-key": crypto.randomUUID(),
        },
        body: JSON.stringify({ tax_category: "STANDARD", rate_bps: 1600, effective_from: "2027-01-01", reason: "Statutory rate increase per the 2027 budget speech." }),
      }));
      expect(proposeResponse.status).toBe(201);
      const proposed = (await proposeResponse.json()).rule;

      const { POST: approvePOST } = await import("@/app/api/v1/vat-rules/[id]/approval/route");
      await actingAs(NAMRA_1);
      const selfApproveResponse = await approvePOST(new Request(`https://vat-msa.local/api/v1/vat-rules/${proposed.id}/approval`, {
        method: "POST",
        headers: {
          "content-type": "application/json",
          "idempotency-key": crypto.randomUUID(),
        },
        body: JSON.stringify({ reason: "Approving my own proposal." }),
      }), { params: Promise.resolve({ id: proposed.id }) });
      expect(selfApproveResponse.status).toBe(422);
      const selfApproveBody = await selfApproveResponse.json();
      expect(selfApproveBody.errors?.[0]?.code).toBe("SELF_APPROVAL_DENIED");

      await actingAs(NAMRA_2);
      const approveResponse = await approvePOST(new Request(`https://vat-msa.local/api/v1/vat-rules/${proposed.id}/approval`, {
        method: "POST",
        headers: {
          "content-type": "application/json",
          "idempotency-key": crypto.randomUUID(),
        },
        body: JSON.stringify({ reason: "Verified against the published 2027 budget speech." }),
      }), { params: Promise.resolve({ id: proposed.id }) });
      expect(approveResponse.status).toBe(200);
      expect((await approveResponse.json()).rule.status).toBe("APPROVED");
    });
  });

  /**
   * Security fix 2026-08-27 (SECURITY_GAP_ASSESSMENT.md item #8): this
   * route family previously had zero rate limiting and zero idempotency-key
   * protection at all. Proven here against the real routes, not just that
   * a header is now required (already covered by the two requests above
   * gaining an idempotency-key).
   */
  describe("SECURITY_GAP_ASSESSMENT.md item #8: rate limiting and idempotency", () => {
    it("ProposeVatRule is idempotent under a repeated key — a retried request returns the same rule, not a duplicate", async () => {
      const { POST } = await import("@/app/api/v1/vat-rules/route");
      await actingAs(NAMRA_1);
      const key = crypto.randomUUID();
      const body = JSON.stringify({ tax_category: "STANDARD", rate_bps: 1700, effective_from: "2028-01-01", reason: "Idempotency replay test." });
      const first = await POST(new Request("https://vat-msa.local/api/v1/vat-rules", { method: "POST", headers: { "content-type": "application/json", "idempotency-key": key }, body }));
      const second = await POST(new Request("https://vat-msa.local/api/v1/vat-rules", { method: "POST", headers: { "content-type": "application/json", "idempotency-key": key }, body }));
      expect(first.status).toBe(201);
      expect(second.status).toBe(201);
      const firstRule = (await first.json()).rule;
      const secondRule = (await second.json()).rule;
      expect(secondRule.id).toBe(firstRule.id);

      const count = await env.DB.prepare("SELECT COUNT(*) AS n FROM vat_rules WHERE tax_category='STANDARD' AND version=?").bind(firstRule.version).first<{ n: number }>();
      expect(count?.n).toBe(1);
    });

    it("refuses a repeated idempotency key reused with a different payload", async () => {
      const { POST } = await import("@/app/api/v1/vat-rules/route");
      await actingAs(NAMRA_1);
      const key = crypto.randomUUID();
      const first = await POST(new Request("https://vat-msa.local/api/v1/vat-rules", { method: "POST", headers: { "content-type": "application/json", "idempotency-key": key }, body: JSON.stringify({ tax_category: "ZERO_RATED", rate_bps: 0, effective_from: "2028-02-01", reason: "First payload for key-reuse test." }) }));
      expect(first.status).toBe(201);
      const second = await POST(new Request("https://vat-msa.local/api/v1/vat-rules", { method: "POST", headers: { "content-type": "application/json", "idempotency-key": key }, body: JSON.stringify({ tax_category: "ZERO_RATED", rate_bps: 0, effective_from: "2028-03-01", reason: "Different payload, same key." }) }));
      expect(second.status).toBe(409);
    });

    it("rejects a malformed (too-short) idempotency key with a validation error", async () => {
      const { POST } = await import("@/app/api/v1/vat-rules/route");
      await actingAs(NAMRA_1);
      const response = await POST(new Request("https://vat-msa.local/api/v1/vat-rules", { method: "POST", headers: { "content-type": "application/json", "idempotency-key": "short" }, body: JSON.stringify({ tax_category: "EXEMPT", rate_bps: 0, effective_from: "2028-04-01", reason: "Malformed key test." }) }));
      expect(response.status).toBe(422);
    });

    it("enforces the per-actor rate limit on ProposeVatRule and reports it as a genuine RATE_LIMIT_EXCEEDED problem", async () => {
      const { POST } = await import("@/app/api/v1/vat-rules/route");
      await actingAs(NAMRA_2);
      let lastStatus = 0;
      for (let attempt = 0; attempt < 31; attempt += 1) {
        const response = await POST(new Request("https://vat-msa.local/api/v1/vat-rules", {
          method: "POST",
          headers: { "content-type": "application/json", "idempotency-key": crypto.randomUUID() },
          body: JSON.stringify({ tax_category: "EXEMPT", rate_bps: 0, effective_from: `2029-${String((attempt % 12) + 1).padStart(2, "0")}-01`, reason: `Rate limit probe attempt ${attempt}.` }),
        }));
        lastStatus = response.status;
        if (response.status === 429) {
          const body = await response.json();
          expect(body.code).toBe("RATE_LIMIT_EXCEEDED");
          break;
        }
      }
      expect(lastStatus).toBe(429);
    });
  });
});
