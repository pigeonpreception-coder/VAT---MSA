import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 4 Phase E: segregation of duties. Proven through the real route
 * handlers (app/api/v1/audit-cases/route.ts, .../transition, .../findings,
 * all dispatched via lib/api/compliance.ts) and
 * lib/data/compliance-repository.ts's enforceSegregationOfDuties, wired
 * into transitionCase's CLOSE action and issueFinding. See
 * tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const NAMRA_OFFICER: FixtureUser = { userId: "usr-sod-namra", externalUserId: "ext-sod-namra", email: "namra@sod.test" };
const NAMRA_OFFICER_2: FixtureUser = { userId: "usr-sod-namra-2", externalUserId: "ext-sod-namra-2", email: "namra2@sod.test" };
const NAMRA_AUDITOR: FixtureUser = { userId: "usr-sod-auditor", externalUserId: "ext-sod-auditor", email: "auditor@sod.test" };
const NAMRA_SUPERVISOR: FixtureUser = { userId: "usr-sod-supervisor", externalUserId: "ext-sod-supervisor", email: "supervisor@sod.test" };

/**
 * handleComplianceCommand rate-limits each command to 30 requests per actor
 * per 60s window (lib/security/request.ts's enforceRateLimits) — see
 * tests/routes/module-4-audit-case-lifecycle.test.ts for the same
 * constraint. The neutral state-advancing steps (AUTHORIZE/ASSIGN/ADVANCE)
 * round-robin across a dedicated pool so they never touch the accounts
 * this suite actually asserts on (NAMRA_OFFICER/NAMRA_OFFICER_2/
 * NAMRA_SUPERVISOR) — opened_by is fixed at case creation and unaffected
 * by who advances it afterward, so any national-scope account will do.
 */
const ADVANCER_POOL: readonly FixtureUser[] = [
  { userId: "usr-sod-adv-1", externalUserId: "ext-sod-adv-1", email: "adv1@sod.test" },
  { userId: "usr-sod-adv-2", externalUserId: "ext-sod-adv-2", email: "adv2@sod.test" },
  { userId: "usr-sod-adv-3", externalUserId: "ext-sod-adv-3", email: "adv3@sod.test" },
  { userId: "usr-sod-adv-4", externalUserId: "ext-sod-adv-4", email: "adv4@sod.test" },
];
let advancerIndex = 0;
function nextAdvancer(): FixtureUser {
  const advancer = ADVANCER_POOL[advancerIndex % ADVANCER_POOL.length];
  advancerIndex += 1;
  return advancer;
}

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
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-sod-taxpayer", "VAT-SOD-001", "TIN-SOD-001", "SoD Test Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 SoD Street", "finance@sod-taxpayer.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-sod-taxpayer", "tp-sod-taxpayer", "SoD Test Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-sod-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_OFFICER.userId, NAMRA_OFFICER.externalUserId, NAMRA_OFFICER.email, "NamRA Officer", "NAMRA_COMPLIANCE_OFFICER", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_OFFICER_2.userId, NAMRA_OFFICER_2.externalUserId, NAMRA_OFFICER_2.email, "NamRA Officer Two", "NAMRA_COMPLIANCE_OFFICER", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_AUDITOR.userId, NAMRA_AUDITOR.externalUserId, NAMRA_AUDITOR.email, "NamRA Auditor", "NAMRA_AUDITOR", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_SUPERVISOR.userId, NAMRA_SUPERVISOR.externalUserId, NAMRA_SUPERVISOR.email, "NamRA Supervisor", "NAMRA_SUPERVISOR", null, "ACTIVE", now),
    ...ADVANCER_POOL.map((user) =>
      db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
        .bind(user.userId, user.externalUserId, user.email, "NamRA Advancer", "NAMRA_AUDITOR", null, "ACTIVE", now)),
    ...[NAMRA_OFFICER, NAMRA_OFFICER_2, NAMRA_AUDITOR, NAMRA_SUPERVISOR, ...ADVANCER_POOL].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-sod-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
  ]);
}

