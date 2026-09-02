import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 4 Phase A: EvaluateRisk and GetRestrictedRisk, proven through the
 * real route handlers (app/api/v1/taxpayers/[id]/risk-evaluation and
 * app/api/v1/risk-indicators, both dispatched via lib/api/compliance.ts)
 * and lib/data/compliance-repository.ts's evaluateRisk/getRestrictedRisk.
 * See tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const TAXPAYER_OWNER: FixtureUser = { userId: "usr-re-taxpayer-owner", externalUserId: "ext-re-taxpayer-owner", email: "owner@re-taxpayer.test" };
const NAMRA_OFFICER: FixtureUser = { userId: "usr-re-namra", externalUserId: "ext-re-namra", email: "namra@re.test" };
const NAMRA_SUPERVISOR: FixtureUser = { userId: "usr-re-supervisor", externalUserId: "ext-re-supervisor", email: "supervisor@re.test" };
const NAMRA_REFUND_OFFICER: FixtureUser = { userId: "usr-re-refund", externalUserId: "ext-re-refund", email: "refund@re.test" };

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
    // High-value-pattern taxpayer: two active invoices independently scored HIGH/CRITICAL risk at submission time.
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-re-highvalue", "VAT-RE-001", "TIN-RE-001", "High Value Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Risk Eval Street", "finance@re-highvalue.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-re-highvalue", "tp-re-highvalue", "High Value Co (Pty) Ltd", null, "ACTIVE", now, now),
    // A clean taxpayer: no invoices, no exceptions, no obligations — no rule should fire.
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-re-clean", "VAT-RE-002", "TIN-RE-002", "Clean Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "2 Risk Eval Street", "finance@re-clean.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-re-clean", "tp-re-clean", "Clean Co (Pty) Ltd", null, "ACTIVE", now, now),
    // A backlog taxpayer: three unresolved reconciliation exceptions.
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-re-backlog", "VAT-RE-003", "TIN-RE-003", "Backlog Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "3 Risk Eval Street", "finance@re-backlog.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-re-backlog", "tp-re-backlog", "Backlog Co (Pty) Ltd", null, "ACTIVE", now, now),
    // An overdue-obligation taxpayer.
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-re-overdue", "VAT-RE-004", "TIN-RE-004", "Overdue Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "4 Risk Eval Street", "finance@re-overdue.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-re-overdue", "tp-re-overdue", "Overdue Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-re-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(TAXPAYER_OWNER.userId, TAXPAYER_OWNER.externalUserId, TAXPAYER_OWNER.email, "Taxpayer Owner", "TAXPAYER_OWNER", "tp-re-highvalue", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_OFFICER.userId, NAMRA_OFFICER.externalUserId, NAMRA_OFFICER.email, "NamRA Officer", "NAMRA_COMPLIANCE_OFFICER", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_SUPERVISOR.userId, NAMRA_SUPERVISOR.externalUserId, NAMRA_SUPERVISOR.email, "NamRA Supervisor", "NAMRA_SUPERVISOR", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_REFUND_OFFICER.userId, NAMRA_REFUND_OFFICER.externalUserId, NAMRA_REFUND_OFFICER.email, "NamRA Refund Officer", "NAMRA_REFUND_OFFICER", null, "ACTIVE", now),
    ...[TAXPAYER_OWNER, NAMRA_OFFICER, NAMRA_SUPERVISOR, NAMRA_REFUND_OFFICER].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-re-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
    // Two active, HIGH-risk-scored invoices for tp-re-highvalue.
    db.prepare(`INSERT INTO invoices
      (id,invoice_number,document_type,source_system,source_document_id,supplier_taxpayer_id,supplier_name,supplier_vat_number,customer_taxpayer_id,customer_name,customer_vat_number,issue_date,currency,line_net_cents,tax_cents,total_cents,status,risk_level,payload_hash,transaction_id,certificate_id,verification_token,created_at,certified_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`)
      .bind("inv-re-1", "INV-RE-0001", "TAX_INVOICE", "PILOT", "doc-re-1", "tp-re-highvalue", "High Value Co (Pty) Ltd", "VAT-RE-001", null, "Cash sale", null, "2026-07-01", "NAD", 100_000_00, 15_000_00, 115_000_00, "EXCEPTION", "HIGH", "hash-re-1", "txn-re-1", "cert-re-1", "verify-re-1", now, now),
    db.prepare(`INSERT INTO invoices
      (id,invoice_number,document_type,source_system,source_document_id,supplier_taxpayer_id,supplier_name,supplier_vat_number,customer_taxpayer_id,customer_name,customer_vat_number,issue_date,currency,line_net_cents,tax_cents,total_cents,status,risk_level,payload_hash,transaction_id,certificate_id,verification_token,created_at,certified_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`)
      .bind("inv-re-2", "INV-RE-0002", "TAX_INVOICE", "PILOT", "doc-re-2", "tp-re-highvalue", "High Value Co (Pty) Ltd", "VAT-RE-001", null, "Cash sale", null, "2026-07-02", "NAD", 200_000_00, 30_000_00, 230_000_00, "EXCEPTION", "CRITICAL", "hash-re-2", "txn-re-2", "cert-re-2", "verify-re-2", now, now),
    // Three unresolved reconciliation exceptions for tp-re-backlog.
    db.prepare(`INSERT INTO invoices
      (id,invoice_number,document_type,source_system,source_document_id,supplier_taxpayer_id,supplier_name,supplier_vat_number,customer_taxpayer_id,customer_name,customer_vat_number,issue_date,currency,line_net_cents,tax_cents,total_cents,status,risk_level,payload_hash,transaction_id,certificate_id,verification_token,created_at,certified_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`)
      .bind("inv-re-3", "INV-RE-0003", "TAX_INVOICE", "PILOT", "doc-re-3", "tp-re-backlog", "Backlog Co (Pty) Ltd", "VAT-RE-003", null, "Cash sale", null, "2026-07-03", "NAD", 1_000_00, 150_00, 1_150_00, "MATCHED", "LOW", "hash-re-3", "txn-re-3", "cert-re-3", "verify-re-3", now, now),
    ...["exc-re-1", "exc-re-2", "exc-re-3"].map((excId) =>
      db.prepare("INSERT INTO reconciliation_exceptions VALUES (?,?,?,?,?,?,?,?,NULL,NULL,NULL,NULL)")
        .bind(excId, "inv-re-3", "tp-re-backlog", "LEDGER_MISMATCH", "HIGH", "OPEN", "Ledger mismatch detected.", now)),
    // One overdue PENDING obligation for tp-re-overdue.
    db.prepare(`INSERT INTO tax_obligations
      (id,organisation_id,taxpayer_id,obligation_type,period_code,due_date,amount_cents,currency,status,source_system,source_reference,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)`)
      .bind("ob-re-1", "org-re-overdue", "tp-re-overdue", "VAT_RETURN", "2026-01", "2026-02-25", 500_000, "NAD", "PENDING", "VAT_MSA", null, now, now),
  ]);
}

