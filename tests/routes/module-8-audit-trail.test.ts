import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 8 Phase D: a 2026-08-26 audit found the hash-chained audit trail
 * genuinely wired as the sink for 117+ call sites across 8 repository
 * files — real evidence-grade coverage — but as 8 independently
 * hand-rolled, near-identical copies of the same hash-chaining logic
 * (`appendAudit`/`auditEnvelope`/`auditRecord`, plus one hand-inlined
 * duplicate in identity-repository.ts), exactly the kind of drift this
 * module's own Phase C watch-outs already warned about. All 8 files (a
 * 9th, business-repository.ts, deliberately kept its own two-stage design
 * — see lib/data/audit-repository.ts's own comment) now delegate to one
 * shared `appendAuditEvent`. `GetAuditTrail` (a filterable, paginated,
 * restricted read — previously only a Next.js page existed, no API route)
 * and `VerifyAuditChain` (a genuine tamper/corruption check, on-demand
 * since this deployment has no cron infrastructure, alerting through
 * Module 8 Phase B's own detection pipeline on a real break) are the two
 * previously entirely-missing pieces. Proven through the real route
 * handlers (app/api/v1/audit/..., dispatched via lib/api/audit.ts) and
 * lib/data/audit-repository.ts. See
 * tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const ANALYST: FixtureUser = { userId: "usr-adt-analyst", externalUserId: "ext-adt-analyst", email: "analyst@adt-test.test" };
const UNAUTHORISED: FixtureUser = { userId: "usr-adt-unauthorised", externalUserId: "ext-adt-unauthorised", email: "unauthorised@adt-test.test" };

function actingAs(user: FixtureUser): void {
  __setRequestHeaders({ "oai-authenticated-user-id": user.externalUserId, "oai-authenticated-user-email": user.email });
}

function jsonRequest(url: string, body: unknown, idempotencyKey = crypto.randomUUID()): Request {
  return new Request(url, { method: "POST", headers: { "content-type": "application/json", "idempotency-key": idempotencyKey }, body: JSON.stringify(body) });
}

async function seedFixture(): Promise<void> {
  const db = env.DB;
  const now = "2026-08-01T00:00:00.000Z";
  await db.batch([
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-adt-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,NULL,?,?)`)
      .bind(ANALYST.userId, ANALYST.externalUserId, ANALYST.email, "Security Analyst", "SECURITY_ANALYST", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,NULL,?,?)`)
      .bind(UNAUTHORISED.userId, UNAUTHORISED.externalUserId, UNAUTHORISED.email, "Taxpayer Staff", "TAXPAYER_STAFF", "ACTIVE", now),
    ...[ANALYST, UNAUTHORISED].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-adt-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
    db.prepare(`INSERT INTO security_detection_rules
      (id,code,name,description,event_type,group_by,threshold_count,window_minutes,severity,status,created_at)
      VALUES ('secrule-audit-chain-breach','AUDIT_CHAIN_INTEGRITY_BREACH','Audit chain integrity breach','Opens a CRITICAL incident the moment a chain-verification run finds a broken audit chain.','AUDIT_CHAIN_BREAK','actor_id',1,1440,'CRITICAL','ACTIVE',?)`).bind(now),
  ]);
}

async function createIncidentRoute(actor: FixtureUser, title: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/security/incidents/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/security/incidents", { schema_version: "1.0.0", title, severity: "LOW", details: "Seed row for the audit-trail test suite." }));
}

async function auditSearchRoute(actor: FixtureUser, query = ""): Promise<Response> {
  const { GET } = await import("@/app/api/v1/audit/route");
  actingAs(actor);
  return GET(new Request(`https://vat-msa.local/api/v1/audit${query}`));
}

async function triggerVerificationRoute(actor: FixtureUser): Promise<Response> {
  const { POST } = await import("@/app/api/v1/audit/chain-verifications/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/audit/chain-verifications", {}));
}