async function openCaseAs(title: string, opener: FixtureUser): Promise<string> {
  const { POST } = await import("@/app/api/v1/audit-cases/route");
  actingAs(opener);
  const response = await POST(jsonRequest("https://vat-msa.local/api/v1/audit-cases", {
    schema_version: "1.0.0", taxpayer_id: "tp-sod-taxpayer", case_type: "VAT_AUDIT", title,
    opening_reason: "Matched evidence fell below the controlled review threshold for the period.", risk_tier: "HIGH",
  }, crypto.randomUUID()));
  expect(response.status).toBe(201);
  const body = await response.json();
  return body.resource.id as string;
}

async function transition(caseId: string, action: string, extra: Record<string, unknown>, actor: FixtureUser, key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/audit-cases/[id]/transition/route");
  actingAs(actor);
  return POST(
    jsonRequest(`https://vat-msa.local/api/v1/audit-cases/${caseId}/transition`, { schema_version: "1.0.0", action, reason: "Recorded as part of the segregation-of-duties test walk.", ...extra }, key),
    { params: Promise.resolve({ id: caseId }) },
  );
}

async function issueFindingAs(caseId: string, findingCode: string, actor: FixtureUser, extra: Record<string, unknown> = {}, key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/audit-cases/[id]/findings/route");
  actingAs(actor);
  return POST(
    jsonRequest(`https://vat-msa.local/api/v1/audit-cases/${caseId}/findings`, {
      schema_version: "1.0.0", finding_code: findingCode, title: "Underdeclared output VAT for the period",
      description: "Sampled invoices show output VAT amounts below the rate applicable to the declared supply category.",
      amount_cents: 250_000, currency: "NAD", ...extra,
    }, key),
    { params: Promise.resolve({ id: caseId }) },
  );
}

/** Advances a freshly-opened case (PROPOSED) up to ANALYSIS, where findings may be issued. Any officer may perform these steps — opened_by is fixed at creation and unaffected by who advances the case afterward. */
async function advanceToAnalysis(caseId: string): Promise<void> {
  await transition(caseId, "AUTHORIZE", {}, nextAdvancer());
  const assigner = nextAdvancer();
  await transition(caseId, "ASSIGN", { officer_id: assigner.userId }, assigner);
  await transition(caseId, "ADVANCE", {}, nextAdvancer());
  await transition(caseId, "ADVANCE", {}, nextAdvancer());
  await transition(caseId, "ADVANCE", {}, nextAdvancer());
}

/** Advances from ANALYSIS through to DECISION, ready for CLOSE. */
async function advanceToDecision(caseId: string): Promise<void> {
  await transition(caseId, "ADVANCE", {}, nextAdvancer());
  await transition(caseId, "ADVANCE", {}, nextAdvancer());
  await transition(caseId, "ADVANCE", {}, nextAdvancer());
}

