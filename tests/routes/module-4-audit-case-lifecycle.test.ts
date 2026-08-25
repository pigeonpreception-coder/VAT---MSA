import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 4 Phase C: the audit case lifecycle state machine, proven through
 * the real route handlers (app/api/v1/audit-cases/[id]/transition,
 * .../findings and .../timeline, all dispatched via lib/api/compliance.ts)
 * and lib/data/compliance-repository.ts's transitionCase/issueFinding/
 * getCaseTimeline. See tests/routes/module-1-access-control.test.ts for why
 * this needs the cloudflare:workers/next/headers fakes and the fake D1.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const TAXPAYER_OWNER: FixtureUser = { userId: "usr-ac-taxpayer-owner", externalUserId: "ext-ac-taxpayer-owner", email: "owner@ac-taxpayer.test" };
const NAMRA_OFFICER: FixtureUser = { userId: "usr-ac-namra", externalUserId: "ext-ac-namra", email: "namra@ac.test" };
const NAMRA_SUPERVISOR: FixtureUser = { userId: "usr-ac-supervisor", externalUserId: "ext-ac-supervisor", email: "supervisor@ac.test" };
const INACTIVE_OFFICER: FixtureUser = { userId: "usr-ac-inactive", externalUserId: "ext-ac-inactive", email: "inactive@ac.test" };
const NAMRA_AUDITOR: FixtureUser = { userId: "usr-ac-auditor", externalUserId: "ext-ac-auditor", email: "auditor@ac.test" };
const NAMRA_OFFICER_2: FixtureUser = { userId: "usr-ac-namra-2", externalUserId: "ext-ac-namra-2", email: "namra2@ac.test" };

/**
 * handleComplianceCommand rate-limits each command to 30 requests per actor
 * per 60s window (lib/security/request.ts's enforceRateLimits). This suite
 * drives far more than 30 TRANSITION_CASE/ISSUE_FINDING calls in well under
 * a minute of wall-clock time, so default (unpinned) actors round-robin
 * across a pool of national-scope officers to stay under each actor's
 * bucket. Tests that need a *specific, stable* actor (idempotency-key replay,
 * permission-denial) pass an explicit actor instead.
 */
const NATIONAL_ACTOR_POOL: readonly FixtureUser[] = [NAMRA_OFFICER, NAMRA_SUPERVISOR, NAMRA_AUDITOR, NAMRA_OFFICER_2];
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
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-ac-taxpayer", "VAT-AC-001", "TIN-AC-001", "Audit Case Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Audit Street", "finance@ac-taxpayer.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-ac-taxpayer", "tp-ac-taxpayer", "Audit Case Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-ac-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(TAXPAYER_OWNER.userId, TAXPAYER_OWNER.externalUserId, TAXPAYER_OWNER.email, "Taxpayer Owner", "TAXPAYER_OWNER", "tp-ac-taxpayer", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_OFFICER.userId, NAMRA_OFFICER.externalUserId, NAMRA_OFFICER.email, "NamRA Officer", "NAMRA_COMPLIANCE_OFFICER", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_SUPERVISOR.userId, NAMRA_SUPERVISOR.externalUserId, NAMRA_SUPERVISOR.email, "NamRA Supervisor", "NAMRA_SUPERVISOR", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(INACTIVE_OFFICER.userId, INACTIVE_OFFICER.externalUserId, INACTIVE_OFFICER.email, "Inactive Officer", "NAMRA_COMPLIANCE_OFFICER", null, "SUSPENDED", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_AUDITOR.userId, NAMRA_AUDITOR.externalUserId, NAMRA_AUDITOR.email, "NamRA Auditor", "NAMRA_AUDITOR", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_OFFICER_2.userId, NAMRA_OFFICER_2.externalUserId, NAMRA_OFFICER_2.email, "NamRA Officer Two", "NAMRA_COMPLIANCE_OFFICER", null, "ACTIVE", now),
    ...[TAXPAYER_OWNER, NAMRA_OFFICER, NAMRA_SUPERVISOR, INACTIVE_OFFICER, NAMRA_AUDITOR, NAMRA_OFFICER_2].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-ac-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
  ]);
}

