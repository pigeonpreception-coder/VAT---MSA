import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 6 Phase C: the case correspondence write path. The `communications`
 * table already existed but was read-only — a full-repo grep before this
 * phase found zero `INSERT INTO communications` anywhere in application
 * code. SendNotice/Respond/CloseConversation/GetInbox close that gap,
 * proven through the real route handlers (app/api/v1/communications,
 * .../notices, .../[id]/responses, .../[id]/closure, dispatched via
 * lib/api/compliance.ts) and lib/data/compliance-repository.ts. See
 * tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const TAXPAYER_OWNER: FixtureUser = { userId: "usr-cc-owner", externalUserId: "ext-cc-owner", email: "owner@cc-taxpayer.test" };
const OTHER_TAXPAYER_OWNER: FixtureUser = { userId: "usr-cc-other-owner", externalUserId: "ext-cc-other-owner", email: "owner@cc-other-taxpayer.test" };
const NAMRA_OFFICER: FixtureUser = { userId: "usr-cc-namra", externalUserId: "ext-cc-namra", email: "namra@cc.test" };
const NAMRA_OFFICER_2: FixtureUser = { userId: "usr-cc-namra-2", externalUserId: "ext-cc-namra-2", email: "namra2@cc.test" };
const NAMRA_AUDITOR: FixtureUser = { userId: "usr-cc-auditor", externalUserId: "ext-cc-auditor", email: "auditor@cc.test" };

/** handleComplianceCommand rate-limits each command to 30/actor/60s; round-robin officer actors for tests that don't need a specific one. */
const OFFICER_POOL: readonly FixtureUser[] = [NAMRA_OFFICER, NAMRA_OFFICER_2];
let officerIndex = 0;
function nextOfficer(): FixtureUser {
  const actor = OFFICER_POOL[officerIndex % OFFICER_POOL.length];
  officerIndex += 1;
  return actor;
}

function actingAs(user: FixtureUser): void {
  __setRequestHeaders({ "oai-authenticated-user-id": user.externalUserId, "oai-authenticated-user-email": user.email });
}

function jsonRequest(url: string, body: unknown, idempotencyKey = crypto.randomUUID()): Request {
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
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-cc-taxpayer", "VAT-CC-001", "TIN-CC-001", "Correspondence Test Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Correspondence Street", "finance@cc-taxpayer.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-cc-taxpayer", "tp-cc-taxpayer", "Correspondence Test Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-cc-other", "VAT-CC-002", "TIN-CC-002", "Other Correspondence Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "2 Correspondence Street", "finance@cc-other.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-cc-other", "tp-cc-other", "Other Correspondence Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-cc-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(TAXPAYER_OWNER.userId, TAXPAYER_OWNER.externalUserId, TAXPAYER_OWNER.email, "Owner", "TAXPAYER_OWNER", "tp-cc-taxpayer", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OTHER_TAXPAYER_OWNER.userId, OTHER_TAXPAYER_OWNER.externalUserId, OTHER_TAXPAYER_OWNER.email, "Other Owner", "TAXPAYER_OWNER", "tp-cc-other", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_OFFICER.userId, NAMRA_OFFICER.externalUserId, NAMRA_OFFICER.email, "NamRA Officer", "NAMRA_COMPLIANCE_OFFICER", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_OFFICER_2.userId, NAMRA_OFFICER_2.externalUserId, NAMRA_OFFICER_2.email, "NamRA Officer Two", "NAMRA_COMPLIANCE_OFFICER", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_AUDITOR.userId, NAMRA_AUDITOR.externalUserId, NAMRA_AUDITOR.email, "NamRA Auditor", "NAMRA_AUDITOR", null, "ACTIVE", now),
    ...[TAXPAYER_OWNER, OTHER_TAXPAYER_OWNER, NAMRA_OFFICER, NAMRA_OFFICER_2, NAMRA_AUDITOR].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-cc-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
    db.prepare(`INSERT INTO audit_cases (id,case_number,organisation_id,taxpayer_id,case_type,title,opening_reason,risk_tier,status,opened_by,opened_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?)`).bind("case-cc-0001", "CASE-CC-0001", "org-cc-taxpayer", "tp-cc-taxpayer", "DESK_REVIEW", "Correspondence test case", "Testing case correspondence.", "MEDIUM", "PROPOSED", NAMRA_OFFICER.userId, now, now),
    db.prepare(`INSERT INTO audit_cases (id,case_number,organisation_id,taxpayer_id,case_type,title,opening_reason,risk_tier,status,opened_by,opened_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?)`).bind("case-cc-0002", "CASE-CC-0002", "org-cc-taxpayer", "tp-cc-taxpayer", "DESK_REVIEW", "Second correspondence test case", "Testing inbox filtering.", "LOW", "PROPOSED", NAMRA_OFFICER.userId, now, now),
  ]);
}

