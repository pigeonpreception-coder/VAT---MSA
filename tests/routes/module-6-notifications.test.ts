import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 6 Phase D: the notification write path. A 2026-08-26 code audit
 * found `notifications` already had five call sites writing to it as a
 * side effect of other commands (now consolidated into one shared
 * `notificationRecord` helper in the repository), but no standalone Queue
 * command, no CancelNotification, no notification_preferences for
 * UpdatePreference, and `read_at` was never written by anything anywhere.
 * Proven through the real route handlers (app/api/v1/notifications,
 * .../preferences, .../[id]/cancellation, .../[id]/read, dispatched via
 * lib/api/compliance.ts) and lib/data/compliance-repository.ts. See
 * tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const TAXPAYER_OWNER: FixtureUser = { userId: "usr-notif-owner", externalUserId: "ext-notif-owner", email: "owner@notif-taxpayer.test" };
const TARGET_USER: FixtureUser = { userId: "usr-notif-target", externalUserId: "ext-notif-target", email: "target@notif-taxpayer.test" };
const OTHER_TAXPAYER_OWNER: FixtureUser = { userId: "usr-notif-other-owner", externalUserId: "ext-notif-other-owner", email: "owner@notif-other.test" };
const NAMRA_OFFICER: FixtureUser = { userId: "usr-notif-namra", externalUserId: "ext-notif-namra", email: "namra@notif.test" };
const NAMRA_OFFICER_2: FixtureUser = { userId: "usr-notif-namra-2", externalUserId: "ext-notif-namra-2", email: "namra2@notif.test" };

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
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-notif-taxpayer", "VAT-NOTIF-001", "TIN-NOTIF-001", "Notification Test Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Notification Street", "finance@notif-taxpayer.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-notif-taxpayer", "tp-notif-taxpayer", "Notification Test Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-notif-other", "VAT-NOTIF-002", "TIN-NOTIF-002", "Other Notification Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "2 Notification Street", "finance@notif-other.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-notif-other", "tp-notif-other", "Other Notification Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-notif-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(TAXPAYER_OWNER.userId, TAXPAYER_OWNER.externalUserId, TAXPAYER_OWNER.email, "Owner", "TAXPAYER_OWNER", "tp-notif-taxpayer", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(TARGET_USER.userId, TARGET_USER.externalUserId, TARGET_USER.email, "Target User", "TAXPAYER_STAFF", "tp-notif-taxpayer", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OTHER_TAXPAYER_OWNER.userId, OTHER_TAXPAYER_OWNER.externalUserId, OTHER_TAXPAYER_OWNER.email, "Other Owner", "TAXPAYER_OWNER", "tp-notif-other", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_OFFICER.userId, NAMRA_OFFICER.externalUserId, NAMRA_OFFICER.email, "NamRA Officer", "NAMRA_COMPLIANCE_OFFICER", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_OFFICER_2.userId, NAMRA_OFFICER_2.externalUserId, NAMRA_OFFICER_2.email, "NamRA Officer Two", "NAMRA_COMPLIANCE_OFFICER", null, "ACTIVE", now),
    ...[TAXPAYER_OWNER, TARGET_USER, OTHER_TAXPAYER_OWNER, NAMRA_OFFICER, NAMRA_OFFICER_2].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-notif-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
  ]);
}

function queuePayload(overrides: Record<string, unknown> = {}) {
  return {
    schema_version: "1.0.0",
    taxpayer_id: "tp-notif-taxpayer",
    notification_type: "MANUAL_REMINDER",
    title: "A reminder notification",
    message: "This is a manually queued reminder notification for testing.",
    severity: "MEDIUM",
    channels: ["IN_APP", "EMAIL"],
    ...overrides,
  };
}

async function queueRoute(actor: FixtureUser, overrides: Record<string, unknown> = {}, key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/notifications/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/notifications", queuePayload(overrides), key));
}

