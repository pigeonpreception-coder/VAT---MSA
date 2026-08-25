import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 4 Phase B: the human-authorisation gate between a risk indicator
 * and an audit case, proven through the real route handlers
 * (app/api/v1/risk-indicators/[id]/assignment and .../decision, both
 * dispatched via lib/api/compliance.ts) and lib/data/compliance-repository.ts's
 * assignRiskReview/approveRiskAction. See
 * tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const TAXPAYER_OWNER: FixtureUser = { userId: "usr-rg-taxpayer-owner", externalUserId: "ext-rg-taxpayer-owner", email: "owner@rg-taxpayer.test" };
const NAMRA_OFFICER: FixtureUser = { userId: "usr-rg-namra", externalUserId: "ext-rg-namra", email: "namra@rg.test" };
const NAMRA_SUPERVISOR: FixtureUser = { userId: "usr-rg-supervisor", externalUserId: "ext-rg-supervisor", email: "supervisor@rg.test" };
const NAMRA_AUDITOR: FixtureUser = { userId: "usr-rg-auditor", externalUserId: "ext-rg-auditor", email: "auditor@rg.test" };
const INACTIVE_OFFICER: FixtureUser = { userId: "usr-rg-inactive", externalUserId: "ext-rg-inactive", email: "inactive@rg.test" };

/**
 * handleComplianceCommand rate-limits each command to 30 requests per actor
 * per 60s window — see tests/routes/module-4-audit-case-lifecycle.test.ts
 * for the same constraint. Default (unpinned) actors round-robin across a
 * small national-scope officer pool; tests needing a specific, stable actor
 * (idempotency-key replay, permission-denial) pass an explicit actor.
 */
const NATIONAL_ACTOR_POOL: readonly FixtureUser[] = [NAMRA_OFFICER, NAMRA_SUPERVISOR, NAMRA_AUDITOR];
let nationalActorIndex = 0;
function nextNationalActor(): FixtureUser {
  const actor = NATIONAL_ACTOR_POOL[nationalActorIndex % NATIONAL_ACTOR_POOL.length];
  nationalActorIndex += 1;
  return actor;
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
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-rg-taxpayer", "VAT-RG-001", "TIN-RG-001", "Risk Gate Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Risk Street", "finance@rg-taxpayer.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-rg-taxpayer", "tp-rg-taxpayer", "Risk Gate Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-rg-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(TAXPAYER_OWNER.userId, TAXPAYER_OWNER.externalUserId, TAXPAYER_OWNER.email, "Taxpayer Owner", "TAXPAYER_OWNER", "tp-rg-taxpayer", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_OFFICER.userId, NAMRA_OFFICER.externalUserId, NAMRA_OFFICER.email, "NamRA Officer", "NAMRA_COMPLIANCE_OFFICER", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_SUPERVISOR.userId, NAMRA_SUPERVISOR.externalUserId, NAMRA_SUPERVISOR.email, "NamRA Supervisor", "NAMRA_SUPERVISOR", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_AUDITOR.userId, NAMRA_AUDITOR.externalUserId, NAMRA_AUDITOR.email, "NamRA Auditor", "NAMRA_AUDITOR", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(INACTIVE_OFFICER.userId, INACTIVE_OFFICER.externalUserId, INACTIVE_OFFICER.email, "Inactive Officer", "NAMRA_COMPLIANCE_OFFICER", null, "SUSPENDED", now),
    ...[TAXPAYER_OWNER, NAMRA_OFFICER, NAMRA_SUPERVISOR, NAMRA_AUDITOR, INACTIVE_OFFICER].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-rg-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
  ]);
}

let indicatorSeq = 0;
async function seedRiskIndicator(status: "OPEN" | "UNDER_REVIEW" = "OPEN", assignedOfficerId: string | null = null): Promise<string> {
  indicatorSeq += 1;
  const id = `risk-rg-${String(indicatorSeq).padStart(4, "0")}`;
  const now = "2026-08-05T00:00:00.000Z";
  await env.DB.prepare(`INSERT INTO risk_indicators
    (id,organisation_id,taxpayer_id,subject_type,subject_id,indicator_code,score_bps,severity,rationale,rule_version,decision_effect,status,detected_at,reviewed_by,reviewed_at,assigned_officer_id,escalated_case_id)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,NULL,?,NULL)`)
    .bind(id, "org-rg-taxpayer", "tp-rg-taxpayer", "INVOICE", `inv-rg-${indicatorSeq}`, "HIGH_VALUE_TRANSACTION", 9000, "HIGH", "Gross value exceeds the controlled pilot threshold.", "RISK-PILOT-2026.1", "ADVISORY_ONLY", status, now, assignedOfficerId)
    .run();
  return id;
}