function noticePayload(overrides: Record<string, unknown> = {}) {
  return {
    schema_version: "1.0.0",
    related_resource_type: "AUDIT_CASE",
    related_resource_id: "case-cc-0001",
    channel: "PORTAL",
    subject: "Evidence requested for desk review",
    content_summary: "Please provide the supporting invoices referenced in your VAT return for this period.",
    classification: "TAX_CONFIDENTIAL",
    ...overrides,
  };
}

async function sendNoticeRoute(actor: FixtureUser, overrides: Record<string, unknown> = {}, key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/communications/notices/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/communications/notices", noticePayload(overrides), key));
}

async function respondRoute(threadId: string, actor: FixtureUser, contentSummary: string, key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/communications/[id]/responses/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/communications/${threadId}/responses`, { schema_version: "1.0.0", channel: "PORTAL", content_summary: contentSummary }, key), { params: Promise.resolve({ id: threadId }) });
}

async function closeConversationRoute(threadId: string, actor: FixtureUser, reason: string, key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/communications/[id]/closure/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/communications/${threadId}/closure`, { schema_version: "1.0.0", reason }, key), { params: Promise.resolve({ id: threadId }) });
}

async function inboxRoute(actor: FixtureUser, query: Record<string, string> = {}): Promise<Response> {
  const { GET } = await import("@/app/api/v1/communications/route");
  actingAs(actor);
  const qs = new URLSearchParams(query).toString();
  return GET(new Request(`https://vat-msa.local/api/v1/communications${qs ? `?${qs}` : ""}`));
}

async function conversationRoute(threadId: string, actor: FixtureUser): Promise<Response> {
  const { GET } = await import("@/app/api/v1/communications/[id]/route");
  actingAs(actor);
  return GET(new Request(`https://vat-msa.local/api/v1/communications/${threadId}`), { params: Promise.resolve({ id: threadId }) });
}

