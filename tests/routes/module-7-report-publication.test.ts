import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";
import { createFakeR2Bucket } from "@/tests/support/fake-r2";

/**
 * Module 7 Phase C: the shared response envelope (as-of-time, source
 * freshness, filters, currency basis, rule version) now wraps every report
 * response, and PublishReport is the "reconciliation-to-source-control-
 * totals as a hard publication gate" the playbook names. This system has no
 * separate warehouse/control-totals ledger yet, so the gate is built as a
 * genuine live re-derivation: the same deterministic query the original run
 * used is re-run against current source data and compared to the stored
 * result — if the underlying rows changed since the run completed,
 * publication is refused until a fresh run is taken. Proven through the
 * real route handler (app/api/v1/reports/runs/[id]/publication, dispatched
 * via lib/api/platform.ts's handleReportRunPublication) and
 * lib/data/platform-repository.ts's publishReportRun/computeReportResult.
 * See tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const OWNER_A: FixtureUser = { userId: "usr-pub-owner-a", externalUserId: "ext-pub-owner-a", email: "owner-a@pub-test.test" };
const OWNER_B: FixtureUser = { userId: "usr-pub-owner-b", externalUserId: "ext-pub-owner-b", email: "owner-b@pub-test.test" };

function actingAs(user: FixtureUser): void {
  __setRequestHeaders({ "oai-authenticated-user-id": user.externalUserId, "oai-authenticated-user-email": user.email });
}

let invoiceSeq = 0;

async function insertInvoice(supplierTaxpayerId: string): Promise<void> {
  invoiceSeq += 1;
  const now = "2026-08-26T00:00:00.000Z";
  await env.DB.prepare(`INSERT INTO invoices
    (id,invoice_number,document_type,source_system,source_document_id,supplier_taxpayer_id,supplier_name,supplier_vat_number,customer_taxpayer_id,customer_name,customer_vat_number,issue_date,currency,line_net_cents,tax_cents,total_cents,status,risk_level,payload_hash,transaction_id,certificate_id,verification_token,created_at,certified_at)
    VALUES (?,?,?,?,?,?,?,?,NULL,?,NULL,?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(
    `inv-pub-${invoiceSeq}`, `INV-PUB-${invoiceSeq}`, "TAX_INVOICE", "TEST", `src-pub-${invoiceSeq}`, supplierTaxpayerId, "Publication Test Supplier", "VAT-PUB-SUPPLIER",
    "Publication Test Customer", "2026-08-20", "NAD", 10_000, 1_500, 11_500, "CERTIFIED", "LOW",
    `hash-pub-${invoiceSeq}`, `txn-pub-${invoiceSeq}`, `cert-pub-${invoiceSeq}`, `token-pub-${invoiceSeq}`, now, now,
  ).run();
}

async function seedFixture(): Promise<void> {
  const db = env.DB;
  const now = "2026-08-01T00:00:00.000Z";
  await db.batch([
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-pub-a", "VAT-PUB-A", "TIN-PUB-A", "Publication Test Co A (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Publication Street", "finance@pub-a.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-pub-a", "tp-pub-a", "Publication Test Co A (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-pub-b", "VAT-PUB-B", "TIN-PUB-B", "Publication Test Co B (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "2 Publication Street", "finance@pub-b.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-pub-b", "tp-pub-b", "Publication Test Co B (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-pub-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OWNER_A.userId, OWNER_A.externalUserId, OWNER_A.email, "Owner A", "TAXPAYER_OWNER", "tp-pub-a", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OWNER_B.userId, OWNER_B.externalUserId, OWNER_B.email, "Owner B", "TAXPAYER_OWNER", "tp-pub-b", "ACTIVE", now),
    ...[OWNER_A, OWNER_B].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-pub-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
    db.prepare(`INSERT INTO report_definitions (id,code,name,audience,description,classification,query_version,status,created_at,freshness_tier,guardrail)
      VALUES (?,?,?,?,?,?,'1.0.0','ACTIVE',?,?,?)`).bind("report-def-pub-sales", "SALES_VAT_SUMMARY", "Sales and VAT summary", "TAXPAYER", "Invoice count, gross value and VAT aggregate.", "CONFIDENTIAL", now, "NEAR_REAL_TIME", "own organisation; delegated scope only"),
  ]);
  await insertInvoice("tp-pub-a");
}

async function runReportRoute(code: string, actor: FixtureUser): Promise<{ id: string; envelope: Record<string, unknown> }> {
  const { POST } = await import("@/app/api/v1/reports/[code]/runs/route");
  actingAs(actor);
  const response = await POST(new Request(`https://vat-msa.local/api/v1/reports/${code}/runs`, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ schema_version: "1.0.0" }) }), { params: Promise.resolve({ code }) });
  expect(response.status).toBe(201);
  const body = await response.json();
  return { id: body.report_run.id as string, envelope: body.report_run.envelope };
}

async function publishRoute(reportRunId: string, actor: FixtureUser, idempotencyKey = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/reports/runs/[id]/publication/route");
  actingAs(actor);
  const request = new Request(`https://vat-msa.local/api/v1/reports/runs/${reportRunId}/publication`, {
    method: "POST",
    headers: { "content-type": "application/json", "idempotency-key": idempotencyKey },
    body: JSON.stringify({ schema_version: "1.0.0" }),
  });
  return POST(request, { params: Promise.resolve({ id: reportRunId }) });
}

async function requestExportRoute(reportRunId: string, actor: FixtureUser): Promise<Response> {
  const { POST } = await import("@/app/api/v1/reports/runs/[id]/exports/route");
  actingAs(actor);
  const request = new Request(`https://vat-msa.local/api/v1/reports/runs/${reportRunId}/exports`, {
    method: "POST",
    headers: { "content-type": "application/json", "idempotency-key": crypto.randomUUID() },
    body: JSON.stringify({ schema_version: "1.0.0" }),
  });
  return POST(request, { params: Promise.resolve({ id: reportRunId }) });
}

describe("Module 7 report response envelope and publication reconciliation gate (Phase C)", () => {
  beforeAll(async () => {
    vi.stubEnv("NODE_ENV", "production");
    env.DB = createFakeD1();
    env.DOCUMENTS = createFakeR2Bucket();
    const { ensureDatabase } = await import("@/db/runtime");
    await ensureDatabase();
    await seedFixture();
  });

  afterAll(() => {
    vi.unstubAllEnvs();
  });

  it("wraps a report run's response in the shared as-of/freshness/filters/currency/rule-version envelope", async () => {
    const { envelope } = await runReportRoute("SALES_VAT_SUMMARY", OWNER_A);
    expect(envelope.audience).toBe("TAXPAYER");
    expect(envelope.freshness_tier).toBe("NEAR_REAL_TIME");
    expect(envelope.guardrail).toBe("own organisation; delegated scope only");
    expect(envelope.currency_basis).toBe("NAD");
    expect(envelope.rule_version).toBe("1.0.0");
    expect(envelope.filters).toEqual({ schema_version: "1.0.0" });
    expect(typeof envelope.as_of).toBe("string");
  });

  it("publishes a completed report run once its result reconciles against live source data", async () => {
    const { id: runId } = await runReportRoute("SALES_VAT_SUMMARY", OWNER_A);
    const response = await publishRoute(runId, OWNER_A);
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.report_run.status).toBe("PUBLISHED");
    expect(body.report_run.envelope.currency_basis).toBe("NAD");
    expect(body.report_run.published_at).toBeTruthy();

    const republish = await publishRoute(runId, OWNER_A);
    expect(republish.status).toBe(409);
  });

  it("refuses publication once the underlying source data has drifted since the run completed, and succeeds again after a fresh run", async () => {
    const { id: staleRunId } = await runReportRoute("SALES_VAT_SUMMARY", OWNER_A);

    await insertInvoice("tp-pub-a");

    const staleAttempt = await publishRoute(staleRunId, OWNER_A);
    expect(staleAttempt.status).toBe(409);

    const { id: freshRunId } = await runReportRoute("SALES_VAT_SUMMARY", OWNER_A);
    const freshAttempt = await publishRoute(freshRunId, OWNER_A);
    expect(freshAttempt.status).toBe(200);
  });

  it("denies publishing a report run that belongs to a different taxpayer", async () => {
    const { id: runId } = await runReportRoute("SALES_VAT_SUMMARY", OWNER_A);
    const response = await publishRoute(runId, OWNER_B);
    expect(response.status).toBe(403);
  });

  it("returns 404 publishing a non-existent report run", async () => {
    const response = await publishRoute(crypto.randomUUID(), OWNER_A);
    expect(response.status).toBe(404);
  });

  it("still allows exporting a published report run", async () => {
    const { id: runId } = await runReportRoute("SALES_VAT_SUMMARY", OWNER_A);
    const published = await publishRoute(runId, OWNER_A);
    expect(published.status).toBe(200);

    const exported = await requestExportRoute(runId, OWNER_A);
    expect(exported.status).toBe(201);
    expect((await exported.json()).report_export.status).toBe("APPROVED");
  });
});