async function listVerificationsRoute(actor: FixtureUser): Promise<Response> {
  const { GET } = await import("@/app/api/v1/audit/chain-verifications/route");
  actingAs(actor);
  return GET(new Request("https://vat-msa.local/api/v1/audit/chain-verifications"));
}

async function socQueueRoute(actor: FixtureUser, query = ""): Promise<Response> {
  const { GET } = await import("@/app/api/v1/security/incidents/route");
  actingAs(actor);
  return GET(new Request(`https://vat-msa.local/api/v1/security/incidents${query}`));
}

describe("Module 8 audit trail integration: unified hash-chain writer, GetAuditTrail, VerifyAuditChain (Phase D)", () => {
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

  it("writes a real, chained audit trail through the now-unified writer as ordinary commands run", async () => {
    const first = await createIncidentRoute(ANALYST, "First seed incident");
    const second = await createIncidentRoute(ANALYST, "Second seed incident");
    const third = await createIncidentRoute(ANALYST, "Third seed incident");
    expect(first.status).toBe(201);
    expect(second.status).toBe(201);
    expect(third.status).toBe(201);

    const rows = await env.DB.prepare("SELECT COUNT(*) AS count FROM audit_events WHERE action='SECURITY_INCIDENT_OPENED'").first<{ count: number }>();
    expect(Number(rows?.count ?? 0)).toBe(3);
  });

  it("searches the audit trail filtered by action, with a real paginated total_count", async () => {
    const filtered = await auditSearchRoute(ANALYST, "?action=SECURITY_INCIDENT_OPENED");
    expect(filtered.status).toBe(200);
    const filteredBody = await filtered.json();
    expect(filteredBody.totalCount).toBe(3);
    expect(filteredBody.items).toHaveLength(3);

    const paged = await auditSearchRoute(ANALYST, "?action=SECURITY_INCIDENT_OPENED&limit=1");
    const pagedBody = await paged.json();
    expect(pagedBody.items).toHaveLength(1);
    expect(pagedBody.totalCount).toBe(3);
  });

  it("verifies the chain as PASSED against a genuinely untampered trail", async () => {
    const response = await triggerVerificationRoute(ANALYST);
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.verification.status).toBe("PASSED");
    expect(body.verification.verifiedCount).toBeGreaterThanOrEqual(3);
    expect(body.verification.firstBreakId).toBeNull();
  });

  it("detects a genuinely tampered row, persists the failed run, and opens a CRITICAL incident through the existing detection pipeline", async () => {
    const target = await env.DB.prepare("SELECT id FROM audit_events WHERE action='SECURITY_INCIDENT_OPENED' ORDER BY occurred_at ASC LIMIT 1").first<{ id: string }>();
    expect(target?.id).toBeTruthy();
    await env.DB.prepare("UPDATE audit_events SET details=? WHERE id=?").bind('{"details":"tampered"}', target!.id).run();

    const response = await triggerVerificationRoute(ANALYST);
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.verification.status).toBe("FAILED");
    expect(body.verification.firstBreakId).toBe(target!.id);
    expect(body.verification.firstBreakReason).toBe("EVENT_HASH_MISMATCH");

    const history = await listVerificationsRoute(ANALYST);
    const historyBody = await history.json();
    expect(historyBody.verifications.some((run: { status: string }) => run.status === "FAILED")).toBe(true);
    expect(historyBody.verifications.some((run: { status: string }) => run.status === "PASSED")).toBe(true);

    const incidents = await socQueueRoute(ANALYST, "?severity=CRITICAL");
    const incidentsBody = await incidents.json();
    const breachIncident = incidentsBody.incidents.find((item: { detection_rule_id: string | null }) => item.detection_rule_id === "secrule-audit-chain-breach");
    expect(breachIncident).toBeTruthy();
    expect(breachIncident.severity).toBe("CRITICAL");
  });

  it("denies audit trail access and chain verification to an actor without audit:read", async () => {
    expect((await auditSearchRoute(UNAUTHORISED)).status).toBe(403);
    expect((await triggerVerificationRoute(UNAUTHORISED)).status).toBe(403);
  });
});