describe("Module 4 segregation of duties (Phase E)", () => {
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

  it("blocks the case opener from issuing a finding on their own case without an override reason", async () => {
    const caseId = await openCaseAs("Opener blocked from own finding", NAMRA_OFFICER);
    await advanceToAnalysis(caseId);
    const response = await issueFindingAs(caseId, "finding-sod-blocked-1", NAMRA_OFFICER);
    expect(response.status).toBe(403);
  });

  it("blocks the case opener from closing their own case without an override reason", async () => {
    const caseId = await openCaseAs("Opener blocked from own close", NAMRA_OFFICER);
    await advanceToAnalysis(caseId);
    const finding = await issueFindingAs(caseId, "finding-sod-blocked-2", NAMRA_OFFICER_2);
    expect(finding.status).toBe(201);
    await advanceToDecision(caseId);
    const closed = await transition(caseId, "CLOSE", {}, NAMRA_OFFICER);
    expect(closed.status).toBe(403);
  });

  it("allows a different officer (not the opener) to issue the finding and close the case, no override needed", async () => {
    const caseId = await openCaseAs("Different officer unaffected", NAMRA_OFFICER);
    await advanceToAnalysis(caseId);
    const finding = await issueFindingAs(caseId, "finding-sod-different-officer", NAMRA_OFFICER_2);
    expect(finding.status).toBe(201);
    await advanceToDecision(caseId);
    const closed = await transition(caseId, "CLOSE", {}, NAMRA_OFFICER_2);
    expect(closed.status).toBe(200);
    expect((await closed.json()).resource.status).toBe("CLOSED");
  });

  it("rejects an override attempt by an actor who lacks cases:override-sod, even with a reason supplied", async () => {
    const caseId = await openCaseAs("Override denied without permission", NAMRA_OFFICER);
    await advanceToAnalysis(caseId);
    const finding = await issueFindingAs(caseId, "finding-sod-no-permission", NAMRA_OFFICER_2);
    expect(finding.status).toBe(201);
    await advanceToDecision(caseId);
    const closed = await transition(caseId, "CLOSE", { override_reason: "I am the only officer available today and need to close this myself." }, NAMRA_OFFICER);
    expect(closed.status).toBe(403);
  });

  it("allows the opener to issue a finding and close their own case when they hold cases:override-sod and supply a reason, and logs the exception distinctly", async () => {
    const caseId = await openCaseAs("Supervisor self-override", NAMRA_SUPERVISOR);
    await advanceToAnalysis(caseId);

    const findingOverrideReason = "Regional office is short-staffed this week; supervisor is authorising the exception personally.";
    const finding = await issueFindingAs(caseId, "finding-sod-override", NAMRA_SUPERVISOR, { override_reason: findingOverrideReason });
    expect(finding.status).toBe(201);
    const findingBody = await finding.json();

    const findingAuditRow = await env.DB.prepare("SELECT action, details FROM audit_events WHERE resource_type='AUDIT_FINDING' AND resource_id=?").bind(findingBody.resource.id).first<{ action: string; details: string }>();
    expect(findingAuditRow?.action).toBe("AUDIT_FINDING_ISSUED_SOD_OVERRIDE");
    expect(JSON.parse(findingAuditRow!.details).overrideReason).toBe(findingOverrideReason);

    await advanceToDecision(caseId);
    const closeOverrideReason = "Regional office is short-staffed this week; supervisor is closing this case personally.";
    const closed = await transition(caseId, "CLOSE", { override_reason: closeOverrideReason }, NAMRA_SUPERVISOR);
    expect(closed.status).toBe(200);
    expect((await closed.json()).resource.status).toBe("CLOSED");

    const closeAuditRow = await env.DB.prepare("SELECT action, details FROM audit_events WHERE resource_type='AUDIT_CASE' AND resource_id=? AND action LIKE '%SOD_OVERRIDE%'").bind(caseId).first<{ action: string; details: string }>();
    expect(closeAuditRow?.action).toBe("AUDIT_CASE_CLOSE_SOD_OVERRIDE");
    const closeDetails = JSON.parse(closeAuditRow!.details);
    expect(closeDetails.overrideReason).toBe(closeOverrideReason);
    expect(closeDetails.openedBy).toBe(NAMRA_SUPERVISOR.userId);
    expect(closeDetails.overriddenBy).toBe(NAMRA_SUPERVISOR.userId);
  });

  it("does not require an override reason at all when there is no same-actor conflict", async () => {
    const caseId = await openCaseAs("No conflict, no override needed", NAMRA_SUPERVISOR);
    await advanceToAnalysis(caseId);
    const finding = await issueFindingAs(caseId, "finding-sod-no-conflict", NAMRA_OFFICER);
    expect(finding.status).toBe(201);
    await advanceToDecision(caseId);
    const closed = await transition(caseId, "CLOSE", {}, NAMRA_OFFICER_2);
    expect(closed.status).toBe(200);
  });
});