async function openCase(title: string): Promise<string> {
  const { POST } = await import("@/app/api/v1/audit-cases/route");
  actingAs(nextNationalActor());
  const response = await POST(jsonRequest("https://vat-msa.local/api/v1/audit-cases", {
    schema_version: "1.0.0", taxpayer_id: "tp-ac-taxpayer", case_type: "VAT_AUDIT", title,
    opening_reason: "Matched evidence fell below the controlled review threshold for the period.", risk_tier: "HIGH",
  }, crypto.randomUUID()));
  expect(response.status).toBe(201);
  const body = await response.json();
  expect(body.resource.status).toBe("PROPOSED");
  return body.resource.id as string;
}

async function transition(caseId: string, action: string, extra: Record<string, unknown> = {}, actor: FixtureUser = nextNationalActor(), key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/audit-cases/[id]/transition/route");
  actingAs(actor);
  return POST(
    jsonRequest(`https://vat-msa.local/api/v1/audit-cases/${caseId}/transition`, { schema_version: "1.0.0", action, reason: "Recorded as part of the automated lifecycle test walk.", ...extra }, key),
    { params: Promise.resolve({ id: caseId }) },
  );
}

async function issueFinding(caseId: string, findingCode: string, actor: FixtureUser = nextNationalActor(), key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/audit-cases/[id]/findings/route");
  actingAs(actor);
  return POST(
    jsonRequest(`https://vat-msa.local/api/v1/audit-cases/${caseId}/findings`, {
      schema_version: "1.0.0", finding_code: findingCode, title: "Underdeclared output VAT for the period",
      description: "Sampled invoices show output VAT amounts below the rate applicable to the declared supply category.",
      amount_cents: 250_000, currency: "NAD",
    }, key),
    { params: Promise.resolve({ id: caseId }) },
  );
}

