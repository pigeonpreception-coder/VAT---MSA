import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 7 Phase A: the real 6-tier audience/guardrail matrix from
 * 08-enterprise-architecture/22-audit-refund-reporting.md — previously
 * report_definitions.audience only ever held two pseudo-values ('BOTH',
 * 'NAMRA'), and any report code other than VAT_POSITION/COMPLIANCE_CASELOAD
 * (including the already-seeded SALES_VAT_SUMMARY) silently fell through to
 * a generic invoices-summary query rather than its own implementation.
 * Proven through the real route handler (app/api/v1/reports/[code]/runs,
 * dispatched via lib/api/platform.ts's handleReportRun) and
 * lib/data/platform-repository.ts's runInlineReport/requireAudienceAccess.
 * See tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const TAXPAYER_OWNER: FixtureUser = { userId: "usr-rpt-owner", externalUserId: "ext-rpt-owner", email: "owner@rpt-a.test" };
const PRACTITIONER_USER: FixtureUser = { userId: "usr-rpt-practitioner", externalUserId: "ext-rpt-practitioner", email: "practitioner@rpt-a.test" };
const NAMRA_OFFICER: FixtureUser = { userId: "usr-rpt-namra", externalUserId: "ext-rpt-namra", email: "namra@rpt.test" };
const NAMRA_AUDITOR_USER: FixtureUser = { userId: "usr-rpt-auditor", externalUserId: "ext-rpt-auditor", email: "auditor@rpt.test" };
const EXECUTIVE_USER: FixtureUser = { userId: "usr-rpt-exec", externalUserId: "ext-rpt-exec", email: "exec@rpt.test" };

function actingAs(user: FixtureUser): void {
  __setRequestHeaders({ "oai-authenticated-user-id": user.externalUserId, "oai-authenticated-user-email": user.email });
}

function jsonRequest(url: string, body: unknown = { schema_version: "1.0.0" }): Request {
  return new Request(url, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(body) });
}