async function assign(indicatorId: string, officerId: string, actor: FixtureUser = nextNationalActor(), key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/risk-indicators/[id]/assignment/route");
  actingAs(actor);
  return POST(
    jsonRequest(`https://vat-msa.local/api/v1/risk-indicators/${indicatorId}/assignment`, { schema_version: "1.0.0", officer_id: officerId }, key),
    { params: Promise.resolve({ id: indicatorId }) },
  );
}

async function decide(indicatorId: string, body: Record<string, unknown>, actor: FixtureUser = nextNationalActor(), key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/risk-indicators/[id]/decision/route");
  actingAs(actor);
  return POST(
    jsonRequest(`https://vat-msa.local/api/v1/risk-indicators/${indicatorId}/decision`, { schema_version: "1.0.0", ...body }, key),
    { params: Promise.resolve({ id: indicatorId }) },
  );
}

describe("Module 4 risk authorisation gate (Phase B)", () => {
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

  it("assigns an OPEN indicator for review and moves it to UNDER_REVIEW", async () => {
    const indicatorId = await seedRiskIndicator("OPEN");
    const response = await assign(indicatorId, NAMRA_OFFICER.userId);
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.resource.status).toBe("UNDER_REVIEW");
    expect(body.resource.assigned_officer_id).toBe(NAMRA_OFFICER.userId);
  });

  it("rejects assigning a review to an indicator that is not OPEN", async () => {
    const indicatorId = await seedRiskIndicator("UNDER_REVIEW", NAMRA_OFFICER.userId);
    const response = await assign(indicatorId, NAMRA_OFFICER.userId);
    expect(response.status).toBe(409);
  });

  it("validates the assigned officer exists and is active", async () => {
    const missingOfficerIndicator = await seedRiskIndicator("OPEN");
    const missing = await assign(missingOfficerIndicator, crypto.randomUUID());
    expect(missing.status).toBe(404);

    const inactiveOfficerIndicator = await seedRiskIndicator("OPEN");
    const inactive = await assign(inactiveOfficerIndicator, INACTIVE_OFFICER.userId);
    expect(inactive.status).toBe(409);
  });

  it("denies a taxpayer-side actor assigning a review (risk:review is a national-scope permission)", async () => {
    const indicatorId = await seedRiskIndicator("OPEN");
    const response = await assign(indicatorId, NAMRA_OFFICER.userId, TAXPAYER_OWNER);
    expect(response.status).toBe(403);
  });

  it("returns 404 for assignment on a non-existent indicator", async () => {
    const response = await assign(crypto.randomUUID(), NAMRA_OFFICER.userId);
    expect(response.status).toBe(404);
  });

  it("rejects a decision recorded before a review has been assigned", async () => {
    const indicatorId = await seedRiskIndicator("OPEN");
    const response = await decide(indicatorId, { decision: "DISMISS", rationale: "The indicator was independently verified as a false positive against known evidence." });
    expect(response.status).toBe(409);
  });

  it("dismisses an UNDER_REVIEW indicator and records the reviewer", async () => {
    const indicatorId = await seedRiskIndicator("OPEN");
    await assign(indicatorId, NAMRA_OFFICER.userId);
    const actor = nextNationalActor();
    const response = await decide(indicatorId, { decision: "DISMISS", rationale: "The indicator was independently verified as a false positive against known evidence." }, actor);
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.resource.status).toBe("DISMISSED");
    expect(body.resource.reviewed_by).toBe(actor.userId);
    expect(body.resource.escalated_case_id).toBeNull();
  });

  it("requires case_type and case_title to escalate, and rejects an unsupported case_type", async () => {
    const indicatorId = await seedRiskIndicator("OPEN");
    await assign(indicatorId, NAMRA_OFFICER.userId);
    const missingFields = await decide(indicatorId, { decision: "ESCALATE_TO_CASE", rationale: "The reviewing officer independently confirmed this transaction pattern warrants a formal audit." });
    expect(missingFields.status).toBe(422);

    const indicatorId2 = await seedRiskIndicator("OPEN");
    await assign(indicatorId2, NAMRA_OFFICER.userId);
    const badType = await decide(indicatorId2, { decision: "ESCALATE_TO_CASE", rationale: "The reviewing officer independently confirmed this transaction pattern warrants a formal audit.", case_type: "NOT_A_TYPE", case_title: "High-value transaction pattern review" });
    expect(badType.status).toBe(422);
  });

  it("escalates an UNDER_REVIEW indicator to a real audit case, carrying severity into risk_tier and rationale into opening_reason", async () => {
    const indicatorId = await seedRiskIndicator("OPEN");
    await assign(indicatorId, NAMRA_OFFICER.userId);
    const response = await decide(indicatorId, {
      decision: "ESCALATE_TO_CASE",
      rationale: "The reviewing officer independently confirmed this transaction pattern warrants a formal audit.",
      case_type: "VAT_AUDIT",
      case_title: "High-value transaction pattern review",
    });
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.resource.status).toBe("ESCALATED_TO_CASE");
    const caseId = body.resource.escalated_case_id as string;
    expect(caseId).toBeTruthy();

    const { GET } = await import("@/app/api/v1/compliance/route");
    actingAs(NAMRA_SUPERVISOR);
    const snapshotResponse = await GET(new Request("https://vat-msa.local/api/v1/compliance"));
    expect(snapshotResponse.status).toBe(200);
    const snapshot = await snapshotResponse.json();
    const createdCase = snapshot.cases.find((item: { id: string }) => item.id === caseId);
    expect(createdCase).toBeTruthy();
    expect(createdCase.status).toBe("PROPOSED");
    expect(createdCase.risk_tier).toBe("HIGH");
    expect(createdCase.opening_reason).toBe("The reviewing officer independently confirmed this transaction pattern warrants a formal audit.");
    expect(createdCase.case_type).toBe("VAT_AUDIT");
    expect(createdCase.title).toBe("High-value transaction pattern review");
  });

  it("rejects a second decision once the indicator has already reached a terminal status", async () => {
    const indicatorId = await seedRiskIndicator("OPEN");
    await assign(indicatorId, NAMRA_OFFICER.userId);
    const first = await decide(indicatorId, { decision: "DISMISS", rationale: "The indicator was independently verified as a false positive against known evidence." });
    expect(first.status).toBe(200);
    const second = await decide(indicatorId, { decision: "DISMISS", rationale: "A second, differently-worded dismissal attempt against the same terminal indicator." });
    expect(second.status).toBe(409);
  });

  it("denies a taxpayer-side actor recording a decision", async () => {
    const indicatorId = await seedRiskIndicator("OPEN");
    await assign(indicatorId, NAMRA_OFFICER.userId);
    const response = await decide(indicatorId, { decision: "DISMISS", rationale: "The indicator was independently verified as a false positive against known evidence." }, TAXPAYER_OWNER);
    expect(response.status).toBe(403);
  });

  it("is idempotent on assignRiskReview under a repeated key and rejects a different payload under the same key", async () => {
    const indicatorId = await seedRiskIndicator("OPEN");
    const key = crypto.randomUUID();
    const first = await assign(indicatorId, NAMRA_OFFICER.userId, NAMRA_OFFICER, key);
    expect(first.status).toBe(200);
    const firstBody = await first.json();

    const retry = await assign(indicatorId, NAMRA_OFFICER.userId, NAMRA_OFFICER, key);
    expect(retry.status).toBe(200);
    expect((await retry.json()).resource.id).toBe(firstBody.resource.id);

    const { POST } = await import("@/app/api/v1/risk-indicators/[id]/assignment/route");
    actingAs(NAMRA_OFFICER);
    const conflicting = await POST(
      jsonRequest(`https://vat-msa.local/api/v1/risk-indicators/${indicatorId}/assignment`, { schema_version: "1.0.0", officer_id: NAMRA_SUPERVISOR.userId }, key),
      { params: Promise.resolve({ id: indicatorId }) },
    );
    expect(conflicting.status).toBe(409);
  });

  it("is idempotent on approveRiskAction under a repeated key", async () => {
    const indicatorId = await seedRiskIndicator("OPEN");
    await assign(indicatorId, NAMRA_OFFICER.userId);
    const key = crypto.randomUUID();
    const decideBody = { decision: "ESCALATE_TO_CASE", rationale: "The reviewing officer independently confirmed this transaction pattern warrants a formal audit.", case_type: "VAT_AUDIT", case_title: "Idempotent escalation" };
    const actor = nextNationalActor();
    const first = await decide(indicatorId, decideBody, actor, key);
    expect(first.status).toBe(200);
    const firstBody = await first.json();
    const retry = await decide(indicatorId, decideBody, actor, key);
    expect(retry.status).toBe(200);
    expect((await retry.json()).resource.escalated_case_id).toBe(firstBody.resource.escalated_case_id);
  });
});
