import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 9 Phase D: RecordPayment/AllocatePayment/GetOutstanding against a
 * typed PaymentConnectorPort (lib/integrations/payment.ts), backed by a
 * genuine sandbox/mock implementation whose every mutating call is gated by
 * a real, DB-backed environment guard — the FIRST actual enforcement use of
 * service_components (db/runtime.ts's component-payment row, seeded
 * DISABLED; previously that table was read only for display, see
 * lib/data/platform-repository.ts). This suite proves the two halves of
 * the playbook's Definition of Done separately and deliberately:
 *
 * 1. The REAL command path (every test except the last) — RecordPayment,
 *    AllocatePayment and their conflict/validation/permission edges, all
 *    driven through the actual route handlers exactly like every other
 *    Module 9 test file. Under this codebase's real, unmodified state, the
 *    guard refuses every single attempt, and payment_instructions never
 *    receives a single row — asserted directly against the table, not just
 *    against a response shape.
 * 2. A single, clearly-labelled SIMULATION at the very end, which manually
 *    flips component-payment's row directly in the fake D1 — something no
 *    command anywhere in this codebase can do — purely to prove the
 *    SandboxPaymentConnector's own mock logic is sound if it were ever
 *    hypothetically authorised. This never goes through a real command and
 *    creates no real activation path; it exists only so Phase D's mock
 *    logic isn't dead code nobody has ever actually run.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const CLAIMANT: FixtureUser = { userId: "usr-rf4-claimant", externalUserId: "ext-rf4-claimant", email: "owner@rf4-test.test" };
