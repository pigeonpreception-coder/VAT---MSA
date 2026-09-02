import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";
import { createFakeR2Bucket } from "@/tests/support/fake-r2";

/**
 * Module 7 Phase B: RequestExport/ApproveExport/CancelReport and
 * AuthorizedDownload for a report export. There is no queue/cron
 * infrastructure in this codebase (verified empty queues/triggers in
 * wrangler.json before this phase started), so the export file is generated
 * inline; a genuinely new report_exports table and lifecycle carries the
 * approval gate, reusing Module 6's document_metadata
 * QUARANTINED/ACTIVE/REJECTED states rather than duplicating them. Proven
 * through the real route handlers (app/api/v1/reports/runs/[id]/exports and
 * app/api/v1/reports/exports/[id]/..., dispatched via lib/api/platform.ts)
 * and lib/data/platform-repository.ts. See
 * tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const OWNER_A: FixtureUser = { userId: "usr-exp-owner-a", externalUserId: "ext-exp-owner-a", email: "owner-a@exp-test.test" };
const OWNER_B: FixtureUser = { userId: "usr-exp-owner-b", externalUserId: "ext-exp-owner-b", email: "owner-b@exp-test.test" };
const NAMRA_REQUESTER: FixtureUser = { userId: "usr-exp-namra-req", externalUserId: "ext-exp-namra-req", email: "namra-req@exp-test.test" };
const NAMRA_APPROVER: FixtureUser = { userId: "usr-exp-namra-appr", externalUserId: "ext-exp-namra-appr", email: "namra-appr@exp-test.test" };

function actingAs(user: FixtureUser): void {
  __setRequestHeaders({ "oai-authenticated-user-id": user.externalUserId, "oai-authenticated-user-email": user.email });
}

/** Security fix 2026-08-27: grants a real, server-verified step-up (step_up_events row) instead of the previous x-vat-msa-auth-assurance/x-vat-msa-reauthenticated-at headers, which lib/security/step-up.ts's requireStepUp no longer reads at all. */
async function grantStepUp(userId: string): Promise<void> {
  await env.DB.prepare("INSERT INTO step_up_events (id,user_id,method,verified_at,expires_at) VALUES (?,?,?,?,?)")
    .bind(crypto.randomUUID(), userId, "TOTP", new Date().toISOString(), new Date(Date.now() + 5 * 60_000).toISOString()).run();
}

function jsonRequest(url: string, body: unknown = { schema_version: "1.0.0" }, options: { idempotencyKey?: string } = {}): Request {
  const idempotencyKey = options.idempotencyKey ?? crypto.randomUUID();
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
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-exp-a", "VAT-EXP-A", "TIN-EXP-A", "Export Test Co A (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Export Street", "finance@exp-a.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-exp-a", "tp-exp-a", "Export Test Co A (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-exp-b", "VAT-EXP-B", "TIN-EXP-B", "Export Test Co B (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "2 Export Street", "finance@exp-b.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-exp-b", "tp-exp-b", "Export Test Co B (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-exp-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OWNER_A.userId, OWNER_A.externalUserId, OWNER_A.email, "Owner A", "TAXPAYER_OWNER", "tp-exp-a", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OWNER_B.userId, OWNER_B.externalUserId, OWNER_B.email, "Owner B", "TAXPAYER_OWNER", "tp-exp-b", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_REQUESTER.userId, NAMRA_REQUESTER.externalUserId, NAMRA_REQUESTER.email, "NamRA Requester", "NAMRA_COMPLIANCE_OFFICER", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_APPROVER.userId, NAMRA_APPROVER.externalUserId, NAMRA_APPROVER.email, "NamRA Approver", "NAMRA_AUDITOR", null, "ACTIVE", now),
    ...[OWNER_A, OWNER_B, NAMRA_REQUESTER, NAMRA_APPROVER].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-exp-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
    db.prepare(`INSERT INTO report_definitions (id,code,name,audience,description,classification,query_version,status,created_at,freshness_tier,guardrail)
      VALUES (?,?,?,?,?,?,'1.0.0','ACTIVE',?,?,?)`).bind("report-def-exp-sales", "SALES_VAT_SUMMARY", "Sales and VAT summary", "TAXPAYER", "Invoice count, gross value and VAT aggregate.", "CONFIDENTIAL", now, "NEAR_REAL_TIME", "own organisation; delegated scope only"),
    db.prepare(`INSERT INTO report_definitions (id,code,name,audience,description,classification,query_version,status,created_at,freshness_tier,guardrail)
      VALUES (?,?,?,?,?,?,'1.0.0','ACTIVE',?,?,?)`).bind("report-def-exp-cases", "COMPLIANCE_CASELOAD", "Compliance caseload", "NAMRA_OPERATIONS", "Open and total compliance case counts.", "TAX_CONFIDENTIAL", now, "MINUTES_TO_DAILY", "office/purpose policy; sensitive field masking"),
  ]);
}

async function runReportRoute(code: string, actor: FixtureUser): Promise<string> {
  const { POST } = await import("@/app/api/v1/reports/[code]/runs/route");
  actingAs(actor);
  const response = await POST(new Request(`https://vat-msa.local/api/v1/reports/${code}/runs`, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify({ schema_version: "1.0.0" }) }), { params: Promise.resolve({ code }) });
  expect(response.status).toBe(201);
  return (await response.json()).report_run.id as string;
}

