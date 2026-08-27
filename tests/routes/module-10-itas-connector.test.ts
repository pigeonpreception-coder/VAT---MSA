import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 10 Phase B: the ITAS anti-corruption layer. Every ITAS-dependent
 * call path in Modules 1/3 now genuinely goes through ItasIdentityPort
 * (lib/integrations/itas.ts) rather than peeking at `.status()` or
 * hardcoding an outcome — a full-repo audit before this phase found two
 * real violations of that rule: submitRegistrationApplication hardcoded
 * AWAITING_PROVIDER_CONTRACT without ever calling verifyTaxpayer, and
 * submitVatReturn's ITAS branch never called submitVatReturn on the port
 * at all (dead code). Both are fixed here. The port itself is now backed
 * by a genuine sandbox/mock implementation gated by the exact same
 * integration_connections row Module 10 Phase A's own generic connector
 * model manages for provider_key='ITAS' — reusing Phase A's real,
 * already-tested guard rather than inventing a separate feature flag.
 *
 * This suite proves three things separately:
 * 1. REAL COMMAND PATH — every ITAS-dependent command, driven through its
 *    actual route, fails closed today with a typed, honest "blocked
 *    pending authority" result — never a silent success, never an
 *    unhandled error — and the local vs. ITAS-availability gates inside
 *    submitVatReturn are genuinely distinct checks, not one conflated flag.
 * 2. Phase A and Phase B are actually wired together: ApproveIntegration
 *    (Phase A's own route) is refused against the seeded `integration-itas`
 *    row for the same structural reason proven in Phase A's own tests, so
 *    there is no real path to ever activate ITAS's mock.
 * 3. SIMULATION ONLY — a clearly-labelled direct-DB flip of that row proves
 *    the mock's own verifyTaxpayer/submitVatReturn logic is sound, and
 *    that both fixed call paths pick up a genuine VERIFIED/ACKNOWLEDGED
 *    outcome (and the documented TaxpayerVerified/VATReturnSubmitted
 *    events actually fire) once hypothetically authorised.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const PLATFORM_ADMIN: FixtureUser = { userId: "usr-itb-platform", externalUserId: "ext-itb-platform", email: "platform@itb-test.test" };
const OWNER: FixtureUser = { userId: "usr-itb-owner", externalUserId: "ext-itb-owner", email: "owner@itb-test.test" };

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
    // db/runtime.ts's PLATFORM_SEED_STATEMENTS (which seeds integration-itas) is dev-only
    // (gated on NODE_ENV !== "production") and never runs under the production stub every
    // test file sets — insert the pre-seeded ITAS connection directly, matching its exact real seed shape.
    db.prepare(`INSERT OR IGNORE INTO integration_connections
      (id,organisation_id,provider_key,category,display_name,capabilities,endpoint_reference,credential_reference,configuration_status,operational_status,data_classification,last_health_check_at,last_health_outcome,created_at,updated_at)
      VALUES ('integration-itas',NULL,'ITAS','GOVERNMENT','ITAS statutory services','["IDENTITY_FEDERATION","TAXPAYER_VERIFICATION","RETURN_SUBMISSION"]',NULL,NULL,'REQUIRES_AUTHORITY_CONTRACT','DISABLED','TAX_CONFIDENTIAL',NULL,NULL,?,?)`).bind(now, now),
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-itb", "VAT-ITB", "TIN-ITB", "ITAS Connector Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Contract Street", "finance@itb-test.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-itb", "tp-itb", "ITAS Connector Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-itb-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(PLATFORM_ADMIN.userId, PLATFORM_ADMIN.externalUserId, PLATFORM_ADMIN.email, "Platform Admin", "PILOT_ADMIN", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OWNER.userId, OWNER.externalUserId, OWNER.email, "Owner", "TAXPAYER_OWNER", "tp-itb", "ACTIVE", now),
    ...[PLATFORM_ADMIN, OWNER].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-itb-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
    db.prepare(`INSERT INTO tax_rule_sets (id,jurisdiction,version,effective_from,effective_to,standard_rate_bps,legal_authority_reference,status,approved_by,approved_at,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("trs-itb-approved", "NA", "2026.1", "2026-01-01", null, 1500, "VAT Act", "AUTHORITY_APPROVED", PLATFORM_ADMIN.userId, now, now),
    db.prepare(`INSERT INTO tax_rule_sets (id,jurisdiction,version,effective_from,effective_to,standard_rate_bps,legal_authority_reference,status,approved_by,approved_at,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("trs-itb-pending", "NA", "2026.2", "2026-02-01", null, 1500, "VAT Act", "ACTIVE", null, null, now),
    db.prepare(`INSERT INTO vat_periods (id,organisation_id,taxpayer_id,period_code,period_start,period_end,due_date,status,lock_version,close_requested_by,close_requested_at,closed_by,closed_at,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,0,NULL,NULL,NULL,NULL,?,?)`)
      .bind("vp-itb-approved", "org-itb", "tp-itb", "2026-01", "2026-01-01", "2026-01-28", "2026-01-25", "FILED", now, now),
    db.prepare(`INSERT INTO vat_periods (id,organisation_id,taxpayer_id,period_code,period_start,period_end,due_date,status,lock_version,close_requested_by,close_requested_at,closed_by,closed_at,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,0,NULL,NULL,NULL,NULL,?,?)`)
      .bind("vp-itb-pending", "org-itb", "tp-itb", "2026-02", "2026-02-01", "2026-02-28", "2026-02-25", "FILED", now, now),
    db.prepare(`INSERT INTO vat_return_versions (id,vat_period_id,organisation_id,taxpayer_id,version_number,parent_version_id,tax_rule_set_id,output_tax_cents,input_tax_cents,adjustment_cents,net_payable_cents,status,ledger_snapshot_hash,generated_by,generated_at,approved_by,approved_at,superseded_at)
      VALUES (?,?,?,?,1,NULL,?,?,?,0,?,'APPROVED',?,?,?,?,?,NULL)`)
      .bind("rv-itb-approved", "vp-itb-approved", "org-itb", "tp-itb", "trs-itb-approved", 0, 40_000, -40_000, "hash-itb-approved", OWNER.userId, now, OWNER.userId, now),
    db.prepare(`INSERT INTO vat_return_versions (id,vat_period_id,organisation_id,taxpayer_id,version_number,parent_version_id,tax_rule_set_id,output_tax_cents,input_tax_cents,adjustment_cents,net_payable_cents,status,ledger_snapshot_hash,generated_by,generated_at,approved_by,approved_at,superseded_at)
      VALUES (?,?,?,?,1,NULL,?,?,?,0,?,'APPROVED',?,?,?,?,?,NULL)`)
      .bind("rv-itb-pending", "vp-itb-pending", "org-itb", "tp-itb", "trs-itb-pending", 0, 20_000, -20_000, "hash-itb-pending", OWNER.userId, now, OWNER.userId, now),
  ]);
}