async function evaluate(taxpayerId: string, actor: FixtureUser = NAMRA_OFFICER, key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/taxpayers/[id]/risk-evaluation/route");
  actingAs(actor);
  return POST(
    jsonRequest(`https://vat-msa.local/api/v1/taxpayers/${taxpayerId}/risk-evaluation`, { schema_version: "1.0.0" }, key),
    { params: Promise.resolve({ id: taxpayerId }) },
  );
}

describe("Module 4 risk evaluation engine (Phase A)", () => {
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

  it("raises HIGH_VALUE_INVOICE_PATTERN for a taxpayer with two active HIGH/CRITICAL-scored invoices", async () => {
    const response = await evaluate("tp-re-highvalue");
    expect(response.status).toBe(200);
    const body = await response.json();
    const factor = body.resource.factors.find((f: { indicator_code: string }) => f.indicator_code === "HIGH_VALUE_INVOICE_PATTERN");
    expect(factor.fired).toBe(true);
    expect(factor.rationale).toContain("2 active invoice");
    const indicator = body.resource.indicators.find((i: { indicator_code: string }) => i.indicator_code === "HIGH_VALUE_INVOICE_PATTERN");
    expect(indicator.status).toBe("OPEN");
    expect(indicator.decision_effect).toBe("ADVISORY_ONLY");
  });

  it("returns all factors, including non-firing ones, for a clean taxpayer — never a bare score", async () => {
    const response = await evaluate("tp-re-clean");
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.resource.factors).toHaveLength(3);
    expect(body.resource.factors.every((f: { fired: boolean }) => f.fired === false)).toBe(true);
    expect(body.resource.indicators).toHaveLength(0);
  });

  it("raises RECONCILIATION_EXCEPTION_BACKLOG for a taxpayer with 3+ unresolved exceptions", async () => {
    const response = await evaluate("tp-re-backlog");
    expect(response.status).toBe(200);
    const body = await response.json();
    const factor = body.resource.factors.find((f: { indicator_code: string }) => f.indicator_code === "RECONCILIATION_EXCEPTION_BACKLOG");
    expect(factor.fired).toBe(true);
    expect(factor.rationale).toContain("3 reconciliation exception");
  });

  it("raises OBLIGATION_OVERDUE for a taxpayer with a PENDING obligation past its due date", async () => {
    const response = await evaluate("tp-re-overdue");
    expect(response.status).toBe(200);
    const body = await response.json();
    const factor = body.resource.factors.find((f: { indicator_code: string }) => f.indicator_code === "OBLIGATION_OVERDUE");
    expect(factor.fired).toBe(true);
  });

  it("is idempotent at the row level: re-evaluating an OPEN indicator refreshes it rather than duplicating it", async () => {
    const first = await evaluate("tp-re-highvalue", NAMRA_OFFICER, crypto.randomUUID());
    const firstBody = await first.json();
    const firstIndicator = firstBody.resource.indicators.find((i: { indicator_code: string }) => i.indicator_code === "HIGH_VALUE_INVOICE_PATTERN");

    const second = await evaluate("tp-re-highvalue", NAMRA_SUPERVISOR, crypto.randomUUID());
    const secondBody = await second.json();
    const secondIndicator = secondBody.resource.indicators.find((i: { indicator_code: string }) => i.indicator_code === "HIGH_VALUE_INVOICE_PATTERN");

    expect(secondIndicator.id).toBe(firstIndicator.id);
  });

  it("leaves an indicator alone once it is no longer OPEN (human-owned) rather than overwriting it", async () => {
    const evalResponse = await evaluate("tp-re-backlog", NAMRA_OFFICER, crypto.randomUUID());
    const evalBody = await evalResponse.json();
    const indicatorId = evalBody.resource.indicators.find((i: { indicator_code: string }) => i.indicator_code === "RECONCILIATION_EXCEPTION_BACKLOG").id as string;

    const { POST: assignPOST } = await import("@/app/api/v1/risk-indicators/[id]/assignment/route");
    actingAs(NAMRA_OFFICER);
    const assignResponse = await assignPOST(
      jsonRequest(`https://vat-msa.local/api/v1/risk-indicators/${indicatorId}/assignment`, { schema_version: "1.0.0", officer_id: NAMRA_OFFICER.userId }, crypto.randomUUID()),
      { params: Promise.resolve({ id: indicatorId }) },
    );
    expect(assignResponse.status).toBe(200);

    const reEvalResponse = await evaluate("tp-re-backlog", NAMRA_SUPERVISOR, crypto.randomUUID());
    const reEvalBody = await reEvalResponse.json();
    const touchedIndicator = reEvalBody.resource.indicators.find((i: { id: string }) => i.id === indicatorId);
    expect(touchedIndicator.status).toBe("UNDER_REVIEW");
    expect(touchedIndicator.assigned_officer_id).toBe(NAMRA_OFFICER.userId);
  });

  it("denies a taxpayer-side actor evaluating risk (risk:review is a national-scope permission)", async () => {
    const response = await evaluate("tp-re-highvalue", TAXPAYER_OWNER);
    expect(response.status).toBe(403);
  });

  it("returns 404 for a non-existent taxpayer", async () => {
    const response = await evaluate(crypto.randomUUID());
    expect(response.status).toBe(404);
  });
});