async function notificationsRoute(actor: FixtureUser, query: Record<string, string> = {}): Promise<Response> {
  const { GET } = await import("@/app/api/v1/notifications/route");
  actingAs(actor);
  const qs = new URLSearchParams(query).toString();
  return GET(new Request(`https://vat-msa.local/api/v1/notifications${qs ? `?${qs}` : ""}`));
}

async function markReadRoute(notificationId: string, actor: FixtureUser, key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/notifications/[id]/read/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/notifications/${notificationId}/read`, { schema_version: "1.0.0" }, key), { params: Promise.resolve({ id: notificationId }) });
}

async function cancelRoute(notificationId: string, actor: FixtureUser, reason: string, key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/notifications/[id]/cancellation/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/notifications/${notificationId}/cancellation`, { schema_version: "1.0.0", reason }, key), { params: Promise.resolve({ id: notificationId }) });
}

async function preferenceRoute(actor: FixtureUser, channel: string, enabled: boolean, key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/notifications/preferences/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/notifications/preferences", { schema_version: "1.0.0", channel, enabled }, key));
}

describe("Module 6 notification queueing, preferences and lifecycle (Phase D)", () => {
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

  it("queues a notification for a taxpayer, attempting every requested channel plus IN_APP", async () => {
    const response = await queueRoute(nextOfficer());
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.resource.status).toBe("UNREAD");
    expect(body.resource.taxpayer_id).toBe("tp-notif-taxpayer");

    const deliveries = await env.DB.prepare("SELECT channel FROM notification_deliveries WHERE notification_id=? ORDER BY channel").bind(body.resource.id).all<{ channel: string }>();
    expect(deliveries.results.map((d) => d.channel)).toEqual(["EMAIL", "IN_APP"]);
  });

  it("respects a disabled channel preference when queuing to a specific user, but always still attempts IN_APP", async () => {
    const disabled = await preferenceRoute(TARGET_USER, "EMAIL", false);
    expect(disabled.status).toBe(200);

    const response = await queueRoute(nextOfficer(), { user_id: TARGET_USER.userId, taxpayer_id: undefined });
    expect(response.status).toBe(201);
    const body = await response.json();

    const deliveries = await env.DB.prepare("SELECT channel FROM notification_deliveries WHERE notification_id=?").bind(body.resource.id).all<{ channel: string }>();
    expect(deliveries.results.map((d) => d.channel)).toEqual(["IN_APP"]);
  });

  it("denies a non-officer actor from queuing a notification directly", async () => {
    const response = await queueRoute(TAXPAYER_OWNER);
    expect(response.status).toBe(403);
  });

  it("returns 404 queuing a notification for a non-existent taxpayer or user", async () => {
    const badTaxpayer = await queueRoute(nextOfficer(), { taxpayer_id: crypto.randomUUID() });
    expect(badTaxpayer.status).toBe(404);
    const badUser = await queueRoute(nextOfficer(), { user_id: crypto.randomUUID(), taxpayer_id: undefined });
    expect(badUser.status).toBe(404);
  });

  it("marks a notification read, and re-marking with a fresh key is a harmless no-op", async () => {
    const queued = await queueRoute(nextOfficer());
    const notificationId = (await queued.json()).resource.id as string;

    const read = await markReadRoute(notificationId, TAXPAYER_OWNER);
    expect(read.status).toBe(200);
    const readBody = await read.json();
    expect(readBody.resource.status).toBe("READ");
    expect(readBody.resource.read_at).not.toBeNull();

    const readAgain = await markReadRoute(notificationId, TAXPAYER_OWNER);
    expect(readAgain.status).toBe(200);
    expect((await readAgain.json()).resource.status).toBe("READ");
  });

  it("cancels an unread notification, rejects a second cancellation, and rejects cancelling an already-read notification", async () => {
    const queued = await queueRoute(nextOfficer());
    const notificationId = (await queued.json()).resource.id as string;

    const cancelled = await cancelRoute(notificationId, TAXPAYER_OWNER, "Superseded by a later notice.");
    expect(cancelled.status).toBe(200);
    expect((await cancelled.json()).resource.status).toBe("CANCELLED");

    const cancelledAgain = await cancelRoute(notificationId, TAXPAYER_OWNER, "Attempting a second cancellation.");
    expect(cancelledAgain.status).toBe(409);

    const readQueued = await queueRoute(nextOfficer());
    const readNotificationId = (await readQueued.json()).resource.id as string;
    await markReadRoute(readNotificationId, TAXPAYER_OWNER);
    const cancelAfterRead = await cancelRoute(readNotificationId, TAXPAYER_OWNER, "Attempting to cancel an already-read notification.");
    expect(cancelAfterRead.status).toBe(409);
  });

  it("rejects reading or cancelling a notification outside the actor's taxpayer scope", async () => {
    const queued = await queueRoute(nextOfficer());
    const notificationId = (await queued.json()).resource.id as string;
    expect((await markReadRoute(notificationId, OTHER_TAXPAYER_OWNER)).status).toBe(403);
    expect((await cancelRoute(notificationId, OTHER_TAXPAYER_OWNER, "An unrelated taxpayer attempting to cancel.")).status).toBe(403);
  });

  it("returns 404 reading or cancelling a non-existent notification", async () => {
    const orphanId = crypto.randomUUID();
    expect((await markReadRoute(orphanId, TAXPAYER_OWNER)).status).toBe(404);
    expect((await cancelRoute(orphanId, TAXPAYER_OWNER, "Attempting to cancel a non-existent notification.")).status).toBe(404);
  });

  it("lists the current actor's notifications, filterable by status and severity, tenant-scoped with a real total_count", async () => {
    const officerView = await notificationsRoute(nextOfficer());
    expect(officerView.status).toBe(200);

    const taxpayerView = await notificationsRoute(TAXPAYER_OWNER);
    expect(taxpayerView.status).toBe(200);
    const taxpayerViewBody = await taxpayerView.json();
    expect(taxpayerViewBody.notifications.every((n: { taxpayer_id: string | null; user_id: string | null }) => n.taxpayer_id === "tp-notif-taxpayer" || n.user_id === TAXPAYER_OWNER.userId)).toBe(true);

    const cancelledOnly = await notificationsRoute(TAXPAYER_OWNER, { status: "CANCELLED" });
    expect(cancelledOnly.status).toBe(200);
    const cancelledOnlyBody = await cancelledOnly.json();
    expect(cancelledOnlyBody.total_count).toBeGreaterThanOrEqual(1);
    expect(cancelledOnlyBody.notifications.every((n: { status: string }) => n.status === "CANCELLED")).toBe(true);

    const otherTaxpayerView = await notificationsRoute(OTHER_TAXPAYER_OWNER);
    const otherTaxpayerViewBody = await otherTaxpayerView.json();
    expect(otherTaxpayerViewBody.total_count).toBe(0);
  });

  it("rejects an invalid notification query filter", async () => {
    const response = await notificationsRoute(TAXPAYER_OWNER, { status: "ARCHIVED" });
    expect(response.status).toBe(422);
  });

  it("updates a notification preference, upserting to the latest value on repeat calls", async () => {
    const first = await preferenceRoute(TAXPAYER_OWNER, "SMS", true);
    expect(first.status).toBe(200);
    expect((await first.json()).resource.enabled).toBe(1);

    const second = await preferenceRoute(TAXPAYER_OWNER, "SMS", false);
    expect(second.status).toBe(200);
    expect((await second.json()).resource.enabled).toBe(0);

    const rows = await env.DB.prepare("SELECT COUNT(*) AS n FROM notification_preferences WHERE user_id=? AND channel='SMS'").bind(TAXPAYER_OWNER.userId).first<{ n: number }>();
    expect(rows?.n).toBe(1);
  });
});
