import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 9 Phase C: "Reuse Module 4's Risk.EvaluateRisk — do not fork a
 * second risk engine here" and "Route by risk tier to configured review
 * lanes" plus "Two-distinct-actor maker-checker enforcement for any
 * material outcome." requestRefund's risk_tier previously came from a
 * naive amount threshold alone. It now also reads the taxpayer's own OPEN
 * risk_indicators — the exact rows Module 4 Phase A's evaluateRisk rule
 * catalogue writes — taking the more severe of the amount-based tier and
 * that live signal (reuseTaxpayerRiskSignal in
 * lib/data/compliance-repository.ts), and persists it as an explainable
 * RISK_INDICATOR_SIGNAL check alongside the rest of Phase B's check
 * battery. risk_tier now has a real behavioural consequence for the first
 * time: transitionRefundClaim requires a genuinely distinct approving
 * officer at every stage for a HIGH/CRITICAL-tier claim (the "enhanced"
 * lane), versus only at the final, fund-releasing
 * PAYMENT_AUTHORISATION→PAYMENT_PENDING step for a standard-tier one — the
 * latter enforced universally, regardless of tier, since that step is
 * always the material outcome. This suite proves both halves: a small
 * claim from a taxpayer with no open risk signal stays on the standard
 * lane (same officer may approve two consecutive non-material stages, but
 * a different officer is still required for the final approval); the same
 * small claim from a taxpayer who *does* carry an open CRITICAL risk
 * indicator is routed onto the enhanced lane purely because of that reused
 * signal, not because of its amount. Proven through the real route
 * handlers (app/api/v1/refunds/...) and
 * lib/data/compliance-repository.ts. See
 * tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const CLAIMANT_STANDARD: FixtureUser = { userId: "usr-rf3-standard", externalUserId: "ext-rf3-standard", email: "owner@rf3-standard.test" };