const OFFICER_A: FixtureUser = { userId: "usr-rf4-officer-a", externalUserId: "ext-rf4-officer-a", email: "officer-a@rf4-test.test" };
const OFFICER_B: FixtureUser = { userId: "usr-rf4-officer-b", externalUserId: "ext-rf4-officer-b", email: "officer-b@rf4-test.test" };
const AUDITOR: FixtureUser = { userId: "usr-rf4-auditor", externalUserId: "ext-rf4-auditor", email: "auditor@rf4-test.test" };

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
    // db/runtime.ts's PLATFORM_SEED_STATEMENTS (which seeds component-payment) is dev-only
    // (gated on NODE_ENV !== "production") and never runs under the production stub every
    // test file sets — insert the guard row directly, matching its exact real seed shape.
    db.prepare(`INSERT OR IGNORE INTO service_components VALUES ('component-payment','PAYMENT_CONNECTOR','Refund payment connector','EXTERNAL','CRITICAL','REQUIRES_AUTHORITY_CONTRACT','DISABLED','Bank/payment gateway contract, settlement account and NamRA payment authority approval',?,'Payment is DISABLED PENDING AUTHORITY -- no live payment instruction is issued; RecordPayment/AllocatePayment refuse to run until this row is authorised.')`).bind(now),
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-rf4", "VAT-RF4", "TIN-RF4", "Payment Connector Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Payment Street", "finance@rf4-test.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-rf4", "tp-rf4", "Payment Connector Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-rf4-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(CLAIMANT.userId, CLAIMANT.externalUserId, CLAIMANT.email, "Claimant", "TAXPAYER_OWNER", "tp-rf4", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OFFICER_A.userId, OFFICER_A.externalUserId, OFFICER_A.email, "Officer A", "NAMRA_REFUND_OFFICER", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OFFICER_B.userId, OFFICER_B.externalUserId, OFFICER_B.email, "Officer B", "NAMRA_REFUND_OFFICER", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(AUDITOR.userId, AUDITOR.externalUserId, AUDITOR.email, "Auditor", "NAMRA_AUDITOR", null, "ACTIVE", now),
    ...[CLAIMANT, OFFICER_A, OFFICER_B, AUDITOR].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-rf4-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
    db.prepare(`INSERT INTO tax_rule_sets (id,jurisdiction,version,effective_from,effective_to,standard_rate_bps,legal_authority_reference,status,approved_by,approved_at,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("trs-rf4-2026", "NA", "2026.1", "2026-01-01", null, 1500, "VAT Act", "ACTIVE", null, null, now),
    // vp-rf4-paid: taken all the way to PAYMENT_PENDING. vp-rf4-unpaid: left at RECEIVED, to prove the wrong-status conflict.
    db.prepare(`INSERT INTO vat_periods (id,organisation_id,taxpayer_id,period_code,period_start,period_end,due_date,status,lock_version,close_requested_by,close_requested_at,closed_by,closed_at,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,0,NULL,NULL,NULL,NULL,?,?)`)
      .bind("vp-rf4-paid", "org-rf4", "tp-rf4", "2026-01", "2026-01-01", "2026-01-28", "2026-01-25", "FILED", now, now),
    db.prepare(`INSERT INTO vat_periods (id,organisation_id,taxpayer_id,period_code,period_start,period_end,due_date,status,lock_version,close_requested_by,close_requested_at,closed_by,closed_at,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,0,NULL,NULL,NULL,NULL,?,?)`)
      .bind("vp-rf4-unpaid", "org-rf4", "tp-rf4", "2026-02", "2026-02-01", "2026-02-28", "2026-02-25", "FILED", now, now),
    db.prepare(`INSERT INTO vat_return_versions (id,vat_period_id,organisation_id,taxpayer_id,version_number,parent_version_id,tax_rule_set_id,output_tax_cents,input_tax_cents,adjustment_cents,net_payable_cents,status,ledger_snapshot_hash,generated_by,generated_at,approved_by,approved_at,superseded_at)
      VALUES (?,?,?,?,1,NULL,?,?,?,0,?,'FILED',?,?,?,?,?,NULL)`)
      .bind("rv-rf4-paid", "vp-rf4-paid", "org-rf4", "tp-rf4", "trs-rf4-2026", 0, 100_000, -100_000, "hash-rf4-paid", CLAIMANT.userId, now, CLAIMANT.userId, now),
    db.prepare(`INSERT INTO vat_return_versions (id,vat_period_id,organisation_id,taxpayer_id,version_number,parent_version_id,tax_rule_set_id,output_tax_cents,input_tax_cents,adjustment_cents,net_payable_cents,status,ledger_snapshot_hash,generated_by,generated_at,approved_by,approved_at,superseded_at)
      VALUES (?,?,?,?,1,NULL,?,?,?,0,?,'FILED',?,?,?,?,?,NULL)`)
      .bind("rv-rf4-unpaid", "vp-rf4-unpaid", "org-rf4", "tp-rf4", "trs-rf4-2026", 0, 50_000, -50_000, "hash-rf4-unpaid", CLAIMANT.userId, now, CLAIMANT.userId, now),
  ]);
}

async function requestRefundRoute(versionId: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/refunds/route");
  actingAs(CLAIMANT);
  return POST(jsonRequest("https://vat-msa.local/api/v1/refunds", { schema_version: "1.0.0", vat_return_version_id: versionId }));
}

async function transitionRoute(actor: FixtureUser, claimId: string, action: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/refunds/[id]/transition/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/refunds/${claimId}/transition`, { schema_version: "1.0.0", action, findings: "Reviewed the claim against the available evidence." }), { params: Promise.resolve({ id: claimId }) });
}

async function recordPaymentRoute(actor: FixtureUser, claimId: string, body: unknown, idempotencyKey?: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/refunds/[id]/payment/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/refunds/${claimId}/payment`, body, idempotencyKey), { params: Promise.resolve({ id: claimId }) });
}

async function allocatePaymentRoute(actor: FixtureUser, claimId: string, body: unknown): Promise<Response> {
  const { POST } = await import("@/app/api/v1/refunds/[id]/payment/allocation/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/refunds/${claimId}/payment/allocation`, body), { params: Promise.resolve({ id: claimId }) });
}

async function outstandingRoute(actor: FixtureUser): Promise<Response> {
  const { GET } = await import("@/app/api/v1/payments/outstanding/route");
  actingAs(actor);
  return GET(new Request("https://vat-msa.local/api/v1/payments/outstanding"));
}

async function claimIdFor(versionId: string): Promise<string> {
  const row = await env.DB.prepare("SELECT id FROM refund_claims WHERE vat_return_version_id=?").bind(versionId).first<{ id: string }>();
  if (!row) throw new Error(`No refund claim found for ${versionId}`);
  return row.id;
}

async function paymentInstructionCount(): Promise<number> {
  const row = await env.DB.prepare("SELECT COUNT(*) AS n FROM payment_instructions").first<{ n: number }>();
  return row?.n ?? 0;
}

