import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 8 Phase A: FeatureFlag/PlatformConfig/AccessPolicy/ChangeRequest —
 * a 2026-08-26 audit found zero code anywhere for any of these, despite the
 * architecture matrix's "VERIFIED FOUNDATION" label. Also covers the
 * finance-data-exclusion fix (GET /api/v1/platform now structurally routes
 * a technical-only actor to the technical snapshot, not just at the UI
 * page level) and ProvisionStaff (platform/NamRA staff accounts previously
 * had no provisioning command at all, only a hardcoded seed row). Proven
 * through the real route handlers (app/api/v1/platform/..., dispatched via
 * lib/api/platform.ts) and lib/data/platform-repository.ts. See
 * tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const PLATFORM_ADMIN_A: FixtureUser = { userId: "usr-adm-platform-a", externalUserId: "ext-adm-platform-a", email: "admin-a@adm-test.test" };
const PLATFORM_ADMIN_B: FixtureUser = { userId: "usr-adm-platform-b", externalUserId: "ext-adm-platform-b", email: "admin-b@adm-test.test" };
const NAMRA_OFFICER: FixtureUser = { userId: "usr-adm-namra", externalUserId: "ext-adm-namra", email: "namra@adm-test.test" };

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
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-adm-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,NULL,?,?)`)
      .bind(PLATFORM_ADMIN_A.userId, PLATFORM_ADMIN_A.externalUserId, PLATFORM_ADMIN_A.email, "Platform Admin A", "SUPER_ADMIN", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,NULL,?,?)`)
      .bind(PLATFORM_ADMIN_B.userId, PLATFORM_ADMIN_B.externalUserId, PLATFORM_ADMIN_B.email, "Platform Admin B", "SUPER_ADMIN", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,NULL,?,?)`)
      .bind(NAMRA_OFFICER.userId, NAMRA_OFFICER.externalUserId, NAMRA_OFFICER.email, "NamRA Officer", "NAMRA_COMPLIANCE_OFFICER", "ACTIVE", now),
    ...[PLATFORM_ADMIN_A, PLATFORM_ADMIN_B, NAMRA_OFFICER].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-adm-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
    db.prepare(`INSERT INTO feature_flags (id,key,name,description,rollout_scope,enabled,status,version,created_at)
      VALUES (?,?,?,?,?,?,?,?,?)`).bind("flag-adm-test", "ADM_TEST_TOGGLE", "Test toggle", "A test feature flag.", "ALL", 0, "ACTIVE", 1, now),
    db.prepare(`INSERT INTO platform_config (id,key,category,description,value,status,version,created_at)
      VALUES (?,?,?,?,?,?,?,?)`).bind("cfg-adm-test", "ADM_TEST_VALUE", "TEST", "A test config value.", "10", "ACTIVE", 1, now),
    db.prepare(`INSERT INTO access_policies (id,code,name,policy_type,description,parameters,status,version,created_at)
      VALUES (?,?,?,?,?,?,?,?,?)`).bind("policy-adm-test", "ADM_TEST_POLICY", "Test policy", "TEST", "A test access policy.", '{"limit":5}', "ACTIVE", 1, now),
  ]);
}

async function platformSnapshotRoute(actor: FixtureUser): Promise<Response> {
  const { GET } = await import("@/app/api/v1/platform/route");
  actingAs(actor);
  return GET(new Request("https://vat-msa.local/api/v1/platform"));
}

async function configRoute(actor: FixtureUser): Promise<Response> {
  const { GET } = await import("@/app/api/v1/platform/config/route");
  actingAs(actor);
  return GET(new Request("https://vat-msa.local/api/v1/platform/config"));
}

async function requestChangeRoute(actor: FixtureUser, body: Record<string, unknown>): Promise<Response> {
  const { POST } = await import("@/app/api/v1/platform/change-requests/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/platform/change-requests", { schema_version: "1.0.0", ...body }));
}

async function listChangeRequestsRoute(actor: FixtureUser, query = ""): Promise<Response> {
  const { GET } = await import("@/app/api/v1/platform/change-requests/route");
  actingAs(actor);
  return GET(new Request(`https://vat-msa.local/api/v1/platform/change-requests${query}`));
}

