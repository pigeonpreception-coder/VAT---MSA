import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Security remediation (2026-08-27), item #4 of SECURITY_GAP_ASSESSMENT.md's
 * prioritised list (HIGH): a 2026-08-26 audit found `AUTHORISATION_DENIED`
 * was emitted from only a handful of route families (business, compliance,
 * platform, security, vat-lifecycle, audit) — the identity, control-plane,
 * reconciliation and vat-rules families recorded nothing at all, so Module
 * 8's `REPEATED_AUTHORISATION_DENIALS` detection rule was structurally
 * blind to roughly half the API. Fixed by making each family's shared
 * `*Problem()` helper (lib/api/identity.ts, control-plane.ts,
 * reconciliation.ts, vat-rules.ts) record the event itself — re-resolving
 * the actor rather than requiring every one of ~60 individual routes to
 * thread one through (lib/security/request.ts's recordAuthorizationDenial/
 * recordRateLimitBreach). This suite proves the fix genuinely closes the
 * gap: a denial on a previously-blind route now writes a real
 * `security_events` row, and five of them from the same actor within the
 * detection window now genuinely opens a `security_incidents` row through
 * the existing, unmodified detection pipeline — proving the rule can now
 * fire for these route families, not just that an event object was
 * constructed. Proven through the real route handlers
 * (app/api/v1/organisations/roles, app/api/v1/exceptions/:id/assignment).
 * See tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const LOW_PRIVILEGE: FixtureUser = { userId: "usr-sev-low", externalUserId: "ext-sev-low", email: "low@sev-test.test" };
const NAMRA_OFFICER: FixtureUser = { userId: "usr-sev-namra", externalUserId: "ext-sev-namra", email: "namra@sev-test.test" };

function actingAs(user: FixtureUser): void {
  __setRequestHeaders({ "oai-authenticated-user-id": user.externalUserId, "oai-authenticated-user-email": user.email });
}

function jsonRequest(url: string, body: unknown): Request {
  return new Request(url, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(body) });
}

async function seedFixture(): Promise<void> {
  const db = env.DB;
  const now = "2026-08-01T00:00:00.000Z";
  await db.batch([
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-sev", "VAT-SEV-001", "TIN-SEV-001", "Security Event Test Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Event Street", "finance@sev-test.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-sev", "tp-sev", "Security Event Test Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-sev-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(LOW_PRIVILEGE.userId, LOW_PRIVILEGE.externalUserId, LOW_PRIVILEGE.email, "Low Privilege Viewer", "TAXPAYER_VIEWER", "tp-sev", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_OFFICER.userId, NAMRA_OFFICER.externalUserId, NAMRA_OFFICER.email, "NamRA Officer", "TAXPAYER_VIEWER", "tp-sev", "ACTIVE", now),
    ...[LOW_PRIVILEGE, NAMRA_OFFICER].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-sev-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
    // The real, unmodified REPEATED_AUTHORISATION_DENIALS detection rule (seed-only in db/runtime.ts, so it doesn't run under NODE_ENV=production here).
    db.prepare(`INSERT INTO security_detection_rules
      (id,code,name,description,event_type,group_by,threshold_count,window_minutes,severity,status,created_at)
      VALUES ('secrule-repeated-denials','REPEATED_AUTHORISATION_DENIALS','Repeated authorisation denials','Opens a HIGH incident after 5 authorisation denials from the same actor within 15 minutes.','AUTHORISATION_DENIED','actor_id',5,15,'HIGH','ACTIVE',?)`).bind(now),
  ]);
}

async function attemptCreateRole(): Promise<Response> {
  const { POST } = await import("@/app/api/v1/organisations/roles/route");
  actingAs(LOW_PRIVILEGE);
  return POST(jsonRequest("https://vat-msa.local/api/v1/organisations/roles?organisation_id=org-sev", { name: "Denied Attempt", permissions: ["invoices:read"] }));
}

async function attemptAssignException(): Promise<Response> {
  const { POST } = await import("@/app/api/v1/exceptions/[id]/assignment/route");
  actingAs(LOW_PRIVILEGE);
  return POST(jsonRequest("https://vat-msa.local/api/v1/exceptions/exc-does-not-matter/assignment", { officer_id: NAMRA_OFFICER.userId }), { params: Promise.resolve({ id: "exc-does-not-matter" }) });
}

describe("Security fix: AUTHORISATION_DENIED now recorded across previously-blind route families", () => {
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

  it("records a real AUTHORISATION_DENIED security_events row for a denied control-plane request (roles:manage)", async () => {
    const response = await attemptCreateRole();
    expect(response.status).toBe(403);
    const row = await env.DB.prepare("SELECT actor_id,action,outcome FROM security_events WHERE event_type='AUTHORISATION_DENIED' AND actor_id=?")
      .bind(LOW_PRIVILEGE.userId).first<{ actor_id: string; action: string; outcome: string }>();
    expect(row?.actor_id).toBe(LOW_PRIVILEGE.userId);
    expect(row?.action).toBe("roles:manage");
    expect(row?.outcome).toBe("DENIED");
  });

  it("records a real AUTHORISATION_DENIED security_events row for a denied reconciliation request (reconciliation:manage)", async () => {
    const before = await env.DB.prepare("SELECT COUNT(*) AS n FROM security_events WHERE event_type='AUTHORISATION_DENIED' AND action='reconciliation:manage'").first<{ n: number }>();
    const response = await attemptAssignException();
    expect(response.status).toBe(403);
    const after = await env.DB.prepare("SELECT COUNT(*) AS n FROM security_events WHERE event_type='AUTHORISATION_DENIED' AND action='reconciliation:manage'").first<{ n: number }>();
    expect(Number(after?.n ?? 0)).toBe(Number(before?.n ?? 0) + 1);
  });

  it("genuinely fires REPEATED_AUTHORISATION_DENIALS and opens a security_incidents row once the same actor accumulates 5 denials — proving the detection rule, previously structurally unreachable from this route family, now actually works", async () => {
    // LOW_PRIVILEGE already accumulated 2 denials in the two tests above (one from each route family — the
    // rule groups by actor_id regardless of which permission/action was denied); four more crosses the
    // threshold of 5 partway through this loop, and stays open (de-duplicated) for the rest of it.
    for (let i = 0; i < 4; i++) {
      const response = await attemptCreateRole();
      expect(response.status).toBe(403);
    }
    const incident = await env.DB.prepare("SELECT status,severity,group_key FROM security_incidents WHERE detection_rule_id='secrule-repeated-denials'").first<{ status: string; severity: string; group_key: string }>();
    expect(incident).toBeTruthy();
    expect(incident?.severity).toBe("HIGH");
    expect(incident?.group_key).toBe(LOW_PRIVILEGE.userId);
  });
});