async function invoiceInsertStatement(index: number, supplierTaxpayerId: string) {
  const now = "2026-08-26T00:00:00.000Z";
  return env.DB.prepare(`INSERT INTO invoices
    (id,invoice_number,document_type,source_system,source_document_id,supplier_taxpayer_id,supplier_name,supplier_vat_number,customer_taxpayer_id,customer_name,customer_vat_number,issue_date,currency,line_net_cents,tax_cents,total_cents,status,risk_level,payload_hash,transaction_id,certificate_id,verification_token,created_at,certified_at)
    VALUES (?,?,?,?,?,?,?,?,NULL,?,NULL,?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(
    `inv-rpt-${index}`, `INV-RPT-${index}`, "TAX_INVOICE", "TEST", `src-rpt-${index}`, supplierTaxpayerId, "Report Test Supplier", "VAT-RPT-SUPPLIER",
    "Report Test Customer", "2026-08-20", "NAD", 10_000, 1_500, 11_500, "CERTIFIED", "LOW",
    `hash-rpt-${index}`, `txn-rpt-${index}`, `cert-rpt-${index}`, `token-rpt-${index}`, now, now,
  );
}

async function seedFixture(): Promise<void> {
  const db = env.DB;
  const now = "2026-08-01T00:00:00.000Z";
  await db.batch([
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-rpt-a", "VAT-RPT-A", "TIN-RPT-A", "Report Test Co A (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Report Street", "finance@rpt-a.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-rpt-a", "tp-rpt-a", "Report Test Co A (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-rpt-b", "VAT-RPT-B", "TIN-RPT-B", "Report Test Co B (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "2 Report Street", "finance@rpt-b.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-rpt-b", "tp-rpt-b", "Report Test Co B (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-rpt-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(TAXPAYER_OWNER.userId, TAXPAYER_OWNER.externalUserId, TAXPAYER_OWNER.email, "Owner", "TAXPAYER_OWNER", "tp-rpt-a", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(PRACTITIONER_USER.userId, PRACTITIONER_USER.externalUserId, PRACTITIONER_USER.email, "Practitioner", "TAXPAYER_OWNER", "tp-rpt-a", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_OFFICER.userId, NAMRA_OFFICER.externalUserId, NAMRA_OFFICER.email, "NamRA Officer", "NAMRA_COMPLIANCE_OFFICER", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_AUDITOR_USER.userId, NAMRA_AUDITOR_USER.externalUserId, NAMRA_AUDITOR_USER.email, "NamRA Auditor", "NAMRA_AUDITOR", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(EXECUTIVE_USER.userId, EXECUTIVE_USER.externalUserId, EXECUTIVE_USER.email, "NamRA Supervisor", "NAMRA_SUPERVISOR", null, "ACTIVE", now),
    ...[TAXPAYER_OWNER, PRACTITIONER_USER, NAMRA_OFFICER, NAMRA_AUDITOR_USER, EXECUTIVE_USER].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-rpt-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
    db.prepare(`INSERT INTO delegations (id,organisation_id,taxpayer_id,delegator_user_id,delegate_user_id,scopes,status,valid_from,valid_to,approved_by,approved_at,created_at)
      VALUES (?,?,?,?,?,?,?,?,NULL,?,?,?)`).bind("deleg-rpt-0001", "org-rpt-b", "tp-rpt-b", NAMRA_OFFICER.userId, PRACTITIONER_USER.userId, "reports:read", "ACTIVE", now, NAMRA_OFFICER.userId, now, now),
    await invoiceInsertStatement(0, "tp-rpt-b"),
    db.prepare(`INSERT INTO reconciliation_exceptions (id,invoice_id,taxpayer_id,exception_type,severity,status,summary,created_at)
      VALUES (?,?,?,?,?,?,?,?)`).bind("exc-rpt-0001", "inv-rpt-0", "tp-rpt-b", "AMOUNT_MISMATCH", "MEDIUM", "OPEN", "Test reconciliation exception for practitioner report.", now),
    db.prepare(`INSERT INTO audit_cases (id,case_number,organisation_id,taxpayer_id,case_type,title,opening_reason,risk_tier,status,opened_by,opened_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?)`).bind("case-rpt-0001", "CASE-RPT-0001", "org-rpt-a", "tp-rpt-a", "DESK_REVIEW", "Report evidence test case", "Testing case evidence summary report.", "LOW", "PROPOSED", NAMRA_OFFICER.userId, now, now),
    db.prepare(`INSERT INTO audit_evidence (id,audit_case_id,evidence_type,source_resource_type,source_resource_id,checksum_sha256,description,status,added_by,added_at,legal_hold)
      VALUES (?,?,?,?,?,?,?,?,?,?,0)`).bind("evidence-rpt-0001", "case-rpt-0001", "SYSTEM_RECORD", "INVOICE", "inv-rpt-0", "a".repeat(64), "Test evidence for the report.", "PRESERVED", NAMRA_OFFICER.userId, now),
    db.prepare(`INSERT INTO audit_evidence_custody_events (id,audit_evidence_id,action,actor_id,notes,integrity_verified,occurred_at)
      VALUES (?,?,?,?,?,?,?)`).bind("custody-rpt-0001", "evidence-rpt-0001", "VERIFY", NAMRA_OFFICER.userId, "Verified for report test.", 1, now),
    ...([
      ["report-def-sales", "SALES_VAT_SUMMARY", "Sales and VAT summary", "TAXPAYER", "Invoice count, gross value and VAT aggregate.", "CONFIDENTIAL", "NEAR_REAL_TIME", "own organisation; delegated scope only"],
      ["report-def-cases", "COMPLIANCE_CASELOAD", "Compliance caseload", "NAMRA_OPERATIONS", "Open and total compliance case counts.", "TAX_CONFIDENTIAL", "MINUTES_TO_DAILY", "office/purpose policy; sensitive field masking"],
      ["report-def-executive", "REVENUE_COMPLIANCE_TRENDS", "Revenue and compliance trends", "EXECUTIVE", "National aggregate invoice revenue and case-load trend.", "CONFIDENTIAL", "DAILY", "aggregation, disclosure controls"],
      ["report-def-portfolio", "PORTFOLIO_EXCEPTIONS", "Portfolio exceptions and deadlines", "PRACTITIONER", "Reconciliation exceptions across delegated taxpayers.", "TAX_CONFIDENTIAL", "MINUTES", "consent/mandate and client-level isolation"],
      ["report-def-evidence", "CASE_EVIDENCE_SUMMARY", "Case evidence summary", "AUDITOR_LEGAL", "Point-in-time evidence and custody-event counts.", "RESTRICTED", "POINT_IN_TIME", "case authority, custody and watermark"],
      ["report-def-opendata", "NATIONAL_VAT_AGGREGATE", "National VAT aggregate", "OPEN_DATA", "Approved national invoice-count and value aggregate, minimum-cell suppressed.", "INTERNAL", "SCHEDULED", "privacy review, minimum-cell suppression, no re-identification"],
    ] as const).map(([id, code, name, audience, description, classification, freshnessTier, guardrail]) =>
      db.prepare(`INSERT INTO report_definitions (id,code,name,audience,description,classification,query_version,status,created_at,freshness_tier,guardrail)
        VALUES (?,?,?,?,?,?,'1.0.0','ACTIVE',?,?,?)`).bind(id, code, name, audience, description, classification, now, freshnessTier, guardrail)),
  ]);
}

async function runReport(code: string, actor: FixtureUser, body: Record<string, unknown> = { schema_version: "1.0.0" }): Promise<Response> {
  const { POST } = await import("@/app/api/v1/reports/[code]/runs/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/reports/${code}/runs`, body), { params: Promise.resolve({ code }) });
}

