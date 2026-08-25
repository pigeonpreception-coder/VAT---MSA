import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 3 Phase A: RunMatch, Assign and ResolveException, proven through
 * the real route handlers against a real invoice submission — not just the
 * pure evaluateInvoiceMatch unit tests (tests/reconciliation-domain.test.ts).
 * The EXCEPTION path is exercised by directly corrupting a ledger row after
 * a clean submission, simulating exactly the drift/tampering scenario an
 * independent verification pass exists to catch. See
 * tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 *
 * Also covers Phase B (GetWorkQueue): the filter/status/officer/age/
 * pagination predicates over reconciliation_exceptions, seeded with
 * directly-inserted fixture exceptions of controlled severity, status,
 * assignment and age so filtering is deterministic rather than depending on
 * whatever RunMatch happens to produce.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const SELLER_OWNER: FixtureUser = { userId: "usr-rec-seller-owner", externalUserId: "ext-rec-seller-owner", email: "owner@rec-seller.test" };
const SELLER_STAFF: FixtureUser = { userId: "usr-rec-seller-staff", externalUserId: "ext-rec-seller-staff", email: "staff@rec-seller.test" };
const NAMRA_OFFICER: FixtureUser = { userId: "usr-rec-namra", externalUserId: "ext-rec-namra", email: "namra@rec.test" };

function actingAs(user: FixtureUser): void {
  __setRequestHeaders({ "oai-authenticated-user-id": user.externalUserId, "oai-authenticated-user-email": user.email });
}

