import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 8 Phase B: `security_incidents` existed with zero `INSERT`/`UPDATE`
 * anywhere in this codebase — a permanently-empty table silently rendering
 * "no incidents" on `/security`, not because the system was healthy but
 * because nothing ever wrote a row. This phase builds a real detection
 * pipeline (a small, fixed rule catalogue evaluated inline whenever
 * `recordSecurityEvent` is called — no cron infrastructure exists to poll
 * with) plus the full CreateIncident/Contain/Revoke/Close lifecycle, with
 * `Revoke` performing a genuine technical action (revoking the subject's
 * active `identity_links`, Module 1's own session-revocation mechanism).
 * Proven through the real route handlers (app/api/v1/security/incidents...,
 * dispatched via lib/api/security.ts) and
 * lib/data/security-repository.ts/lib/security/request.ts's
 * evaluateDetectionRules. See tests/routes/module-1-access-control.test.ts
 * for why this needs the cloudflare:workers/next/headers fakes and the
 * fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const ANALYST: FixtureUser = { userId: "usr-sec-analyst", externalUserId: "ext-sec-analyst", email: "analyst@sec-test.test" };
const TARGET_USER: FixtureUser = { userId: "usr-sec-target", externalUserId: "ext-sec-target", email: "target@sec-test.test" };
const UNAUTHORISED: FixtureUser = { userId: "usr-sec-unauthorised", externalUserId: "ext-sec-unauthorised", email: "unauthorised@sec-test.test" };

function actingAs(user: FixtureUser): void {
  __setRequestHeaders({ "oai-authenticated-user-id": user.externalUserId, "oai-authenticated-user-email": user.email });
}

function jsonRequest(url: string, body: unknown, options: { idempotencyKey?: string; stepUp?: boolean } = {}): Request {
  return new Request(url, {
    method: "POST",
    headers: {
      "content-type": "application/json",
      "idempotency-key": options.idempotencyKey ?? crypto.randomUUID(),
      ...(options.stepUp ? { "x-vat-msa-auth-assurance": "MFA_STEP_UP", "x-vat-msa-reauthenticated-at": new Date().toISOString() } : {}),
    },
    body: JSON.stringify(body),
  });
}

async function seedFixture(): Promise<void> {
  const db = env.DB;
  const now = "2026-08-01T00:00:00.000Z";
  await db.batch([
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-sec-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,NULL,?,?)`)
      .bind(ANALYST.userId, ANALYST.externalUserId, ANALYST.email, "Security Analyst", "SECURITY_ANALYST", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,NULL,?,?)`)
      .bind(UNAUTHORISED.userId, UNAUTHORISED.externalUserId, UNAUTHORISED.email, "Unauthorised Actor", "INTERNAL_AUDITOR", "ACTIVE", now),
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-sec-a", "VAT-SEC-A", "TIN-SEC-A", "Security Test Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Security Street", "finance@sec-test.test", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(TARGET_USER.userId, TARGET_USER.externalUserId, TARGET_USER.email, "Target User", "TAXPAYER_OWNER", "tp-sec-a", "ACTIVE", now),
    ...[ANALYST, TARGET_USER, UNAUTHORISED].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-sec-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
    db.prepare(`INSERT INTO security_detection_rules
      (id,code,name,description,event_type,group_by,threshold_count,window_minutes,severity,status,created_at)
      VALUES ('secrule-repeated-denials','REPEATED_AUTHORISATION_DENIALS','Repeated authorisation denials','Opens an incident when the same actor accumulates repeated access-denied events in a short window.','AUTHORISATION_DENIED','actor_id',5,15,'HIGH','ACTIVE',?)`).bind(now),
    db.prepare(`INSERT INTO security_detection_rules
      (id,code,name,description,event_type,group_by,threshold_count,window_minutes,severity,status,created_at)
      VALUES ('secrule-rate-limit-abuse','RATE_LIMIT_ABUSE','Rate limit abuse','Opens an incident when the same source repeatedly trips a rate limit in a short window.','RATE_LIMIT_EXCEEDED','source_token',10,10,'MEDIUM','ACTIVE',?)`).bind(now),
  ]);
}