describe("Module 7 report audience tiers and guardrails (Phase A)", () => {
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

  it("runs a TAXPAYER-tier report scoped to the caller's own organisation, with the audience/freshness/guardrail envelope", async () => {
    const response = await runReport("SALES_VAT_SUMMARY", TAXPAYER_OWNER);
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.report_run.audience).toBe("TAXPAYER");
    expect(body.report_run.freshness_tier).toBe("NEAR_REAL_TIME");
    expect(body.report_run.guardrail).toBe("own organisation; delegated scope only");
  });

  it("restricts a NAMRA_OPERATIONS-tier report to national-scope actors", async () => {
    const officerResponse = await runReport("COMPLIANCE_CASELOAD", NAMRA_OFFICER);
    expect(officerResponse.status).toBe(201);
    expect((await officerResponse.json()).report_run.audience).toBe("NAMRA_OPERATIONS");

    const taxpayerResponse = await runReport("COMPLIANCE_CASELOAD", TAXPAYER_OWNER);
    expect(taxpayerResponse.status).toBe(403);
  });

  it("restricts an EXECUTIVE-tier report to actors holding reports:executive", async () => {
    const execResponse = await runReport("REVENUE_COMPLIANCE_TRENDS", EXECUTIVE_USER);
    expect(execResponse.status).toBe(201);
    const execBody = await execResponse.json();
    expect(execBody.report_run.audience).toBe("EXECUTIVE");
    expect(execBody.report_run.result_summary.cases).toBeGreaterThanOrEqual(1);

    const officerResponse = await runReport("REVENUE_COMPLIANCE_TRENDS", NAMRA_OFFICER);
    expect(officerResponse.status).toBe(403);
  });

  it("scopes a PRACTITIONER-tier report to the actor's own active delegations", async () => {
    const delegatedResponse = await runReport("PORTFOLIO_EXCEPTIONS", PRACTITIONER_USER);
    expect(delegatedResponse.status).toBe(201);
    const delegatedBody = await delegatedResponse.json();
    expect(delegatedBody.report_run.result_summary.exceptions).toBeGreaterThanOrEqual(1);
    expect(delegatedBody.report_run.result_summary.open_exceptions).toBeGreaterThanOrEqual(1);

    const undelegatedResponse = await runReport("PORTFOLIO_EXCEPTIONS", TAXPAYER_OWNER);
    expect(undelegatedResponse.status).toBe(403);
  });

  it("runs an AUDITOR_LEGAL-tier, case-scoped report requiring case authority and a case_id parameter", async () => {
    const response = await runReport("CASE_EVIDENCE_SUMMARY", NAMRA_AUDITOR_USER, { schema_version: "1.0.0", case_id: "case-rpt-0001" });
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.report_run.result_summary.evidence_items).toBe(1);
    expect(body.report_run.result_summary.custody_events).toBe(1);

    const missingParam = await runReport("CASE_EVIDENCE_SUMMARY", NAMRA_AUDITOR_USER);
    expect(missingParam.status).toBe(422);

    const noAuthority = await runReport("CASE_EVIDENCE_SUMMARY", TAXPAYER_OWNER, { schema_version: "1.0.0", case_id: "case-rpt-0001" });
    expect(noAuthority.status).toBe(403);

    const notFound = await runReport("CASE_EVIDENCE_SUMMARY", NAMRA_AUDITOR_USER, { schema_version: "1.0.0", case_id: crypto.randomUUID() });
    expect(notFound.status).toBe(404);
  });

  it("suppresses the OPEN_DATA aggregate below the minimum-cell threshold, and reveals it once enough invoices exist", async () => {
    const suppressed = await runReport("NATIONAL_VAT_AGGREGATE", NAMRA_OFFICER);
    expect(suppressed.status).toBe(201);
    const suppressedBody = await suppressed.json();
    expect(suppressedBody.report_run.result_summary.suppressed).toBe(true);
    expect(suppressedBody.report_run.result_summary.invoices).toBe(0);

    for (let i = 1; i <= 10; i += 1) {
      await env.DB.batch([await invoiceInsertStatement(i, "tp-rpt-a")]);
    }

    const revealed = await runReport("NATIONAL_VAT_AGGREGATE", NAMRA_OFFICER);
    expect(revealed.status).toBe(201);
    const revealedBody = await revealed.json();
    expect(revealedBody.report_run.result_summary.suppressed).toBe(false);
    expect(revealedBody.report_run.result_summary.invoices).toBeGreaterThanOrEqual(10);
  });

  it("returns 404 for an unknown report code", async () => {
    const response = await runReport("DOES_NOT_EXIST", NAMRA_OFFICER);
    expect(response.status).toBe(404);
  });

  it("returns 501 for a report definition with no runnable implementation", async () => {
    await env.DB.batch([
      env.DB.prepare(`INSERT INTO report_definitions (id,code,name,audience,description,classification,query_version,status,created_at,freshness_tier,guardrail)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("report-def-unimplemented", "UNIMPLEMENTED_TEST_REPORT", "Unimplemented test report", "TAXPAYER", "A definition with no code branch.", "INTERNAL", "1.0.0", "ACTIVE", "2026-08-26T00:00:00.000Z", "DAILY", "own organisation; delegated scope only"),
    ]);
    const response = await runReport("UNIMPLEMENTED_TEST_REPORT", TAXPAYER_OWNER);
    expect(response.status).toBe(501);
  });
});
