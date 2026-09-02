import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 10 Phase C: SaaS provider onboarding — RegisterProvider/
 * SubmitConformance/GetUsage against a genuinely new data model
 * (saas_providers/saas_applications/saas_conformance_runs/
 * saas_environment_approvals — confirmed greenfield before this phase:
 * no such tables existed anywhere in db/runtime.ts). RegisterProvider
 * creates both the SaaSProvider and its first Application atomically (the
 * playbook names no separate "create application" verb). SubmitConformance
 * runs a real, fixed, code-versioned check harness
 * (lib/domain/saas.ts's evaluateConformance) rather than trusting a
 * self-reported pass/fail — including EVENT_CONTRACT_ACKNOWLEDGED, which
 * validates a submission's acknowledged_events against the actual
 * documented catalogue (08-enterprise-architecture/event-catalog.csv),
 * genuinely fulfilling "validated against the documented event/API
 * shapes." The one deliberate safety property this phase adds: a PASSED
 * PRODUCTION conformance run still only ever reaches AWAITING_AUTHORITY,
 * never GRANTED — production onboarding from a purely self-submitted,
 * self-run conformance suite is a governance decision this phase does not
 * build a path to grant automatically, the same "fail closed on an
 * unconfirmed authority" posture ITAS/Payment/HSM already apply elsewhere.
 * GetUsage also ties back into Module 10 Phase A's own generic connector
 * model, surfacing real integration_connections/sync_jobs usage for a
 * vetted provider's provider_key.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const DEV_A: FixtureUser = { userId: "usr-saas-dev-a", externalUserId: "ext-saas-dev-a", email: "dev-a@saas-test.test" };
const DEV_B: FixtureUser = { userId: "usr-saas-dev-b", externalUserId: "ext-saas-dev-b", email: "dev-b@saas-test.test" };
const PLATFORM_ADMIN: FixtureUser = { userId: "usr-saas-platform", externalUserId: "ext-saas-platform", email: "platform@saas-test.test" };
const AUDITOR: FixtureUser = { userId: "usr-saas-auditor", externalUserId: "ext-saas-auditor", email: "auditor@saas-test.test" };

function actingAs(user: FixtureUser): void {
  __setRequestHeaders({ "oai-authenticated-user-id": user.externalUserId, "oai-authenticated-user-email": user.email });
}

function jsonRequest(url: string, method: string, body: unknown, idempotencyKey = crypto.randomUUID()): Request {
  return new Request(url, { method, headers: { "content-type": "application/json", "idempotency-key": idempotencyKey }, body: JSON.stringify(body) });
}

async function seedFixture(): Promise<void> {
  const db = env.DB;
  const now = "2026-08-01T00:00:00.000Z";
  await db.batch([
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-saas-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(DEV_A.userId, DEV_A.externalUserId, DEV_A.email, "Dev Partner A", "DEVELOPER_PARTNER", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(DEV_B.userId, DEV_B.externalUserId, DEV_B.email, "Dev Partner B", "DEVELOPER_PARTNER", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(PLATFORM_ADMIN.userId, PLATFORM_ADMIN.externalUserId, PLATFORM_ADMIN.email, "Platform Admin", "PILOT_ADMIN", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(AUDITOR.userId, AUDITOR.externalUserId, AUDITOR.email, "Auditor", "NAMRA_AUDITOR", null, "ACTIVE", now),
    ...[DEV_A, DEV_B, PLATFORM_ADMIN, AUDITOR].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-saas-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
  ]);
}

const baseRegistration = {
  schema_version: "1.0.0", provider_key: "SAASCO_ACCOUNTING", legal_name: "SaaSCo Accounting Ltd", contact_email: "integrations@saasco.test", category: "ACCOUNTING",
  application: { name: "SaaSCo Sync App", description: "Synchronises invoices and payments with SaaSCo's accounting ledger.", requested_capabilities: ["INVOICE_SYNC", "PAYMENT_SYNC"], endpoint_reference: "https://api.saasco.test/webhooks/vat-msa" },
};

async function registerProviderRoute(actor: FixtureUser, body: unknown, idempotencyKey?: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/saas-providers/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/saas-providers", "POST", body, idempotencyKey));
}

async function submitConformanceRoute(actor: FixtureUser, applicationId: string, body: unknown, idempotencyKey?: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/saas-applications/[id]/conformance-runs/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/saas-applications/${applicationId}/conformance-runs`, "POST", body, idempotencyKey), { params: Promise.resolve({ id: applicationId }) });
}

async function usageRoute(actor: FixtureUser, providerId: string): Promise<Response> {
  const { GET } = await import("@/app/api/v1/saas-providers/[id]/usage/route");
  actingAs(actor);
  return GET(new Request(`https://vat-msa.local/api/v1/saas-providers/${providerId}/usage`), { params: Promise.resolve({ id: providerId }) });
}

async function registerIntegrationRoute(actor: FixtureUser, body: unknown): Promise<Response> {
  const { POST } = await import("@/app/api/v1/integrations/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/integrations", "POST", body));
}

describe("Module 10 SaaS provider onboarding: RegisterProvider/SubmitConformance/GetUsage (Phase C)", () => {
  let providerId: string;
  let applicationId: string;

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

  it("denies registration to a role without developer:manage", async () => {
    const response = await registerProviderRoute(AUDITOR, baseRegistration);
    expect(response.status).toBe(403);
  });

  it("rejects registration with a validation error for a non-https endpoint", async () => {
    const response = await registerProviderRoute(DEV_A, { ...baseRegistration, provider_key: "BAD_ENDPOINT_CO", application: { ...baseRegistration.application, endpoint_reference: "http://insecure.test/hook" } });
    expect(response.status).toBe(422);
  });

  it("RegisterProvider creates both the SaaSProvider and its first Application atomically", async () => {
    const response = await registerProviderRoute(DEV_A, baseRegistration);
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.resource.status).toBe("ACTIVE");
    expect(body.resource.application.status).toBe("REGISTERED");
    providerId = body.resource.id;
    applicationId = body.resource.application.id;
  });

  it("refuses a duplicate provider_key", async () => {
    const response = await registerProviderRoute(DEV_B, { ...baseRegistration, application: { ...baseRegistration.application, name: "Other App" } });
    expect(response.status).toBe(409);
  });

  it("refuses SubmitConformance from an actor who neither registered the provider nor holds national scope", async () => {
    const response = await submitConformanceRoute(DEV_B, applicationId, { schema_version: "1.0.0", environment: "SANDBOX", tested_capabilities: ["INVOICE_SYNC"], acknowledged_events: ["InvoiceCreated"] });
    expect(response.status).toBe(403);
  });

  it("SANDBOX conformance FAILS when a tested capability exceeds what was registered", async () => {
    const response = await submitConformanceRoute(DEV_A, applicationId, { schema_version: "1.0.0", environment: "SANDBOX", tested_capabilities: ["INVOICE_SYNC", "SOMETHING_NOT_REGISTERED"], acknowledged_events: ["InvoiceCreated"] });
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.resource.outcome).toBe("FAILED");
    const checks = JSON.parse(body.resource.checks);
    expect(checks.find((c: { code: string }) => c.code === "CAPABILITY_SCOPE_MATCHED").status).toBe("FAIL");
  });

  it("SANDBOX conformance FAILS when an acknowledged event isn't in the documented catalogue", async () => {
    const response = await submitConformanceRoute(DEV_A, applicationId, { schema_version: "1.0.0", environment: "SANDBOX", tested_capabilities: ["INVOICE_SYNC"], acknowledged_events: ["TotallyMadeUpEvent"] });
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.resource.outcome).toBe("FAILED");
    const checks = JSON.parse(body.resource.checks);
    expect(checks.find((c: { code: string }) => c.code === "EVENT_CONTRACT_ACKNOWLEDGED").status).toBe("FAIL");
  });

  it("PRODUCTION conformance FAILS without a prior PASSED SANDBOX run for the same application", async () => {
    const response = await submitConformanceRoute(DEV_A, applicationId, { schema_version: "1.0.0", environment: "PRODUCTION", tested_capabilities: ["INVOICE_SYNC"], acknowledged_events: ["InvoiceCreated"] });
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.resource.outcome).toBe("FAILED");
    const checks = JSON.parse(body.resource.checks);
    expect(checks.find((c: { code: string }) => c.code === "SANDBOX_PRECEDES_PRODUCTION").status).toBe("FAIL");

    const approval = await env.DB.prepare("SELECT status FROM saas_environment_approvals WHERE saas_application_id=? AND environment='PRODUCTION'").bind(applicationId).first<{ status: string }>();
    expect(approval?.status).toBe("DENIED");
  });

  it("a genuinely PASSING SANDBOX conformance run GRANTS the sandbox environment approval", async () => {
    const response = await submitConformanceRoute(DEV_A, applicationId, { schema_version: "1.0.0", environment: "SANDBOX", tested_capabilities: ["INVOICE_SYNC", "PAYMENT_SYNC"], acknowledged_events: ["InvoiceCreated", "PaymentSettled"] });
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.resource.outcome).toBe("PASSED");

    const approval = await env.DB.prepare("SELECT status FROM saas_environment_approvals WHERE saas_application_id=? AND environment='SANDBOX'").bind(applicationId).first<{ status: string }>();
    expect(approval?.status).toBe("GRANTED");
  });

  it("a PASSING PRODUCTION conformance run (now that SANDBOX has passed) still only reaches AWAITING_AUTHORITY, never GRANTED — no live path to production from a self-submitted suite alone", async () => {
    const response = await submitConformanceRoute(DEV_A, applicationId, { schema_version: "1.0.0", environment: "PRODUCTION", tested_capabilities: ["INVOICE_SYNC", "PAYMENT_SYNC"], acknowledged_events: ["InvoiceCreated", "PaymentSettled"] });
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.resource.outcome).toBe("PASSED");

    const approval = await env.DB.prepare("SELECT status FROM saas_environment_approvals WHERE saas_application_id=? AND environment='PRODUCTION'").bind(applicationId).first<{ status: string }>();
    expect(approval?.status).toBe("AWAITING_AUTHORITY");
  });

  it("a national-scope actor may submit conformance on another actor's application (oversight)", async () => {
    const response = await submitConformanceRoute(PLATFORM_ADMIN, applicationId, { schema_version: "1.0.0", environment: "SANDBOX", tested_capabilities: ["INVOICE_SYNC"], acknowledged_events: ["InvoiceCreated"] });
    expect(response.status).toBe(201);
  });

  it("denies GetUsage to an actor who neither registered the provider nor holds national scope", async () => {
    const response = await usageRoute(DEV_B, providerId);
    expect(response.status).toBe(403);
  });

  it("GetUsage reports the provider, its applications, environment approvals, and ties back into Module 10 Phase A's real integration usage", async () => {
    const before = await usageRoute(DEV_A, providerId);
    expect(before.status).toBe(200);
    const beforeBody = await before.json();
    expect(beforeBody.provider.provider_key).toBe("SAASCO_ACCOUNTING");
    expect(beforeBody.applications.length).toBe(1);
    expect(beforeBody.environmentApprovals.length).toBe(2);
    expect(beforeBody.connectionCount).toBe(0);

    // Tie-in: a platform-scope actor registers a real Phase A integration_connections row
    // for this exact provider_key, and GetUsage picks it up honestly.
    const integrationResponse = await registerIntegrationRoute(PLATFORM_ADMIN, {
      schema_version: "1.0.0", provider_key: "SAASCO_ACCOUNTING", category: "ACCOUNTING", display_name: "SaaSCo Accounting (platform connection)",
      capabilities: ["INVOICE_SYNC"], data_classification: "CONFIDENTIAL",
    });
    expect(integrationResponse.status).toBe(201);

    const after = await usageRoute(DEV_A, providerId);
    expect(after.status).toBe(200);
    const afterBody = await after.json();
    expect(afterBody.connectionCount).toBe(1);
  });
});