const CLAIMANT_ELEVATED: FixtureUser = { userId: "usr-rf3-elevated", externalUserId: "ext-rf3-elevated", email: "owner@rf3-elevated.test" };
const OFFICER_A: FixtureUser = { userId: "usr-rf3-officer-a", externalUserId: "ext-rf3-officer-a", email: "officer-a@rf3-test.test" };
const OFFICER_B: FixtureUser = { userId: "usr-rf3-officer-b", externalUserId: "ext-rf3-officer-b", email: "officer-b@rf3-test.test" };

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
    // Taxpayer A: standard lane — no open risk indicators, a small (sub-1,000,000-cent) claim amount.
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-rf3-standard", "VAT-RF3-STD", "TIN-RF3-STD", "Standard Lane Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Risk Routing Street", "finance@rf3-standard.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-rf3-standard", "tp-rf3-standard", "Standard Lane Co (Pty) Ltd", null, "ACTIVE", now, now),
    // Taxpayer B: enhanced lane — an OPEN CRITICAL risk indicator on record, the same shape Module 4 Phase A's
    // evaluateRisk itself writes, but the SAME small claim amount as taxpayer A.
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-rf3-elevated", "VAT-RF3-ELV", "TIN-RF3-ELV", "Elevated Lane Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "2 Risk Routing Street", "finance@rf3-elevated.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-rf3-elevated", "tp-rf3-elevated", "Elevated Lane Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO risk_indicators
      (id,organisation_id,taxpayer_id,subject_type,subject_id,indicator_code,score_bps,severity,rationale,rule_version,decision_effect,status,detected_at,reviewed_by,reviewed_at,assigned_officer_id,escalated_case_id)
      VALUES (?,?,?,'TAXPAYER',?,?,?,?,?,?,'ADVISORY_ONLY','OPEN',?,NULL,NULL,NULL,NULL)`)
      .bind("rind-rf3-1", "org-rf3-elevated", "tp-rf3-elevated", "tp-rf3-elevated", "OBLIGATION_OVERDUE", 8000, "CRITICAL", "Pre-seeded for the Phase C risk-routing test — simulates a prior real EvaluateRisk run.", "RISK-PILOT-2026.2", now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-rf3-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(CLAIMANT_STANDARD.userId, CLAIMANT_STANDARD.externalUserId, CLAIMANT_STANDARD.email, "Standard Claimant", "TAXPAYER_OWNER", "tp-rf3-standard", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(CLAIMANT_ELEVATED.userId, CLAIMANT_ELEVATED.externalUserId, CLAIMANT_ELEVATED.email, "Elevated Claimant", "TAXPAYER_OWNER", "tp-rf3-elevated", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OFFICER_A.userId, OFFICER_A.externalUserId, OFFICER_A.email, "Officer A", "NAMRA_REFUND_OFFICER", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OFFICER_B.userId, OFFICER_B.externalUserId, OFFICER_B.email, "Officer B", "NAMRA_REFUND_OFFICER", null, "ACTIVE", now),
    ...[CLAIMANT_STANDARD, CLAIMANT_ELEVATED, OFFICER_A, OFFICER_B].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-rf3-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
    db.prepare(`INSERT INTO tax_rule_sets (id,jurisdiction,version,effective_from,effective_to,standard_rate_bps,legal_authority_reference,status,approved_by,approved_at,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("trs-rf3-2026", "NA", "2026.1", "2026-01-01", null, 1500, "VAT Act", "ACTIVE", null, null, now),
    db.prepare(`INSERT INTO vat_periods (id,organisation_id,taxpayer_id,period_code,period_start,period_end,due_date,status,lock_version,close_requested_by,close_requested_at,closed_by,closed_at,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,0,NULL,NULL,NULL,NULL,?,?)`)
      .bind("vp-rf3-standard", "org-rf3-standard", "tp-rf3-standard", "2026-01", "2026-01-01", "2026-01-28", "2026-01-25", "FILED", now, now),
    db.prepare(`INSERT INTO vat_periods (id,organisation_id,taxpayer_id,period_code,period_start,period_end,due_date,status,lock_version,close_requested_by,close_requested_at,closed_by,closed_at,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,0,NULL,NULL,NULL,NULL,?,?)`)
      .bind("vp-rf3-elevated", "org-rf3-elevated", "tp-rf3-elevated", "2026-01", "2026-01-01", "2026-01-28", "2026-01-25", "FILED", now, now),
    db.prepare(`INSERT INTO vat_return_versions (id,vat_period_id,organisation_id,taxpayer_id,version_number,parent_version_id,tax_rule_set_id,output_tax_cents,input_tax_cents,adjustment_cents,net_payable_cents,status,ledger_snapshot_hash,generated_by,generated_at,approved_by,approved_at,superseded_at)
      VALUES (?,?,?,?,1,NULL,?,?,?,0,?,'FILED',?,?,?,?,?,NULL)`)
      .bind("rv-rf3-standard", "vp-rf3-standard", "org-rf3-standard", "tp-rf3-standard", "trs-rf3-2026", 0, 100_000, -100_000, "hash-rf3-standard", CLAIMANT_STANDARD.userId, now, CLAIMANT_STANDARD.userId, now),
    db.prepare(`INSERT INTO vat_return_versions (id,vat_period_id,organisation_id,taxpayer_id,version_number,parent_version_id,tax_rule_set_id,output_tax_cents,input_tax_cents,adjustment_cents,net_payable_cents,status,ledger_snapshot_hash,generated_by,generated_at,approved_by,approved_at,superseded_at)
      VALUES (?,?,?,?,1,NULL,?,?,?,0,?,'FILED',?,?,?,?,?,NULL)`)
      .bind("rv-rf3-elevated", "vp-rf3-elevated", "org-rf3-elevated", "tp-rf3-elevated", "trs-rf3-2026", 0, 100_000, -100_000, "hash-rf3-elevated", CLAIMANT_ELEVATED.userId, now, CLAIMANT_ELEVATED.userId, now),
  ]);
}

async function requestRefundRoute(actor: FixtureUser, versionId: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/refunds/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/refunds", { schema_version: "1.0.0", vat_return_version_id: versionId }));
}

async function transitionRoute(actor: FixtureUser, claimId: string, action: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/refunds/[id]/transition/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/refunds/${claimId}/transition`, { schema_version: "1.0.0", action, findings: "Reviewed the claim against the available evidence." }), { params: Promise.resolve({ id: claimId }) });
}

async function checksRoute(actor: FixtureUser, claimId: string): Promise<Response> {
  const { GET } = await import("@/app/api/v1/refunds/[id]/checks/route");
  actingAs(actor);
  return GET(new Request(`https://vat-msa.local/api/v1/refunds/${claimId}/checks`), { params: Promise.resolve({ id: claimId }) });
}

async function claimIdFor(versionId: string): Promise<string> {
  const row = await env.DB.prepare("SELECT id FROM refund_claims WHERE vat_return_version_id=?").bind(versionId).first<{ id: string }>();
  if (!row) throw new Error(`No refund claim found for ${versionId}`);
  return row.id;
}