describe("Module 4 GetRestrictedRisk (Phase A)", () => {
  it("returns risk indicators to a national-scope risk:read actor, filterable by taxpayer_id/status/severity", async () => {
    const { GET } = await import("@/app/api/v1/risk-indicators/route");
    actingAs(NAMRA_REFUND_OFFICER);
    const response = await GET(new Request("https://vat-msa.local/api/v1/risk-indicators?taxpayer_id=tp-re-highvalue"));
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.items.length).toBeGreaterThan(0);
    expect(body.items.every((item: { taxpayer_id: string }) => item.taxpayer_id === "tp-re-highvalue")).toBe(true);
    expect(typeof body.totalCount).toBe("number");
  });

  it("filters by status and severity", async () => {
    const { GET } = await import("@/app/api/v1/risk-indicators/route");
    actingAs(NAMRA_SUPERVISOR);
    const response = await GET(new Request("https://vat-msa.local/api/v1/risk-indicators?status=OPEN&severity=HIGH"));
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.items.every((item: { status: string; severity: string }) => item.status === "OPEN" && item.severity === "HIGH")).toBe(true);
  });

  it("denies a taxpayer-side actor entirely, even for their own taxpayer's indicators", async () => {
    const { GET } = await import("@/app/api/v1/risk-indicators/route");
    actingAs(TAXPAYER_OWNER);
    const response = await GET(new Request("https://vat-msa.local/api/v1/risk-indicators?taxpayer_id=tp-re-highvalue"));
    expect(response.status).toBe(403);
  });

  it("rejects an invalid status filter", async () => {
    const { GET } = await import("@/app/api/v1/risk-indicators/route");
    actingAs(NAMRA_SUPERVISOR);
    const response = await GET(new Request("https://vat-msa.local/api/v1/risk-indicators?status=NOT_A_STATUS"));
    expect(response.status).toBe(422);
  });
});