async function registerAppRoute(body: unknown): Promise<Response> {
  const { POST } = await import("@/app/api/v1/registration-applications/route");
  actingAs(PLATFORM_ADMIN);
  return POST(jsonRequest("https://vat-msa.local/api/v1/registration-applications", "POST", body));
}

async function verifyIdentifiersRoute(actor: FixtureUser, taxpayerId: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/taxpayers/[id]/identifiers/verification/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/taxpayers/${taxpayerId}/identifiers/verification`, "POST", {}), { params: Promise.resolve({ id: taxpayerId }) });
}

async function submitReturnRoute(actor: FixtureUser, versionId: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/vat-returns/[id]/submissions/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/vat-returns/${versionId}/submissions`, "POST", {}), { params: Promise.resolve({ id: versionId }) });
}

async function approveIntegrationRoute(actor: FixtureUser, id: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/integrations/[id]/approval/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/integrations/${id}/approval`, "POST", {}), { params: Promise.resolve({ id }) });
}

async function outboxEventsFor(eventType: string): Promise<Array<{ payload: string }>> {
  const result = await env.DB.prepare("SELECT payload FROM outbox_events WHERE event_type=? ORDER BY occurred_at DESC").bind(eventType).all<{ payload: string }>();
  return result.results;
}

describe("Module 10 ITAS anti-corruption layer: fixed call paths + sandbox/mock connector (Phase B)", () => {
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

  it("integration-itas is seeded DISABLED/REQUIRES_AUTHORITY_CONTRACT — the guard's starting state, reused from Module 10 Phase A", async () => {
    const row = await env.DB.prepare("SELECT configuration_status,operational_status FROM integration_connections WHERE provider_key='ITAS' AND organisation_id IS NULL").first<{ configuration_status: string; operational_status: string }>();
    expect(row?.configuration_status).toBe("REQUIRES_AUTHORITY_CONTRACT");
    expect(row?.operational_status).toBe("DISABLED");
  });

  it("REAL COMMAND PATH: submitRegistrationApplication now genuinely attempts verifyTaxpayer (previously hardcoded) and honestly reports AWAITING_PROVIDER_CONTRACT", async () => {
    const response = await registerAppRoute({ schema_version: "1.0.0", vat_number: "VAT-ITB-NEW1", tin: "TIN-ITB-NEW1", legal_name: "New Registrant One (Pty) Ltd", taxpayer_type: "PRIVATE_COMPANY", return_frequency: "MONTHLY", address: "2 New Street", email: "new1@itb-test.test" });
    expect(response.status).toBe(202);
    const body = await response.json();
    expect(body.verification_status).toBe("AWAITING_PROVIDER_CONTRACT");

    const verification = await env.DB.prepare("SELECT status,response_hash,verified_taxpayer_id FROM registration_verifications WHERE registration_application_id=?").bind(body.registration_id).first<{ status: string; response_hash: string | null; verified_taxpayer_id: string | null }>();
    expect(verification?.status).toBe("AWAITING_PROVIDER_CONTRACT");
    expect(verification?.response_hash).toBeNull();
    expect(verification?.verified_taxpayer_id).toBeNull();
  });

  it("REAL COMMAND PATH: VerifyIdentifiers honestly reports AWAITING_PROVIDER_CONTRACT, never a fabricated VERIFIED", async () => {
    const response = await verifyIdentifiersRoute(OWNER, "tp-itb");
    expect(response.status).toBe(200);
    const body = await response.json();
    expect(body.verification.status).toBe("AWAITING_PROVIDER_CONTRACT");
    expect(body.verification.requestReference).toBeNull();
  });

  it("REAL COMMAND PATH: SubmitReturn against a rule set lacking authority approval is blocked by the local gate, never even attempting ITAS", async () => {
    const response = await submitReturnRoute(OWNER, "rv-itb-pending");
    expect(response.status).toBe(202);
    const body = await response.json();
    expect(body.resource.status).toBe("BLOCKED_CONFIGURATION");
    expect(body.resource.last_error).toContain("authority approval");
  });

  it("REAL COMMAND PATH: SubmitReturn against an authority-approved rule set now genuinely calls ItasIdentityPort.submitVatReturn (previously dead code) and is blocked by ITAS's own unavailability, not the rule gate", async () => {
    const response = await submitReturnRoute(OWNER, "rv-itb-approved");
    expect(response.status).toBe(202);
    const body = await response.json();
    expect(body.resource.status).toBe("BLOCKED_CONFIGURATION");
    expect(body.resource.last_error).toContain("ITAS technical contract");
    expect(body.resource.provider_reference).toBeNull();
    expect(body.resource.attempt_count).toBe(1);
  });

  it("a genuine retry (fresh idempotency key, same version) updates the same submission row in place instead of hitting UNIQUE(provider, request_reference) as an unhandled 500 — the exact bug this phase's audit found", async () => {
    const first = await submitReturnRoute(OWNER, "rv-itb-approved");
    expect(first.status).toBe(202);
    const firstBody = await first.json();
    expect(firstBody.resource.status).toBe("BLOCKED_CONFIGURATION");
    expect(firstBody.resource.attempt_count).toBe(2);

    const second = await submitReturnRoute(OWNER, "rv-itb-approved");
    expect(second.status).toBe(202);
    const secondBody = await second.json();
    expect(secondBody.resource.status).toBe("BLOCKED_CONFIGURATION");
    expect(secondBody.resource.attempt_count).toBe(3);
    expect(secondBody.resource.id).toBe(firstBody.resource.id);

    const count = await env.DB.prepare("SELECT COUNT(*) AS n FROM vat_return_submissions WHERE vat_return_version_id='rv-itb-approved'").first<{ n: number }>();
    expect(count?.n).toBe(1);
  });

  it("IdentityLinked's outbox payload now carries every field event-catalog.csv names as its minimum payload (previously missing assurance/occurred_at)", async () => {
    const { linkIdentity } = await import("@/lib/data/identity-repository");
    actingAs(PLATFORM_ADMIN);
    const { getCurrentUser } = await import("@/lib/auth");
    const actor = await getCurrentUser();
    await linkIdentity(actor, { user_id: OWNER.userId, provider_key: "SITES_WORKSPACE", subject: "itb-linked-subject" }, crypto.randomUUID());

    const events = await outboxEventsFor("IdentityLinked");
    expect(events.length).toBeGreaterThan(0);
    const payload = JSON.parse(events[0].payload);
    expect(payload.userId).toBe(OWNER.userId);
    expect(payload.providerId).toBe("idp-itb-workspace");
    expect(payload.assurance).toBeTruthy();
    expect(payload.occurredAt).toBeTruthy();
  });

  it("PHASE A/B TIE-IN: ApproveIntegration (Phase A's own route) refuses to move integration-itas out of REQUIRES_AUTHORITY_CONTRACT — there is no real path to ever activate ITAS's mock", async () => {
    const response = await approveIntegrationRoute(PLATFORM_ADMIN, "integration-itas");
    expect(response.status).toBe(422);
    const row = await env.DB.prepare("SELECT configuration_status,operational_status FROM integration_connections WHERE id='integration-itas'").first<{ configuration_status: string; operational_status: string }>();
    expect(row?.configuration_status).toBe("REQUIRES_AUTHORITY_CONTRACT");
    expect(row?.operational_status).toBe("DISABLED");
  });

  it("SIMULATION ONLY (never reachable via any real command — see the file-level comment and the Phase A/B tie-in test above): flipping integration-itas directly to CONFIGURED/OPERATIONAL lets every fixed call path genuinely succeed, and fires the documented TaxpayerVerified/VATReturnSubmitted events", async () => {
    await env.DB.prepare("UPDATE integration_connections SET configuration_status='CONFIGURED', operational_status='OPERATIONAL' WHERE id='integration-itas'").run();

    const verifyResponse = await verifyIdentifiersRoute(OWNER, "tp-itb");
    expect(verifyResponse.status).toBe(200);
    const verifyBody = await verifyResponse.json();
    expect(verifyBody.verification.status).toBe("VERIFIED");
    expect(verifyBody.verification.requestReference).toMatch(/^SANDBOX-VERIFY-/);

    const verifiedEvents = await outboxEventsFor("TaxpayerVerified");
    expect(verifiedEvents.length).toBeGreaterThan(0);
    const verifiedPayload = JSON.parse(verifiedEvents[0].payload);
    expect(verifiedPayload.taxpayerId).toBe("tp-itb");
    expect(verifiedPayload.source).toBe("ITAS");
    expect(verifiedPayload.sourceVersion).toBeTruthy();
    expect(verifiedPayload.verifiedAt).toBeTruthy();

    const submitResponse = await submitReturnRoute(OWNER, "rv-itb-approved");
    expect(submitResponse.status).toBe(202);
    const submitBody = await submitResponse.json();
    expect(submitBody.resource.status).toBe("ACKNOWLEDGED");
    expect(submitBody.resource.provider_reference).toMatch(/^SANDBOX-SUB-/);
    expect(submitBody.resource.acknowledged_at).toBeTruthy();
    // Same underlying submission row as the two earlier blocked attempts — updated in place, not duplicated.
    expect(submitBody.resource.attempt_count).toBe(4);

    const submittedEvents = await outboxEventsFor("VATReturnSubmitted");
    expect(submittedEvents.length).toBeGreaterThan(0);
    const submittedPayload = JSON.parse(submittedEvents[0].payload);
    expect(submittedPayload.vatReturnId).toBe("rv-itb-approved");
    expect(submittedPayload.payloadHash).toBe("hash-itb-approved");
    expect(submittedPayload.submittedAt).toBeTruthy();

    // Attempting to submit again after a genuine ACKNOWLEDGED outcome is refused, not silently re-attempted.
    const thirdAttempt = await submitReturnRoute(OWNER, "rv-itb-approved");
    expect(thirdAttempt.status).toBe(409);

    const registerResponse = await registerAppRoute({ schema_version: "1.0.0", vat_number: "VAT-ITB-NEW2", tin: "TIN-ITB-NEW2", legal_name: "New Registrant Two (Pty) Ltd", taxpayer_type: "PRIVATE_COMPANY", return_frequency: "MONTHLY", address: "3 New Street", email: "new2@itb-test.test" });
    expect(registerResponse.status).toBe(202);
    expect((await registerResponse.json()).verification_status).toBe("VERIFIED");

    // Restore the guard to its real, DISABLED default — this row is never left active beyond this one simulation test.
    await env.DB.prepare("UPDATE integration_connections SET configuration_status='REQUIRES_AUTHORITY_CONTRACT', operational_status='DISABLED' WHERE id='integration-itas'").run();
  });
});