describe("Module 9 refund risk routing & maker-checker: reused risk_indicators signal, tier-gated review lanes (Phase C)", () => {
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

  it("stays MEDIUM-tier (amount-based) for a taxpayer with no open risk indicators", async () => {
    const response = await requestRefundRoute(CLAIMANT_STANDARD, "rv-rf3-standard");
    expect(response.status).toBe(201);
    expect((await response.json()).resource.risk_tier).toBe("MEDIUM");
  });

  it("elevates risk_tier to CRITICAL purely from a reused, pre-existing risk indicator — the same amount as the standard-lane claim above", async () => {
    const response = await requestRefundRoute(CLAIMANT_ELEVATED, "rv-rf3-elevated");
    expect(response.status).toBe(201);
    expect((await response.json()).resource.risk_tier).toBe("CRITICAL");

    const claimId = await claimIdFor("rv-rf3-elevated");
    const checksResponse = await checksRoute(OFFICER_A, claimId);
    const checks = (await checksResponse.json()).checks as { check_code: string; status: string; rationale: string }[];
    const riskCheck = checks.find((c) => c.check_code === "RISK_INDICATOR_SIGNAL");
    expect(riskCheck?.status).toBe("FAIL");
    expect(riskCheck?.rationale).toContain("CRITICAL");
  });

  it("reports RISK_INDICATOR_SIGNAL as PASS for the standard-lane claim, with no open indicators to report", async () => {
    const claimId = await claimIdFor("rv-rf3-standard");
    const checksResponse = await checksRoute(OFFICER_A, claimId);
    const checks = (await checksResponse.json()).checks as { check_code: string; status: string }[];
    expect(checks.find((c) => c.check_code === "RISK_INDICATOR_SIGNAL")?.status).toBe("PASS");
  });

  it("standard lane (MEDIUM tier): the same officer may approve two consecutive non-material stages, but a distinct officer is still required for the final, material approval", async () => {
    const claimId = await claimIdFor("rv-rf3-standard");
    // RECEIVED -> RISK_REVIEW and RISK_REVIEW -> OFFICER_REVIEW: same officer, both allowed on the standard lane.
    expect((await transitionRoute(OFFICER_A, claimId, "APPROVE")).status).toBe(200);
    expect((await transitionRoute(OFFICER_A, claimId, "APPROVE")).status).toBe(200);
    let claim = await env.DB.prepare("SELECT status FROM refund_claims WHERE id=?").bind(claimId).first<{ status: string }>();
    expect(claim?.status).toBe("OFFICER_REVIEW");

    // OFFICER_REVIEW -> PAYMENT_AUTHORISATION: same officer again is still fine (not yet the material step).
    expect((await transitionRoute(OFFICER_A, claimId, "APPROVE")).status).toBe(200);

    // PAYMENT_AUTHORISATION -> PAYMENT_PENDING is the material, fund-releasing outcome: the SAME officer is refused...
    const sameOfficerAttempt = await transitionRoute(OFFICER_A, claimId, "APPROVE");
    expect(sameOfficerAttempt.status).toBe(403);
    claim = await env.DB.prepare("SELECT status FROM refund_claims WHERE id=?").bind(claimId).first<{ status: string }>();
    expect(claim?.status).toBe("PAYMENT_AUTHORISATION");

    // ...but a genuinely distinct officer succeeds.
    const distinctOfficer = await transitionRoute(OFFICER_B, claimId, "APPROVE");
    expect(distinctOfficer.status).toBe(200);
    expect((await distinctOfficer.json()).resource.status).toBe("PAYMENT_PENDING");
  });

  it("enhanced lane (CRITICAL tier): the same officer is refused at every stage, not just the final one", async () => {
    const claimId = await claimIdFor("rv-rf3-elevated");
    expect((await transitionRoute(OFFICER_A, claimId, "APPROVE")).status).toBe(200);
    let claim = await env.DB.prepare("SELECT status FROM refund_claims WHERE id=?").bind(claimId).first<{ status: string }>();
    expect(claim?.status).toBe("RISK_REVIEW");

    // The SAME officer approving the very next stage is refused here — unlike the standard lane above.
    const sameOfficerMidChain = await transitionRoute(OFFICER_A, claimId, "APPROVE");
    expect(sameOfficerMidChain.status).toBe(403);
    claim = await env.DB.prepare("SELECT status FROM refund_claims WHERE id=?").bind(claimId).first<{ status: string }>();
    expect(claim?.status).toBe("RISK_REVIEW");

    // A distinct officer succeeds.
    expect((await transitionRoute(OFFICER_B, claimId, "APPROVE")).status).toBe(200);
    claim = await env.DB.prepare("SELECT status FROM refund_claims WHERE id=?").bind(claimId).first<{ status: string }>();
    expect(claim?.status).toBe("OFFICER_REVIEW");
  });
});