describe("Module 6 case correspondence (Phase C)", () => {
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

  it("sends a notice, opening a new OPEN correspondence thread for an audit case", async () => {
    const response = await sendNoticeRoute(nextOfficer());
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.resource.status).toBe("OPEN");
    expect(body.resource.taxpayer_id).toBe("tp-cc-taxpayer");
    expect(body.resource.related_resource_type).toBe("AUDIT_CASE");
  });

  it("rejects sending a second notice for the same case reference", async () => {
    const first = await sendNoticeRoute(nextOfficer(), { related_resource_id: "case-cc-0002" });
    expect(first.status).toBe(201);
    const second = await sendNoticeRoute(nextOfficer(), { related_resource_id: "case-cc-0002" });
    expect(second.status).toBe(409);
  });

  it("denies a taxpayer-side actor from sending a notice", async () => {
    const response = await sendNoticeRoute(TAXPAYER_OWNER, { related_resource_id: "case-cc-0001" });
    expect(response.status).toBe(403);
  });

  it("returns 404 sending a notice against an unresolvable case reference (including a non-AUDIT_CASE type)", async () => {
    const response = await sendNoticeRoute(nextOfficer(), { related_resource_type: "REFUND_CLAIM", related_resource_id: crypto.randomUUID() });
    expect(response.status).toBe(404);
  });

  it("allows both the officer and the taxpayer to respond within an open thread", async () => {
    await env.DB.batch([
      env.DB.prepare(`INSERT INTO audit_cases (id,case_number,organisation_id,taxpayer_id,case_type,title,opening_reason,risk_tier,status,opened_by,opened_at,updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)`).bind("case-cc-0003", "CASE-CC-0003", "org-cc-taxpayer", "tp-cc-taxpayer", "DESK_REVIEW", "Third correspondence test case", "Testing respond.", "LOW", "PROPOSED", NAMRA_OFFICER.userId, "2026-08-01T00:00:00.000Z", "2026-08-01T00:00:00.000Z"),
    ]);
    const opened = await sendNoticeRoute(nextOfficer(), { related_resource_id: "case-cc-0003" });
    expect(opened.status).toBe(201);
    const threadId = (await opened.json()).resource.id as string;

    const taxpayerReply = await respondRoute(threadId, TAXPAYER_OWNER, "We are gathering the requested invoices now.");
    expect(taxpayerReply.status).toBe(201);
    expect((await taxpayerReply.json()).resource.direction).toBe("INBOUND");

    const officerReply = await respondRoute(threadId, nextOfficer(), "Thank you, please submit them within five business days.");
    expect(officerReply.status).toBe(201);
    expect((await officerReply.json()).resource.direction).toBe("OUTBOUND");

    const conversation = await conversationRoute(threadId, TAXPAYER_OWNER);
    expect(conversation.status).toBe(200);
    const conversationBody = await conversation.json();
    expect(conversationBody.messages).toHaveLength(3);
    expect(conversationBody.messages.map((m: { direction: string }) => m.direction)).toEqual(["OUTBOUND", "INBOUND", "OUTBOUND"]);
  });

  it("rejects a reply from an actor holding neither communications:manage nor communications:respond", async () => {
    await env.DB.batch([
      env.DB.prepare(`INSERT INTO audit_cases (id,case_number,organisation_id,taxpayer_id,case_type,title,opening_reason,risk_tier,status,opened_by,opened_at,updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)`).bind("case-cc-0004", "CASE-CC-0004", "org-cc-taxpayer", "tp-cc-taxpayer", "DESK_REVIEW", "Fourth correspondence test case", "Testing denial.", "LOW", "PROPOSED", NAMRA_OFFICER.userId, "2026-08-01T00:00:00.000Z", "2026-08-01T00:00:00.000Z"),
    ]);
    const opened = await sendNoticeRoute(nextOfficer(), { related_resource_id: "case-cc-0004" });
    const threadId = (await opened.json()).resource.id as string;
    const response = await respondRoute(threadId, NAMRA_AUDITOR, "An auditor attempting to reply.");
    expect(response.status).toBe(403);
  });

  it("rejects a taxpayer replying to a correspondence thread outside their taxpayer scope", async () => {
    await env.DB.batch([
      env.DB.prepare(`INSERT INTO audit_cases (id,case_number,organisation_id,taxpayer_id,case_type,title,opening_reason,risk_tier,status,opened_by,opened_at,updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)`).bind("case-cc-0005", "CASE-CC-0005", "org-cc-taxpayer", "tp-cc-taxpayer", "DESK_REVIEW", "Fifth correspondence test case", "Testing cross-tenant denial.", "LOW", "PROPOSED", NAMRA_OFFICER.userId, "2026-08-01T00:00:00.000Z", "2026-08-01T00:00:00.000Z"),
    ]);
    const opened = await sendNoticeRoute(nextOfficer(), { related_resource_id: "case-cc-0005" });
    const threadId = (await opened.json()).resource.id as string;
    const response = await respondRoute(threadId, OTHER_TAXPAYER_OWNER, "An unrelated taxpayer attempting to reply.");
    expect(response.status).toBe(403);
  });

  it("closes a conversation as an officer, rejects a second close, and rejects a reply once closed", async () => {
    await env.DB.batch([
      env.DB.prepare(`INSERT INTO audit_cases (id,case_number,organisation_id,taxpayer_id,case_type,title,opening_reason,risk_tier,status,opened_by,opened_at,updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)`).bind("case-cc-0006", "CASE-CC-0006", "org-cc-taxpayer", "tp-cc-taxpayer", "DESK_REVIEW", "Sixth correspondence test case", "Testing closure.", "LOW", "PROPOSED", NAMRA_OFFICER.userId, "2026-08-01T00:00:00.000Z", "2026-08-01T00:00:00.000Z"),
    ]);
    const opened = await sendNoticeRoute(nextOfficer(), { related_resource_id: "case-cc-0006" });
    const threadId = (await opened.json()).resource.id as string;

    const closed = await closeConversationRoute(threadId, nextOfficer(), "The taxpayer provided sufficient evidence and the matter is resolved.");
    expect(closed.status).toBe(200);
    expect((await closed.json()).resource.status).toBe("CLOSED");

    const closedAgain = await closeConversationRoute(threadId, nextOfficer(), "Attempting to close an already-closed thread.");
    expect(closedAgain.status).toBe(409);

    const replyAfterClose = await respondRoute(threadId, TAXPAYER_OWNER, "A reply attempted after closure.");
    expect(replyAfterClose.status).toBe(409);
  });

  it("denies a taxpayer-side actor from closing a conversation", async () => {
    await env.DB.batch([
      env.DB.prepare(`INSERT INTO audit_cases (id,case_number,organisation_id,taxpayer_id,case_type,title,opening_reason,risk_tier,status,opened_by,opened_at,updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)`).bind("case-cc-0007", "CASE-CC-0007", "org-cc-taxpayer", "tp-cc-taxpayer", "DESK_REVIEW", "Seventh correspondence test case", "Testing closure denial.", "LOW", "PROPOSED", NAMRA_OFFICER.userId, "2026-08-01T00:00:00.000Z", "2026-08-01T00:00:00.000Z"),
    ]);
    const opened = await sendNoticeRoute(nextOfficer(), { related_resource_id: "case-cc-0007" });
    const threadId = (await opened.json()).resource.id as string;
    const response = await closeConversationRoute(threadId, TAXPAYER_OWNER, "A taxpayer attempting to close their own thread.");
    expect(response.status).toBe(403);
  });

  it("returns 404 responding to, closing or reading a non-existent correspondence thread", async () => {
    const orphanId = crypto.randomUUID();
    expect((await respondRoute(orphanId, TAXPAYER_OWNER, "A reply to a non-existent thread.")).status).toBe(404);
    expect((await closeConversationRoute(orphanId, nextOfficer(), "Closing a non-existent thread.")).status).toBe(404);
    expect((await conversationRoute(orphanId, nextOfficer())).status).toBe(404);
  });

  it("lists the inbox filterable by status and case reference type, tenant-scoped with a real total_count", async () => {
    const officerInbox = await inboxRoute(nextOfficer(), { related_resource_type: "AUDIT_CASE" });
    expect(officerInbox.status).toBe(200);
    const officerInboxBody = await officerInbox.json();
    expect(officerInboxBody.total_count).toBeGreaterThanOrEqual(6);
    expect(officerInboxBody.threads.every((t: { taxpayer_id: string }) => t.taxpayer_id === "tp-cc-taxpayer")).toBe(true);

    const closedOnly = await inboxRoute(nextOfficer(), { status: "CLOSED" });
    expect(closedOnly.status).toBe(200);
    const closedOnlyBody = await closedOnly.json();
    expect(closedOnlyBody.threads.length).toBeGreaterThanOrEqual(1);
    expect(closedOnlyBody.threads.every((t: { status: string }) => t.status === "CLOSED")).toBe(true);

    const taxpayerInbox = await inboxRoute(TAXPAYER_OWNER);
    expect(taxpayerInbox.status).toBe(200);
    const taxpayerInboxBody = await taxpayerInbox.json();
    expect(taxpayerInboxBody.threads.every((t: { taxpayer_id: string }) => t.taxpayer_id === "tp-cc-taxpayer")).toBe(true);

    const otherTaxpayerInbox = await inboxRoute(OTHER_TAXPAYER_OWNER);
    const otherTaxpayerInboxBody = await otherTaxpayerInbox.json();
    expect(otherTaxpayerInboxBody.total_count).toBe(0);
  });

  it("rejects an invalid inbox status filter", async () => {
    const response = await inboxRoute(nextOfficer(), { status: "ARCHIVED" });
    expect(response.status).toBe(422);
  });
});