async function decideChangeRoute(changeRequestId: string, actor: FixtureUser, decision: string, notes: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/platform/change-requests/[id]/decision/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/platform/change-requests/${changeRequestId}/decision`, { schema_version: "1.0.0", decision, notes }), { params: Promise.resolve({ id: changeRequestId }) });
}

async function provisionStaffRoute(actor: FixtureUser, body: Record<string, unknown>, options: { stepUp?: boolean } = {}): Promise<Response> {
  const { POST } = await import("@/app/api/v1/platform/staff/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/platform/staff", { schema_version: "1.0.0", ...body }, options));
}

describe("Module 8 administration core: FeatureFlag/PlatformConfig/AccessPolicy/ChangeRequest, ProvisionStaff (Phase A)", () => {
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

  it("routes a technical-only actor to the technical snapshot, structurally excluding finance data", async () => {
    const technical = await platformSnapshotRoute(PLATFORM_ADMIN_A);
    expect(technical.status).toBe(200);
    const technicalBody = await technical.json();
    expect(technicalBody.components).toBeDefined();
    expect(technicalBody.payments).toBeUndefined();
    expect(technicalBody.bankImports).toBeUndefined();

    const namra = await platformSnapshotRoute(NAMRA_OFFICER);
    expect(namra.status).toBe(200);
    const namraBody = await namra.json();
    expect(namraBody.payments).toBeDefined();
  });

  it("lists feature flags, platform config and access policies with their current values", async () => {
    const response = await configRoute(PLATFORM_ADMIN_A);
    expect(response.status).toBe(200);
    const body = await response.json();
    const flag = body.feature_flags.find((item: { key: string }) => item.key === "ADM_TEST_TOGGLE");
    expect(flag.enabled).toBe(false);
    const config = body.platform_config.find((item: { key: string }) => item.key === "ADM_TEST_VALUE");
    expect(config.value).toBe("10");
    const policy = body.access_policies.find((item: { code: string }) => item.code === "ADM_TEST_POLICY");
    expect(policy.parameters).toEqual({ limit: 5 });
  });

  it("denies requesting or deciding a platform change to an actor without platform:manage", async () => {
    const request = await requestChangeRoute(NAMRA_OFFICER, { target_type: "FEATURE_FLAG", target_id: "flag-adm-test", proposed_value: { enabled: true }, reason: "Attempted by an unauthorised actor." });
    expect(request.status).toBe(403);
  });

  it("stages, self-decision-denies, and approves a feature flag change, reflected in GetConfig afterwards", async () => {
    const requested = await requestChangeRoute(PLATFORM_ADMIN_A, { target_type: "FEATURE_FLAG", target_id: "flag-adm-test", proposed_value: { enabled: true }, reason: "Enable for the pilot cohort rollout." });
    expect(requested.status).toBe(201);
    const requestedBody = await requested.json();
    expect(requestedBody.change_request.status).toBe("PENDING");
    expect(JSON.parse(requestedBody.change_request.previous_value)).toEqual({ enabled: false });
    const changeRequestId = requestedBody.change_request.id as string;

    const selfDecision = await decideChangeRoute(changeRequestId, PLATFORM_ADMIN_A, "APPROVE", "Approving my own request.");
    expect(selfDecision.status).toBe(403);

    const approved = await decideChangeRoute(changeRequestId, PLATFORM_ADMIN_B, "APPROVE", "Confirmed with the release checklist.");
    expect(approved.status).toBe(200);
    expect((await approved.json()).change_request.status).toBe("APPLIED");

    const config = await configRoute(PLATFORM_ADMIN_A);
    const flag = (await config.json()).feature_flags.find((item: { key: string }) => item.key === "ADM_TEST_TOGGLE");
    expect(flag.enabled).toBe(true);
    expect(flag.version).toBe(2);

    const redecide = await decideChangeRoute(changeRequestId, PLATFORM_ADMIN_B, "APPROVE", "Trying to decide it again.");
    expect(redecide.status).toBe(409);
  });

  it("rejects a platform config change, leaving the value unchanged", async () => {
    const requested = await requestChangeRoute(PLATFORM_ADMIN_A, { target_type: "PLATFORM_CONFIG", target_id: "cfg-adm-test", proposed_value: { value: "99" }, reason: "Proposed value for review." });
    const changeRequestId = (await requested.json()).change_request.id as string;

    const rejected = await decideChangeRoute(changeRequestId, PLATFORM_ADMIN_B, "REJECT", "Not approved for this release.");
    expect(rejected.status).toBe(200);
    expect((await rejected.json()).change_request.status).toBe("REJECTED");

    const config = await configRoute(PLATFORM_ADMIN_A);
    const entry = (await config.json()).platform_config.find((item: { key: string }) => item.key === "ADM_TEST_VALUE");
    expect(entry.value).toBe("10");
  });

  it("refuses a change request whose proposed_value does not match its target type's shape", async () => {
    const response = await requestChangeRoute(PLATFORM_ADMIN_A, { target_type: "ACCESS_POLICY", target_id: "policy-adm-test", proposed_value: { value: "wrong shape" }, reason: "Malformed proposed value." });
    expect(response.status).toBe(422);
  });

  it("returns 404 requesting a change against an unknown target", async () => {
    const response = await requestChangeRoute(PLATFORM_ADMIN_A, { target_type: "FEATURE_FLAG", target_id: "flag-does-not-exist", proposed_value: { enabled: true }, reason: "Targeting a non-existent flag." });
    expect(response.status).toBe(404);
  });

  it("filters change requests by status", async () => {
    const pending = await listChangeRequestsRoute(PLATFORM_ADMIN_A, "?status=PENDING");
    const pendingBody = await pending.json();
    expect(pendingBody.change_requests.every((item: { status: string }) => item.status === "PENDING")).toBe(true);

    const applied = await listChangeRequestsRoute(PLATFORM_ADMIN_A, "?status=APPLIED");
    const appliedBody = await applied.json();
    expect(appliedBody.change_requests.length).toBeGreaterThanOrEqual(1);
    expect(appliedBody.change_requests.every((item: { status: string }) => item.status === "APPLIED")).toBe(true);
  });

  it("requires step-up to provision platform staff, then provisions a national-scope account with no taxpayer", async () => {
    const withoutStepUp = await provisionStaffRoute(PLATFORM_ADMIN_A, { external_user_id: "ext-new-staff-0001", email: "new.staff@namra.test", display_name: "New Staff Member", role: "SECURITY_ANALYST" });
    expect(withoutStepUp.status).toBe(403);

    const provisioned = await provisionStaffRoute(PLATFORM_ADMIN_A, { external_user_id: "ext-new-staff-0001", email: "new.staff@namra.test", display_name: "New Staff Member", role: "SECURITY_ANALYST" }, { stepUp: true });
    expect(provisioned.status).toBe(201);
    const staff = (await provisioned.json()).staff;
    expect(staff.role).toBe("SECURITY_ANALYST");
    expect(staff.status).toBe("ACTIVE");

    const duplicate = await provisionStaffRoute(PLATFORM_ADMIN_A, { external_user_id: "ext-new-staff-0001", email: "new.staff@namra.test", display_name: "New Staff Member", role: "SECURITY_ANALYST" }, { stepUp: true });
    expect(duplicate.status).toBe(409);

    const row = await env.DB.prepare("SELECT taxpayer_id,role FROM app_users WHERE external_user_id=?").bind("ext-new-staff-0001").first<{ taxpayer_id: string | null; role: string }>();
    expect(row?.taxpayer_id).toBeNull();
    expect(row?.role).toBe("SECURITY_ANALYST");
  });

  it("denies provisioning platform staff to an actor without platform:manage", async () => {
    const response = await provisionStaffRoute(NAMRA_OFFICER, { external_user_id: "ext-denied-0001", email: "denied@namra.test", display_name: "Denied Staff", role: "SECURITY_ANALYST" }, { stepUp: true });
    expect(response.status).toBe(403);
  });
});