describe("Module 4 audit case lifecycle (Phase C)", () => {
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

  it("walks a case through the full happy-path lifecycle to CLOSED", async () => {
    const caseId = await openCase("Full lifecycle walk");

    expect((await (await transition(caseId, "AUTHORIZE")).json()).resource.status).toBe("AUTHORIZED");
    expect((await (await transition(caseId, "ASSIGN", { officer_id: NAMRA_OFFICER.userId })).json()).resource.status).toBe("ASSIGNED");
    expect((await (await transition(caseId, "ADVANCE")).json()).resource.status).toBe("PLANNING");
    expect((await (await transition(caseId, "ADVANCE")).json()).resource.status).toBe("EVIDENCE_COLLECTION");
    expect((await (await transition(caseId, "ADVANCE")).json()).resource.status).toBe("ANALYSIS");

    const findingResponse = await issueFinding(caseId, "finding-lifecycle-0001");
    expect(findingResponse.status).toBe(201);
    expect((await findingResponse.json()).resource.status).toBe("PRELIMINARY");

    expect((await (await transition(caseId, "ADVANCE")).json()).resource.status).toBe("TAXPAYER_RESPONSE");
    expect((await (await transition(caseId, "ADVANCE")).json()).resource.status).toBe("FINDINGS_REVIEW");
    expect((await (await transition(caseId, "ADVANCE")).json()).resource.status).toBe("DECISION");

    const closed = await transition(caseId, "CLOSE");
    expect(closed.status).toBe(200);
    const closedBody = await closed.json();
    expect(closedBody.resource.status).toBe("CLOSED");
    expect(closedBody.resource.closed_at).toBeTruthy();
  });

  it("rejects an illegal transition for the case's current status", async () => {
    const caseId = await openCase("Illegal transition rejection");
    const response = await transition(caseId, "ADVANCE");
    expect(response.status).toBe(422);
    const body = await response.json();
    expect(body.errors[0].code).toBe("CASE_TRANSITION_INVALID");
  });

  it("suspends and resumes a case back into its pre-suspension status", async () => {
    const caseId = await openCase("Suspend and resume");
    await transition(caseId, "AUTHORIZE");
    await transition(caseId, "ASSIGN", { officer_id: NAMRA_OFFICER.userId });
    await transition(caseId, "ADVANCE");

    const suspended = await transition(caseId, "SUSPEND");
    expect((await suspended.json()).resource.status).toBe("SUSPENDED");

    const resumed = await transition(caseId, "RESUME");
    expect(resumed.status).toBe(200);
    expect((await resumed.json()).resource.status).toBe("PLANNING");
  });

  it("cancels a case from PROPOSED and rejects cancellation once ASSIGNED", async () => {
    const proposedCaseId = await openCase("Cancel from proposed");
    const cancelled = await transition(proposedCaseId, "CANCEL");
    expect((await cancelled.json()).resource.status).toBe("CANCELLED");

    const assignedCaseId = await openCase("Cancel rejected once assigned");
    await transition(assignedCaseId, "AUTHORIZE");
    await transition(assignedCaseId, "ASSIGN", { officer_id: NAMRA_OFFICER.userId });
    const rejected = await transition(assignedCaseId, "CANCEL");
    expect(rejected.status).toBe(422);
  });

  it("reopens a CLOSED case into FINDINGS_REVIEW and links an appeal reference", async () => {
    const caseId = await openCase("Reopen and link appeal");
    await transition(caseId, "AUTHORIZE");
    await transition(caseId, "ASSIGN", { officer_id: NAMRA_OFFICER.userId });
    await transition(caseId, "ADVANCE");
    await transition(caseId, "ADVANCE");
    await transition(caseId, "ADVANCE");
    await issueFinding(caseId, "finding-reopen-0001");
    await transition(caseId, "ADVANCE");
    await transition(caseId, "ADVANCE");
    await transition(caseId, "ADVANCE");
    await transition(caseId, "CLOSE");

    const reopened = await transition(caseId, "REOPEN");
    expect((await reopened.json()).resource.status).toBe("FINDINGS_REVIEW");

    await transition(caseId, "ADVANCE");
    await transition(caseId, "CLOSE");
    const linked = await transition(caseId, "LINK_APPEAL", { appeal_reference: "APPEAL-2026-0099" });
    const linkedBody = await linked.json();
    expect(linkedBody.resource.status).toBe("CLOSED");
    expect(linkedBody.resource.appeal_reference).toBe("APPEAL-2026-0099");
  });

  it("validates the assigned officer exists and is active on ASSIGN", async () => {
    const missingOfficerCase = await openCase("Assign to missing officer");
    await transition(missingOfficerCase, "AUTHORIZE");
    const missing = await transition(missingOfficerCase, "ASSIGN", { officer_id: crypto.randomUUID() });
    expect(missing.status).toBe(404);

    const inactiveOfficerCase = await openCase("Assign to inactive officer");
    await transition(inactiveOfficerCase, "AUTHORIZE");
    const inactive = await transition(inactiveOfficerCase, "ASSIGN", { officer_id: INACTIVE_OFFICER.userId });
    expect(inactive.status).toBe(409);
  });

  it("rejects CLOSE when the case has no findings on record", async () => {
    const caseId = await openCase("Close without findings");
    await transition(caseId, "AUTHORIZE");
    await transition(caseId, "ASSIGN", { officer_id: NAMRA_OFFICER.userId });
    await transition(caseId, "ADVANCE");
    await transition(caseId, "ADVANCE");
    await transition(caseId, "ADVANCE");
    await transition(caseId, "ADVANCE");
    await transition(caseId, "ADVANCE");
    await transition(caseId, "ADVANCE");
    const closed = await transition(caseId, "CLOSE");
    expect(closed.status).toBe(409);
  });

  it("denies a taxpayer-side actor transitioning a case (national-scope only)", async () => {
    const caseId = await openCase("Denied taxpayer transition");
    const response = await transition(caseId, "AUTHORIZE", {}, TAXPAYER_OWNER);
    expect(response.status).toBe(403);
  });

  it("is idempotent on transitionCase under a repeated key and rejects a different payload under the same key", async () => {
    const caseId = await openCase("Idempotent transition");
    const key = crypto.randomUUID();
    const first = await transition(caseId, "AUTHORIZE", {}, NAMRA_OFFICER, key);
    expect(first.status).toBe(200);
    const firstBody = await first.json();
    expect(firstBody.resource.status).toBe("AUTHORIZED");

    const retry = await transition(caseId, "AUTHORIZE", {}, NAMRA_OFFICER, key);
    expect(retry.status).toBe(200);
    expect((await retry.json()).resource.id).toBe(firstBody.resource.id);

    const { POST } = await import("@/app/api/v1/audit-cases/[id]/transition/route");
    actingAs(NAMRA_OFFICER);
    const conflicting = await POST(
      jsonRequest(`https://vat-msa.local/api/v1/audit-cases/${caseId}/transition`, { schema_version: "1.0.0", action: "AUTHORIZE", reason: "A materially different reason text than the first request used." }, key),
      { params: Promise.resolve({ id: caseId }) },
    );
    expect(conflicting.status).toBe(409);
  });

  it("restricts IssueFinding to the case's analytical stages and rejects a duplicate finding_code", async () => {
    const proposedCaseId = await openCase("Finding rejected before analysis");
    const tooEarly = await issueFinding(proposedCaseId, "finding-too-early-0001");
    expect(tooEarly.status).toBe(409);

    const caseId = await openCase("Duplicate finding code rejected");
    await transition(caseId, "AUTHORIZE");
    await transition(caseId, "ASSIGN", { officer_id: NAMRA_OFFICER.userId });
    await transition(caseId, "ADVANCE");
    await transition(caseId, "ADVANCE");
    await transition(caseId, "ADVANCE");

    const first = await issueFinding(caseId, "finding-duplicate-0001");
    expect(first.status).toBe(201);
    const duplicate = await issueFinding(caseId, "finding-duplicate-0001");
    expect(duplicate.status).toBe(409);
  });

  it("is idempotent on issueFinding under a repeated key", async () => {
    const caseId = await openCase("Idempotent finding issuance");
    await transition(caseId, "AUTHORIZE");
    await transition(caseId, "ASSIGN", { officer_id: NAMRA_OFFICER.userId });
    await transition(caseId, "ADVANCE");
    await transition(caseId, "ADVANCE");
    await transition(caseId, "ADVANCE");

    const key = crypto.randomUUID();
    const first = await issueFinding(caseId, "finding-idempotent-0001", NAMRA_OFFICER, key);
    expect(first.status).toBe(201);
    const firstBody = await first.json();
    const retry = await issueFinding(caseId, "finding-idempotent-0001", NAMRA_OFFICER, key);
    expect(retry.status).toBe(201);
    expect((await retry.json()).resource.id).toBe(firstBody.resource.id);
  });

  it("returns a case's transition history in chronological order via CaseTimeline, tenant-scoped", async () => {
    const caseId = await openCase("Timeline chronology");
    await transition(caseId, "AUTHORIZE");
    await transition(caseId, "ASSIGN", { officer_id: NAMRA_OFFICER.userId });

    const { GET } = await import("@/app/api/v1/audit-cases/[id]/timeline/route");

    actingAs(NAMRA_SUPERVISOR);
    const nationalResponse = await GET(new Request(`https://vat-msa.local/api/v1/audit-cases/${caseId}/timeline`), { params: Promise.resolve({ id: caseId }) });
    expect(nationalResponse.status).toBe(200);
    const nationalBody = await nationalResponse.json();
    expect(nationalBody.case.id).toBe(caseId);
    const actions = nationalBody.transitions.map((row: { action: string }) => row.action);
    expect(actions).toEqual(["AUTHORIZE", "ASSIGN"]);
    for (let i = 1; i < nationalBody.transitions.length; i += 1) {
      expect(nationalBody.transitions[i].occurred_at >= nationalBody.transitions[i - 1].occurred_at).toBe(true);
    }

    actingAs(TAXPAYER_OWNER);
    const ownScope = await GET(new Request(`https://vat-msa.local/api/v1/audit-cases/${caseId}/timeline`), { params: Promise.resolve({ id: caseId }) });
    expect(ownScope.status).toBe(200);
  });

  it("returns 404 for a non-existent case's timeline", async () => {
    const { GET } = await import("@/app/api/v1/audit-cases/[id]/timeline/route");
    actingAs(NAMRA_OFFICER);
    const missingId = crypto.randomUUID();
    const response = await GET(new Request(`https://vat-msa.local/api/v1/audit-cases/${missingId}/timeline`), { params: Promise.resolve({ id: missingId }) });
    expect(response.status).toBe(404);
  });
});