function invoicePayload(input: { sourceDocumentId: string; invoiceNumber: string }) {
  return {
    schema_version: "1.0.0",
    document_type: "TAX_INVOICE",
    source: { system_id: "TEST-ERP", document_id: input.sourceDocumentId, submitted_at: new Date().toISOString() },
    supplier: { name: "Reconciliation Seller Co", identifiers: [{ type: "VAT_NUMBER", value: "VAT-REC-SELLER-001" }] },
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

function matchRequest(id: string): [Request, { params: Promise<{ id: string }> }] {
  return [new Request("https://vat-msa.local/api/v1/invoices/x/match", { method: "POST" }), { params: Promise.resolve({ id }) }];
}

async function seedFixture(): Promise<void> {
  const db = env.DB;
  const now = "2026-08-01T00:00:00.000Z";
  await db.batch([
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-rec-seller", "VAT-REC-SELLER-001", "TIN-REC-SELLER-001", "Reconciliation Seller Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Seller Street", "finance@rec-seller.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-rec-seller", "tp-rec-seller", "Reconciliation Seller Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO organisation_capabilities (id,organisation_id,capability,status,effective_from,effective_to,approved_by,created_at)
      VALUES (?,?,?,?,?,NULL,?,?)`).bind("cap-rec-seller", "org-rec-seller", "SELLER", "ACTIVE", now, "SYSTEM_BOOTSTRAP", now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-sites-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(SELLER_OWNER.userId, SELLER_OWNER.externalUserId, SELLER_OWNER.email, "Seller Owner", "TAXPAYER_OWNER", "tp-rec-seller", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(SELLER_STAFF.userId, SELLER_STAFF.externalUserId, SELLER_STAFF.email, "Seller Staff", "TAXPAYER_STAFF", "tp-rec-seller", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_OFFICER.userId, NAMRA_OFFICER.externalUserId, NAMRA_OFFICER.email, "NamRA Officer", "NAMRA_COMPLIANCE_OFFICER", null, "ACTIVE", now),
    ...[SELLER_OWNER, SELLER_STAFF, NAMRA_OFFICER].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-sites-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
  ]);
}

describe("Module 3 reconciliation matching engine (Phase A)", () => {
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

  it("denies a TAXPAYER_STAFF actor running a match (officer-only)", async () => {
    const { POST: submitPOST } = await import("@/app/api/v1/invoices/route");
    actingAs(SELLER_OWNER);
    const submitResponse = await submitPOST(submitRequest(invoicePayload({ sourceDocumentId: "rec-perm-doc", invoiceNumber: "INV-REC-PERM" })));
    const submitted = await submitResponse.json();

    const { POST: matchPOST } = await import("@/app/api/v1/invoices/[id]/match/route");
    actingAs(SELLER_STAFF);
    const [request, context] = matchRequest(submitted.invoice_id);
    const response = await matchPOST(request, context);
    expect(response.status).toBe(403);
  });

  it("matches a cleanly certified invoice and is idempotent on retry", async () => {
    const { POST: submitPOST } = await import("@/app/api/v1/invoices/route");
    actingAs(SELLER_OWNER);
    const submitResponse = await submitPOST(submitRequest(invoicePayload({ sourceDocumentId: "rec-clean-doc", invoiceNumber: "INV-REC-CLEAN" })));
    const submitted = await submitResponse.json();

    const { POST: matchPOST } = await import("@/app/api/v1/invoices/[id]/match/route");
    actingAs(NAMRA_OFFICER);
    const [firstRequest, firstContext] = matchRequest(submitted.invoice_id);
    const first = await (await matchPOST(firstRequest, firstContext)).json();
    expect(first.match).toMatchObject({ status: "MATCHED", invoiceId: submitted.invoice_id, mismatches: [] });

    const [secondRequest, secondContext] = matchRequest(submitted.invoice_id);
    const second = await (await matchPOST(secondRequest, secondContext)).json();
    expect(second.match.id).toBe(first.match.id);

    const count = await env.DB.prepare("SELECT COUNT(*) AS n FROM reconciliation_matches WHERE invoice_id=?").bind(submitted.invoice_id).first<{ n: number }>();
    expect(count?.n).toBe(1);
  });

  it("raises an exception when the posted ledger entry has drifted from the invoice's declared tax", async () => {
    const { POST: submitPOST } = await import("@/app/api/v1/invoices/route");
    actingAs(SELLER_OWNER);
    const submitResponse = await submitPOST(submitRequest(invoicePayload({ sourceDocumentId: "rec-drift-doc", invoiceNumber: "INV-REC-DRIFT" })));
    const submitted = await submitResponse.json();

    // Simulate drift/tampering: corrupt the OUTPUT_VAT ledger entry directly.
    await env.DB.prepare("UPDATE ledger_entries SET amount_cents=999 WHERE invoice_id=? AND entry_type='OUTPUT_VAT'").bind(submitted.invoice_id).run();

    const { POST: matchPOST } = await import("@/app/api/v1/invoices/[id]/match/route");
    actingAs(NAMRA_OFFICER);
    const [request, context] = matchRequest(submitted.invoice_id);
    const response = await matchPOST(request, context);
    const body = await response.json();
    expect(body.match).toMatchObject({ status: "EXCEPTION" });
    expect(body.match.mismatches[0]).toMatch(/does not equal/);

    const exception = await env.DB.prepare("SELECT id, status FROM reconciliation_exceptions WHERE invoice_id=? AND exception_type='LEDGER_MISMATCH'")
      .bind(submitted.invoice_id).first<{ id: string; status: string }>();
    expect(exception?.status).toBe("OPEN");

    const { POST: assignPOST } = await import("@/app/api/v1/exceptions/[id]/assignment/route");
    actingAs(NAMRA_OFFICER);
    const assignResponse = await assignPOST(
      new Request("https://vat-msa.local/api/v1/exceptions/x/assignment", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ officer_id: NAMRA_OFFICER.userId }) }),
      { params: Promise.resolve({ id: exception!.id }) },
    );
    expect(assignResponse.status).toBe(200);
    expect((await assignResponse.json()).assignment).toEqual({ id: exception!.id, status: "ASSIGNED" });

    const { POST: resolvePOST } = await import("@/app/api/v1/exceptions/[id]/resolution/route");
    actingAs(NAMRA_OFFICER);
    const resolveResponse = await resolvePOST(
      new Request("https://vat-msa.local/api/v1/exceptions/x/resolution", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ notes: "Confirmed a manual data correction; re-posted the correct ledger amount." }) }),
      { params: Promise.resolve({ id: exception!.id }) },
    );
    expect(resolveResponse.status).toBe(200);
    expect((await resolveResponse.json()).resolution).toEqual({ id: exception!.id, status: "RESOLVED" });

    const secondResolve = await resolvePOST(
      new Request("https://vat-msa.local/api/v1/exceptions/x/resolution", { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ notes: "Resolving again to confirm idempotency." }) }),
      { params: Promise.resolve({ id: exception!.id }) },
    );
    expect(secondResolve.status).toBe(200);
  });

  describe("GetWorkQueue (Phase B)", () => {
    async function workQueueRequest(query: string) {
      const { GET } = await import("@/app/api/v1/exceptions/route");
      return GET(new Request(`https://vat-msa.local/api/v1/exceptions${query}`));
    }

    beforeAll(async () => {
      const db = env.DB;
      const now = "2026-08-01T00:00:00.000Z";
      // A second taxpayer, scoped away from the seller fixture, to prove
      // GetWorkQueue's tenant scoping — not just its filter predicates.
      await db.batch([
        db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
          VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-wq-other", "VAT-WQ-OTHER-001", "TIN-WQ-OTHER-001", "Other Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Other Street", "finance@wq-other.test", now),
        db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
          .bind("org-wq-other", "tp-wq-other", "Other Co (Pty) Ltd", null, "ACTIVE", now, now),
      ]);

      const { POST: submitPOST } = await import("@/app/api/v1/invoices/route");
      actingAs(SELLER_OWNER);
      const submissions = await Promise.all([
        submitPOST(submitRequest(invoicePayload({ sourceDocumentId: "wq-doc-1", invoiceNumber: "INV-WQ-1" }))),
        submitPOST(submitRequest(invoicePayload({ sourceDocumentId: "wq-doc-2", invoiceNumber: "INV-WQ-2" }))),
        submitPOST(submitRequest(invoicePayload({ sourceDocumentId: "wq-doc-3", invoiceNumber: "INV-WQ-3" }))),
      ]);
      const [invoiceRecent, invoiceMid, invoiceOld] = await Promise.all(submissions.map((response) => response.json()));

      await db.batch([
        // 1 day old, CRITICAL, unassigned.
        db.prepare("INSERT INTO reconciliation_exceptions VALUES (?,?,?,?,?,?,?,?,NULL,NULL,NULL,NULL)").bind(
          "wq-exc-recent", invoiceRecent.invoice_id, "tp-rec-seller", "LEDGER_MISMATCH", "CRITICAL", "OPEN", "Recent critical exception.", "2026-08-24T00:00:00.000Z",
        ),
        // 10 days old, MEDIUM, assigned to NAMRA_OFFICER.
        db.prepare("INSERT INTO reconciliation_exceptions VALUES (?,?,?,?,?,?,?,?,NULL,?,NULL,NULL)").bind(
          "wq-exc-mid", invoiceMid.invoice_id, "tp-rec-seller", "LEDGER_MISMATCH", "MEDIUM", "ASSIGNED", "Mid-aged assigned exception.", "2026-08-15T00:00:00.000Z", NAMRA_OFFICER.userId,
        ),
        // 40 days old, LOW, unassigned.
        db.prepare("INSERT INTO reconciliation_exceptions VALUES (?,?,?,?,?,?,?,?,NULL,NULL,NULL,NULL)").bind(
          "wq-exc-old", invoiceOld.invoice_id, "tp-rec-seller", "LEDGER_MISMATCH", "LOW", "OPEN", "Old unassigned exception.", "2026-07-16T00:00:00.000Z",
        ),
        // Belongs to a different taxpayer entirely.
        db.prepare(`INSERT INTO invoices VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(
          "inv-wq-other", "INV-WQ-OTHER", "TAX_INVOICE", "TEST-ERP", "wq-doc-other", "tp-wq-other", "Other Co (Pty) Ltd", "VAT-WQ-OTHER-001",
          null, "Walk-in customer", null, "2026-08-25", "NAD", 10_000, 1_500, 11_500, "CERTIFIED", "LOW",
          "hash", "txn-wq-other", "cert-wq-other", "vfy_wq_other", now, now,
        ),
        db.prepare("INSERT INTO reconciliation_exceptions VALUES (?,?,?,?,?,?,?,?,NULL,NULL,NULL,NULL)").bind(
          "wq-exc-other-taxpayer", "inv-wq-other", "tp-wq-other", "LEDGER_MISMATCH", "HIGH", "OPEN", "Different taxpayer's exception.", "2026-08-20T00:00:00.000Z",
        ),
      ]);
    });

    it("scopes results to the caller's own taxpayer", async () => {
      actingAs(SELLER_OWNER);
      const response = await workQueueRequest("");
      const body = await response.json();
      const ids = body.workQueue.items.map((item: { id: string }) => item.id);
      expect(ids).toEqual(expect.arrayContaining(["wq-exc-recent", "wq-exc-mid", "wq-exc-old"]));
      expect(ids).not.toContain("wq-exc-other-taxpayer");
    });

    it("shows every taxpayer's exceptions to a national-scope NamRA actor", async () => {
      actingAs(NAMRA_OFFICER);
      const response = await workQueueRequest("");
      const body = await response.json();
      const ids = body.workQueue.items.map((item: { id: string }) => item.id);
      expect(ids).toContain("wq-exc-other-taxpayer");
    });

    it("filters by severity", async () => {
      actingAs(NAMRA_OFFICER);
      const body = await (await workQueueRequest("?severity=CRITICAL")).json();
      expect(body.workQueue.items.map((item: { id: string }) => item.id)).toEqual(["wq-exc-recent"]);
    });

    it("filters by unassigned_only and by assigned_officer_id", async () => {
      actingAs(NAMRA_OFFICER);
      const unassigned = await (await workQueueRequest("?unassigned_only=true")).json();
      const unassignedIds = unassigned.workQueue.items.map((item: { id: string }) => item.id);
      expect(unassignedIds).not.toContain("wq-exc-mid");
      expect(unassignedIds).toEqual(expect.arrayContaining(["wq-exc-recent", "wq-exc-old"]));

      const assigned = await (await workQueueRequest(`?assigned_officer_id=${NAMRA_OFFICER.userId}`)).json();
      expect(assigned.workQueue.items.map((item: { id: string }) => item.id)).toContain("wq-exc-mid");
      expect(assigned.workQueue.items[0].assignedOfficerName).toBe("NamRA Officer");
    });

    it("filters by age range", async () => {
      actingAs(SELLER_OWNER);
      const midOnly = await (await workQueueRequest("?min_age_days=5&max_age_days=15")).json();
      expect(midOnly.workQueue.items.map((item: { id: string }) => item.id)).toEqual(["wq-exc-mid"]);
    });

    it("paginates with a real totalCount independent of the page size", async () => {
      actingAs(SELLER_OWNER);
      const page = await (await workQueueRequest("?limit=1&offset=0")).json();
      expect(page.workQueue.items).toHaveLength(1);
      expect(page.workQueue.totalCount).toBeGreaterThanOrEqual(3);
      expect(page.workQueue.limit).toBe(1);
      expect(page.workQueue.offset).toBe(0);
    });

    it("rejects a malformed filter", async () => {
      actingAs(NAMRA_OFFICER);
      const response = await workQueueRequest("?severity=EXTREME");
      expect(response.status).toBe(422);
    });
  });
});