async function socQueueRoute(actor: FixtureUser, query = ""): Promise<Response> {
  const { GET } = await import("@/app/api/v1/security/incidents/route");
  actingAs(actor);
  return GET(new Request(`https://vat-msa.local/api/v1/security/incidents${query}`));
}

async function incidentDetailRoute(incidentId: string, actor: FixtureUser): Promise<Response> {
  const { GET } = await import("@/app/api/v1/security/incidents/[id]/route");
  actingAs(actor);
  return GET(new Request(`https://vat-msa.local/api/v1/security/incidents/${incidentId}`), { params: Promise.resolve({ id: incidentId }) });
}

async function createIncidentRoute(actor: FixtureUser, body: Record<string, unknown>): Promise<Response> {
  const { POST } = await import("@/app/api/v1/security/incidents/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/security/incidents", { schema_version: "1.0.0", ...body }));
}

async function containRoute(incidentId: string, actor: FixtureUser, notes: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/security/incidents/[id]/containment/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/security/incidents/${incidentId}/containment`, { schema_version: "1.0.0", notes }), { params: Promise.resolve({ id: incidentId }) });
}

async function revokeRoute(incidentId: string, actor: FixtureUser, notes: string, options: { stepUp?: boolean; idempotencyKey?: string } = {}): Promise<Response> {
  const { POST } = await import("@/app/api/v1/security/incidents/[id]/revocation/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/security/incidents/${incidentId}/revocation`, { schema_version: "1.0.0", notes }, options), { params: Promise.resolve({ id: incidentId }) });
}