async function requestExportRoute(reportRunId: string, actor: FixtureUser, options: { idempotencyKey?: string; stepUp?: boolean } = {}): Promise<Response> {
  const { POST } = await import("@/app/api/v1/reports/runs/[id]/exports/route");
  actingAs(actor);
  if (options.stepUp) await grantStepUp(actor.userId);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/reports/runs/${reportRunId}/exports`, { schema_version: "1.0.0" }, options), { params: Promise.resolve({ id: reportRunId }) });
}

async function approveExportRoute(exportId: string, actor: FixtureUser, options: { idempotencyKey?: string; stepUp?: boolean } = {}): Promise<Response> {
  const { POST } = await import("@/app/api/v1/reports/exports/[id]/approval/route");
  actingAs(actor);
  if (options.stepUp) await grantStepUp(actor.userId);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/reports/exports/${exportId}/approval`, { schema_version: "1.0.0" }, options), { params: Promise.resolve({ id: exportId }) });
}

async function cancelExportRoute(exportId: string, actor: FixtureUser, reason: string, options: { idempotencyKey?: string } = {}): Promise<Response> {
  const { POST } = await import("@/app/api/v1/reports/exports/[id]/cancellation/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/reports/exports/${exportId}/cancellation`, { schema_version: "1.0.0", reason }, options), { params: Promise.resolve({ id: exportId }) });
}

async function statusRoute(exportId: string, actor: FixtureUser): Promise<Response> {
  const { GET } = await import("@/app/api/v1/reports/exports/[id]/route");
  actingAs(actor);
  return GET(new Request(`https://vat-msa.local/api/v1/reports/exports/${exportId}`), { params: Promise.resolve({ id: exportId }) });
}

async function downloadRoute(exportId: string, actor: FixtureUser): Promise<Response> {
  const { GET } = await import("@/app/api/v1/reports/exports/[id]/download/route");
  actingAs(actor);
  return GET(new Request(`https://vat-msa.local/api/v1/reports/exports/${exportId}/download`), { params: Promise.resolve({ id: exportId }) });
}

describe("Module 7 report export request/approval/download (Phase B)", () => {
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

  it("auto-approves and downloads a non-sensitive report export, embedding a watermark", async () => {
    const runId = await runReportRoute("SALES_VAT_SUMMARY", OWNER_A);
    const requested = await requestExportRoute(runId, OWNER_A);
    expect(requested.status).toBe(201);
    const requestedBody = await requested.json();
    expect(requestedBody.report_export.status).toBe("APPROVED");
    expect(requestedBody.report_export.requires_step_up).toBe(0);

    const exportId = requestedBody.report_export.id as string;
    const status = await statusRoute(exportId, OWNER_A);
    expect(status.status).toBe(200);
    expect((await status.json()).report_export.status).toBe("APPROVED");

    const download = await downloadRoute(exportId, OWNER_A);
    expect(download.status).toBe(200);
    const content = await download.text();
    expect(content).toContain("# code:SALES_VAT_SUMMARY");
    expect(content).toContain(`# watermark:issued_to:${OWNER_A.userId}`);
    expect(download.headers.get("content-disposition")).toContain(".csv");
  });

  it("requires a fresh step-up to request a sensitive export, then holds it for maker-checker approval", async () => {
    const runId = await runReportRoute("COMPLIANCE_CASELOAD", NAMRA_REQUESTER);

    const withoutStepUp = await requestExportRoute(runId, NAMRA_REQUESTER);
    expect(withoutStepUp.status).toBe(403);

    const requested = await requestExportRoute(runId, NAMRA_REQUESTER, { stepUp: true });
    expect(requested.status).toBe(201);
    const requestedBody = await requested.json();
    expect(requestedBody.report_export.status).toBe("PENDING_APPROVAL");
    expect(requestedBody.report_export.requires_step_up).toBe(1);
    const exportId = requestedBody.report_export.id as string;

    const downloadBeforeApproval = await downloadRoute(exportId, NAMRA_REQUESTER);
    expect(downloadBeforeApproval.status).toBe(409);

    const selfApproval = await approveExportRoute(exportId, NAMRA_REQUESTER, { stepUp: true });
    expect(selfApproval.status).toBe(403);

    const approvalWithoutStepUp = await approveExportRoute(exportId, NAMRA_APPROVER);
    expect(approvalWithoutStepUp.status).toBe(403);

    const approved = await approveExportRoute(exportId, NAMRA_APPROVER, { stepUp: true });
    expect(approved.status).toBe(200);
    expect((await approved.json()).report_export.status).toBe("APPROVED");

    const download = await downloadRoute(exportId, NAMRA_REQUESTER);
    expect(download.status).toBe(200);
    expect(await download.text()).toContain("# code:COMPLIANCE_CASELOAD");

    const reapprove = await approveExportRoute(exportId, NAMRA_APPROVER, { stepUp: true, idempotencyKey: crypto.randomUUID() });
    expect(reapprove.status).toBe(409);
  });

  it("cancels a pending export, refusing download and refusing to approve it afterwards", async () => {
    const runId = await runReportRoute("COMPLIANCE_CASELOAD", NAMRA_REQUESTER);
    const requested = await requestExportRoute(runId, NAMRA_REQUESTER, { stepUp: true });
    const exportId = (await requested.json()).report_export.id as string;

    const cancelled = await cancelExportRoute(exportId, NAMRA_REQUESTER, "No longer required for this review.");
    expect(cancelled.status).toBe(200);
    expect((await cancelled.json()).report_export.status).toBe("CANCELLED");

    const download = await downloadRoute(exportId, NAMRA_REQUESTER);
    expect(download.status).toBe(409);

    const approveAfterCancel = await approveExportRoute(exportId, NAMRA_APPROVER, { stepUp: true });
    expect(approveAfterCancel.status).toBe(409);
  });

  it("refuses download once a report export has expired", async () => {
    const runId = await runReportRoute("SALES_VAT_SUMMARY", OWNER_A);
    const requested = await requestExportRoute(runId, OWNER_A);
    const exportId = (await requested.json()).report_export.id as string;

    await env.DB.prepare("UPDATE report_exports SET expires_at=? WHERE id=?").bind("2020-01-01T00:00:00.000Z", exportId).run();

    const download = await downloadRoute(exportId, OWNER_A);
    expect(download.status).toBe(410);
  });

  it("denies exporting or accessing a report run that belongs to a different taxpayer", async () => {
    const runId = await runReportRoute("SALES_VAT_SUMMARY", OWNER_A);
    const crossTenantRequest = await requestExportRoute(runId, OWNER_B);
    expect(crossTenantRequest.status).toBe(403);

    const requested = await requestExportRoute(runId, OWNER_A);
    const exportId = (await requested.json()).report_export.id as string;
    const crossTenantStatus = await statusRoute(exportId, OWNER_B);
    expect(crossTenantStatus.status).toBe(403);
    const crossTenantDownload = await downloadRoute(exportId, OWNER_B);
    expect(crossTenantDownload.status).toBe(403);
  });

  it("returns 404 for a non-existent report run or report export", async () => {
    const missingRun = await requestExportRoute(crypto.randomUUID(), OWNER_A);
    expect(missingRun.status).toBe(404);

    const missingExport = await statusRoute(crypto.randomUUID(), OWNER_A);
    expect(missingExport.status).toBe(404);
  });
});