describe("Module 9 Payment connector: guarded RecordPayment/AllocatePayment/GetOutstanding (Phase D)", () => {
  let paidClaimId: string;
  let unpaidClaimId: string;

  beforeAll(async () => {
    vi.stubEnv("NODE_ENV", "production");
    env.DB = createFakeD1();
    const { ensureDatabase } = await import("@/db/runtime");
    await ensureDatabase();
    await seedFixture();

    expect((await requestRefundRoute("rv-rf4-paid")).status).toBe(201);
    expect((await requestRefundRoute("rv-rf4-unpaid")).status).toBe(201);
    paidClaimId = await claimIdFor("rv-rf4-paid");
    unpaidClaimId = await claimIdFor("rv-rf4-unpaid");

    // Drive rv-rf4-paid's claim all the way to PAYMENT_PENDING: three APPROVEs by
    // OFFICER_A, then the material, fund-releasing APPROVE by a distinct
    // OFFICER_B (Phase C's maker-checker rule).
    expect((await transitionRoute(OFFICER_A, paidClaimId, "APPROVE")).status).toBe(200);
    expect((await transitionRoute(OFFICER_A, paidClaimId, "APPROVE")).status).toBe(200);
    expect((await transitionRoute(OFFICER_A, paidClaimId, "APPROVE")).status).toBe(200);
    const finalApproval = await transitionRoute(OFFICER_B, paidClaimId, "APPROVE");
    expect(finalApproval.status).toBe(200);
    expect((await finalApproval.json()).resource.status).toBe("PAYMENT_PENDING");
    // rv-rf4-unpaid's claim is deliberately left at RECEIVED.
  });

  afterAll(() => {
    vi.unstubAllEnvs();
  });

  it("component-payment is seeded DISABLED/REQUIRES_AUTHORITY_CONTRACT — the guard's starting state", async () => {
    const row = await env.DB.prepare("SELECT configuration_status,operational_status FROM service_components WHERE component_key='PAYMENT_CONNECTOR'").first<{ configuration_status: string; operational_status: string }>();
    expect(row?.configuration_status).toBe("REQUIRES_AUTHORITY_CONTRACT");
    expect(row?.operational_status).toBe("DISABLED");
  });

  it("refuses RecordPayment for a claim that has not reached PAYMENT_PENDING", async () => {
    const response = await recordPaymentRoute(OFFICER_A, unpaidClaimId, { schema_version: "1.0.0", beneficiary_reference: "NA-BANK-ACC-000111222", provider: "Bank of Namibia" });
    expect(response.status).toBe(409);
    expect(await paymentInstructionCount()).toBe(0);
  });

  it("rejects RecordPayment with a validation error for a missing beneficiary reference", async () => {
    const response = await recordPaymentRoute(OFFICER_A, paidClaimId, { schema_version: "1.0.0", beneficiary_reference: "", provider: "Bank of Namibia" });
    expect(response.status).toBe(422);
    expect(await paymentInstructionCount()).toBe(0);
  });

  it("denies RecordPayment to a role without payments:record", async () => {
    const response = await recordPaymentRoute(AUDITOR, paidClaimId, { schema_version: "1.0.0", beneficiary_reference: "NA-BANK-ACC-000111222", provider: "Bank of Namibia" });
    expect(response.status).toBe(403);
    expect(await paymentInstructionCount()).toBe(0);
  });

  it("REAL COMMAND PATH: RecordPayment on a genuinely PAYMENT_PENDING claim is refused by the sandbox guard, reports AWAITING_AUTHORITY honestly, and writes zero payment_instructions rows", async () => {
    const response = await recordPaymentRoute(OFFICER_A, paidClaimId, { schema_version: "1.0.0", beneficiary_reference: "NA-BANK-ACC-000111222", provider: "Bank of Namibia" });
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.resource.status).toBe("AWAITING_AUTHORITY");
    expect(body.resource.provider_reference).toBeNull();
    expect(await paymentInstructionCount()).toBe(0);

    const claim = await env.DB.prepare("SELECT payment_instruction_id FROM refund_claims WHERE id=?").bind(paidClaimId).first<{ payment_instruction_id: string | null }>();
    expect(claim?.payment_instruction_id).toBeNull();
  });

  it("REAL COMMAND PATH: repeated RecordPayment attempts stay refused — no accumulation, no eventual leak through retries", async () => {
    for (let attempt = 0; attempt < 3; attempt += 1) {
      const response = await recordPaymentRoute(OFFICER_A, paidClaimId, { schema_version: "1.0.0", beneficiary_reference: "NA-BANK-ACC-000111222", provider: "Bank of Namibia" });
      expect(response.status).toBe(201);
      expect((await response.json()).resource.status).toBe("AWAITING_AUTHORITY");
    }
    expect(await paymentInstructionCount()).toBe(0);
  });

  it("REAL COMMAND PATH: AllocatePayment is refused with a conflict, since no payment_instructions row was ever created to allocate against", async () => {
    const response = await allocatePaymentRoute(OFFICER_A, paidClaimId, { schema_version: "1.0.0", settlement_reference: "STL-0001", settled_amount_cents: 100_000 });
    expect(response.status).toBe(409);
  });

  it("REAL COMMAND PATH: GetOutstanding lists the PAYMENT_PENDING claim honestly as still unpaid, and reports the connector's real REQUIRES_AUTHORITY_CONTRACT state", async () => {
    const response = await outstandingRoute(OFFICER_A);
    expect(response.status).toBe(200);
    const body = await response.json();
    const listed = body.claims.find((claim: { id: string }) => claim.id === paidClaimId);
    expect(listed).toBeTruthy();
    expect(listed.net_payable_cents).toBe(100_000);
    expect(body.totalOutstandingCents).toBeGreaterThanOrEqual(100_000);
    expect(body.connector.state).toBe("REQUIRES_AUTHORITY_CONTRACT");
    expect(body.connector.configured).toBe(false);
  });

  it("GetOutstanding is restricted to national-scope refund roles", async () => {
    const response = await outstandingRoute(CLAIMANT);
    expect(response.status).toBe(403);
  });

  it("SIMULATION ONLY (never reachable via any real command — see the file-level comment): flipping component-payment directly to SANDBOX_CONFIGURED/SANDBOX_ACTIVE lets RecordPayment and AllocatePayment genuinely succeed, proving the mock connector's own logic is sound", async () => {
    await env.DB.prepare("UPDATE service_components SET configuration_status='SANDBOX_CONFIGURED', operational_status='SANDBOX_ACTIVE' WHERE component_key='PAYMENT_CONNECTOR'").run();

    const recordResponse = await recordPaymentRoute(OFFICER_A, paidClaimId, { schema_version: "1.0.0", beneficiary_reference: "NA-BANK-ACC-000111222", provider: "Bank of Namibia" });
    expect(recordResponse.status).toBe(201);
    const recordBody = await recordResponse.json();
    expect(recordBody.resource.status).toBe("INITIATED");
    expect(recordBody.resource.provider_reference).toMatch(/^SANDBOX-/);
    expect(await paymentInstructionCount()).toBe(1);

    const claim = await env.DB.prepare("SELECT payment_instruction_id FROM refund_claims WHERE id=?").bind(paidClaimId).first<{ payment_instruction_id: string | null }>();
    expect(claim?.payment_instruction_id).toBeTruthy();

    const allocateResponse = await allocatePaymentRoute(OFFICER_A, paidClaimId, { schema_version: "1.0.0", settlement_reference: "STL-SANDBOX-0001", settled_amount_cents: 100_000 });
    expect(allocateResponse.status).toBe(201);
    const allocateBody = await allocateResponse.json();
    expect(allocateBody.resource.status).toBe("SETTLED");
    expect(allocateBody.resource.provider_reference).toBe("STL-SANDBOX-0001");

    // A second AllocatePayment against an already-settled instruction is refused.
    const secondAllocate = await allocatePaymentRoute(OFFICER_A, paidClaimId, { schema_version: "1.0.0", settlement_reference: "STL-SANDBOX-0002", settled_amount_cents: 100_000 });
    expect(secondAllocate.status).toBe(409);

    // Restore the guard to its real, DISABLED default — this row is never left active beyond this one simulation test.
    await env.DB.prepare("UPDATE service_components SET configuration_status='REQUIRES_AUTHORITY_CONTRACT', operational_status='DISABLED' WHERE component_key='PAYMENT_CONNECTOR'").run();
  });
});