async function closeRoute(incidentId: string, actor: FixtureUser, resolutionNotes: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/security/incidents/[id]/closure/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/security/incidents/${incidentId}/closure`, { schema_version: "1.0.0", resolution_notes: resolutionNotes }), { params: Promise.resolve({ id: incidentId }) });
}

describe("Module 8 security telemetry & incident model: detection, CreateIncident, Contain/Revoke/Close (Phase B)", () => {
  let detectedIncidentId: string;

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

  it("auto-opens an incident once a detection rule's threshold is reached, and de-duplicates further events past the threshold", async () => {
    const { recordSecurityEvent } = await import("@/lib/security/request");
    const context = { correlationId: crypto.randomUUID(), sourceToken: "sha256:test-source-token", deviceId: "test-device" };
    for (let i = 0; i < 5; i += 1) {
      await recordSecurityEvent({ eventType: "AUTHORISATION_DENIED", severity: "HIGH", actorId: TARGET_USER.userId, context, action: "TEST_PROBE", outcome: "DENIED", details: { attempt: i } });
    }

    const queue = await socQueueRoute(ANALYST, "?status=OPEN");
    expect(queue.status).toBe(200);
    const queueBody = await queue.json();
    const detected = queueBody.incidents.find((item: { detection_rule_id: string | null; group_key: string | null }) => item.detection_rule_id === "secrule-repeated-denials" && item.group_key === TARGET_USER.userId);
    expect(detected).toBeTruthy();
    expect(detected.subject_user_id).toBe(TARGET_USER.userId);
    detectedIncidentId = detected.id as string;

    // A 6th and 7th event past the threshold must not spawn a second incident for the same actor.
    await recordSecurityEvent({ eventType: "AUTHORISATION_DENIED", severity: "HIGH", actorId: TARGET_USER.userId, context, action: "TEST_PROBE", outcome: "DENIED", details: { attempt: 5 } });
    const queueAfter = await socQueueRoute(ANALYST, "?status=OPEN");
    const matches = (await queueAfter.json()).incidents.filter((item: { id: string }) => item.id === detectedIncidentId);
    expect(matches).toHaveLength(1);
  });

  it("reads the detected incident's detail, including the automated DETECTED playbook action", async () => {
    const response = await incidentDetailRoute(detectedIncidentId, ANALYST);
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.incident.status).toBe("OPEN");
    const detectedAction = body.actions.find((action: { action_type: string }) => action.action_type === "DETECTED");
    expect(detectedAction).toBeTruthy();
    expect(detectedAction.automated).toBe(1);
  });

  it("requires step-up to revoke access, then genuinely revokes the subject's active identity links and advances the incident to CONTAINED", async () => {
    const withoutStepUp = await revokeRoute(detectedIncidentId, ANALYST, "Attempting revocation without step-up.");
    expect(withoutStepUp.status).toBe(403);

    const revoked = await revokeRoute(detectedIncidentId, ANALYST, "Revoking the compromised account's active sessions.", { stepUp: true });
    expect(revoked.status).toBe(200);
    const revokedBody = await revoked.json();
    expect(revokedBody.incident.status).toBe("CONTAINED");
    const revokeAction = revokedBody.actions.find((action: { action_type: string }) => action.action_type === "REVOKE");
    expect(revokeAction).toBeTruthy();
    expect(JSON.parse(revokeAction.details).revokedIdentityLinks).toBe(1);

    const link = await env.DB.prepare("SELECT status FROM identity_links WHERE id=?").bind(`ilink-${TARGET_USER.userId}`).first<{ status: string }>();
    expect(link?.status).toBe("REVOKED");

    const revokeAgain = await revokeRoute(detectedIncidentId, ANALYST, "Revoking again after the first pass.", { stepUp: true });
    expect(revokeAgain.status).toBe(200);
    const revokeAgainAction = (await revokeAgain.json()).actions.filter((action: { action_type: string }) => action.action_type === "REVOKE").pop();
    expect(JSON.parse(revokeAgainAction.details).revokedIdentityLinks).toBe(0);
  });

  it("closes the detected incident with resolution notes, and refuses a second close or a revoke on a closed incident", async () => {
    const closed = await closeRoute(detectedIncidentId, ANALYST, "Confirmed contained; subject account access fully revoked.");
    expect(closed.status).toBe(200);
    expect((await closed.json()).incident.status).toBe("CLOSED");

    const recloseAttempt = await closeRoute(detectedIncidentId, ANALYST, "Trying to close it a second time.");
    expect(recloseAttempt.status).toBe(409);

    const revokeAfterClose = await revokeRoute(detectedIncidentId, ANALYST, "Trying to revoke after closure.", { stepUp: true });
    expect(revokeAfterClose.status).toBe(409);
  });

  it("creates a manual incident, contains it, and refuses a second containment", async () => {
    const created = await createIncidentRoute(ANALYST, { title: "Suspicious bulk export request", severity: "MEDIUM", details: "Reported by the taxpayer's own compliance officer." });
    expect(created.status).toBe(201);
    const createdBody = await created.json();
    expect(createdBody.incident.status).toBe("OPEN");
    const openedAction = createdBody.actions.find((action: { action_type: string }) => action.action_type === "OPENED");
    expect(openedAction).toBeTruthy();
    const manualIncidentId = createdBody.incident.id as string;

    const contained = await containRoute(manualIncidentId, ANALYST, "Reviewed and stabilised; monitoring for further activity.");
    expect(contained.status).toBe(200);
    expect((await contained.json()).incident.status).toBe("CONTAINED");
    expect((await incidentDetailRoute(manualIncidentId, ANALYST).then((r) => r.json())).incident.owner).toBe(ANALYST.userId);

    const recontainAttempt = await containRoute(manualIncidentId, ANALYST, "Trying to contain it again.");
    expect(recontainAttempt.status).toBe(409);
  });

  it("returns 404 for actions against an unknown incident", async () => {
    const missingId = crypto.randomUUID();
    expect((await incidentDetailRoute(missingId, ANALYST)).status).toBe(404);
    expect((await containRoute(missingId, ANALYST, "Attempting on an unknown incident.")).status).toBe(404);
    expect((await revokeRoute(missingId, ANALYST, "Attempting on an unknown incident.", { stepUp: true })).status).toBe(404);
    expect((await closeRoute(missingId, ANALYST, "Attempting to close an unknown incident.")).status).toBe(404);
  });

  it("denies SOC queue access and incident creation to an actor without security:read/security:manage", async () => {
    expect((await socQueueRoute(UNAUTHORISED)).status).toBe(403);
    expect((await createIncidentRoute(UNAUTHORISED, { title: "Unauthorised attempt", severity: "LOW", details: "Should be refused before it is ever created." })).status).toBe(403);
  });
});
