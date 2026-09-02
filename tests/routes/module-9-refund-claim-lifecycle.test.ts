import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 9 Phase A: a 2026-08-26 audit found the refund claim "state
 * machine" was, in reality, 6 flat status strings set inline across two
 * functions (requestRefund/reviewRefund) — no adjacency table, nothing
 * stopping an out-of-order review decision, and a HOLD decision the old
 * validator accepted but the status model couldn't express. This suite
 * proves the real replacement: a genuine adjacency-list state machine
 * (lib/domain/compliance.ts's REFUND_CLAIM_TRANSITIONS, mirroring Module
 * 4's CASE_TRANSITIONS), one shared transition path per actor type
 * (transitionRefundClaim for the officer, disputeRefund for the taxpayer's
 * one DISPUTE action), a real pause/resume cycle sharing one resume_status
 * column, and a live (never cached) statutory debt-offset computation
 * against tax_obligations at the PAYMENT_AUTHORISATION -> PAYMENT_PENDING
 * step. PAYMENT_PENDING is a deliberate terminal boundary: Payment itself
 * (Module 9 Phase D) stays DISABLED PENDING AUTHORITY, so nothing beyond
 * it — no Paid/Failed/Reversed, no payment_instructions write — exists
 * yet. Proven through the real route handlers (app/api/v1/refunds/...,
 * dispatched via lib/api/compliance.ts) and
 * lib/data/compliance-repository.ts. See
 * tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const CLAIMANT: FixtureUser = { userId: "usr-rf-claimant", externalUserId: "ext-rf-claimant", email: "owner@rf-claimant.test" };
const COLLEAGUE: FixtureUser = { userId: "usr-rf-colleague", externalUserId: "ext-rf-colleague", email: "colleague@rf-claimant.test" };
const REFUND_OFFICER: FixtureUser = { userId: "usr-rf-officer", externalUserId: "ext-rf-officer", email: "officer@rf-test.test" };
// Module 9 Phase C: this fixture's 1,000,000-cent claims are amount-tier HIGH (>=1,000,000), which Phase C's
// enhanced maker-checker lane now requires a genuinely distinct officer at every stage for — a second officer
// is needed wherever a HIGH/CRITICAL-tier claim walks through multiple consecutive approvals.
const REFUND_OFFICER_2: FixtureUser = { userId: "usr-rf-officer-2", externalUserId: "ext-rf-officer-2", email: "officer-2@rf-test.test" };

function actingAs(user: FixtureUser): void {
  __setRequestHeaders({ "oai-authenticated-user-id": user.externalUserId, "oai-authenticated-user-email": user.email });
}

function jsonRequest(url: string, body: unknown, idempotencyKey = crypto.randomUUID()): Request {
  return new Request(url, { method: "POST", headers: { "content-type": "application/json", "idempotency-key": idempotencyKey }, body: JSON.stringify(body) });
}

/** One taxpayer, many VAT return versions (one per test scenario) and their derived refund claims. */
async function seedFixture(): Promise<void> {
  const db = env.DB;
  const now = "2026-08-01T00:00:00.000Z";
  await db.batch([
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-rf-claimant", "VAT-RF-001", "TIN-RF-001", "Refund Claimant Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Refund Street", "finance@rf-claimant.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-rf-claimant", "tp-rf-claimant", "Refund Claimant Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-rf-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(CLAIMANT.userId, CLAIMANT.externalUserId, CLAIMANT.email, "Refund Claimant Owner", "TAXPAYER_OWNER", "tp-rf-claimant", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(COLLEAGUE.userId, COLLEAGUE.externalUserId, COLLEAGUE.email, "Refund Claimant Admin", "TAXPAYER_ADMIN", "tp-rf-claimant", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(REFUND_OFFICER.userId, REFUND_OFFICER.externalUserId, REFUND_OFFICER.email, "NamRA Refund Officer", "NAMRA_REFUND_OFFICER", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(REFUND_OFFICER_2.userId, REFUND_OFFICER_2.externalUserId, REFUND_OFFICER_2.email, "NamRA Refund Officer Two", "NAMRA_REFUND_OFFICER", null, "ACTIVE", now),
    ...[CLAIMANT, COLLEAGUE, REFUND_OFFICER, REFUND_OFFICER_2].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-rf-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
    db.prepare(`INSERT INTO tax_rule_sets (id,jurisdiction,version,effective_from,effective_to,standard_rate_bps,legal_authority_reference,status,approved_by,approved_at,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("trs-rf-2026", "NA", "2026.1", "2026-01-01", null, 1500, "VAT Act", "ACTIVE", null, null, now),
    // Eight VAT periods/return versions — one per test scenario below, each with a negative net position.
    ...["A", "B", "C", "D", "E", "F", "G", "H", "J"].map((suffix, index) =>
      db.prepare(`INSERT INTO vat_periods (id,organisation_id,taxpayer_id,period_code,period_start,period_end,due_date,status,lock_version,close_requested_by,close_requested_at,closed_by,closed_at,created_at,updated_at)
        VALUES (?,?,?,?,?,?,?,?,0,NULL,NULL,NULL,NULL,?,?)`)
        .bind(`vp-rf-${suffix}`, "org-rf-claimant", "tp-rf-claimant", `2026-0${index + 1}`, `2026-0${index + 1}-01`, `2026-0${index + 1}-28`, `2026-0${index + 1}-25`, "FILED", now, now)),
    ...["A", "B", "C", "D", "E", "G", "H", "J"].map((suffix) =>
      db.prepare(`INSERT INTO vat_return_versions (id,vat_period_id,organisation_id,taxpayer_id,version_number,parent_version_id,tax_rule_set_id,output_tax_cents,input_tax_cents,adjustment_cents,net_payable_cents,status,ledger_snapshot_hash,generated_by,generated_at,approved_by,approved_at,superseded_at)
        VALUES (?,?,?,?,1,NULL,?,?,?,0,?,'FILED',?,?,?,?,?,NULL)`)
        .bind(`rv-rf-${suffix}`, `vp-rf-${suffix}`, "org-rf-claimant", "tp-rf-claimant", "trs-rf-2026", 0, 1_000_000, -1_000_000, `hash-rf-${suffix}`, CLAIMANT.userId, now, CLAIMANT.userId, now)),
    // F stays DRAFT (not yet filed) — used to prove RECHECK_ELIGIBILITY genuinely re-checks live filed status.
    db.prepare(`INSERT INTO vat_return_versions (id,vat_period_id,organisation_id,taxpayer_id,version_number,parent_version_id,tax_rule_set_id,output_tax_cents,input_tax_cents,adjustment_cents,net_payable_cents,status,ledger_snapshot_hash,generated_by,generated_at,approved_by,approved_at,superseded_at)
      VALUES (?,?,?,?,1,NULL,?,?,?,0,?,'DRAFT',?,?,?,NULL,NULL,NULL)`)
      .bind("rv-rf-F", "vp-rf-F", "org-rf-claimant", "tp-rf-claimant", "trs-rf-2026", 0, 1_000_000, -1_000_000, "hash-rf-F", CLAIMANT.userId, now),
  ]);
}

async function requestRefundRoute(actor: FixtureUser, versionId: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/refunds/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/refunds", { schema_version: "1.0.0", vat_return_version_id: versionId }));
}

async function transitionRoute(actor: FixtureUser, claimId: string, action: string, findings = "Reviewed the claim against the available evidence."): Promise<Response> {
  const { POST } = await import("@/app/api/v1/refunds/[id]/transition/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/refunds/${claimId}/transition`, { schema_version: "1.0.0", action, findings }), { params: Promise.resolve({ id: claimId }) });
}

async function disputeRoute(actor: FixtureUser, claimId: string, findings = "The rejection does not reflect the evidence already on file."): Promise<Response> {
  const { POST } = await import("@/app/api/v1/refunds/[id]/disputes/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/refunds/${claimId}/disputes`, { schema_version: "1.0.0", action: "DISPUTE", findings }), { params: Promise.resolve({ id: claimId }) });
}

async function claimIdFor(versionId: string): Promise<string> {
  const row = await env.DB.prepare("SELECT id FROM refund_claims WHERE vat_return_version_id=?").bind(versionId).first<{ id: string }>();
  if (!row) throw new Error(`No refund claim found for ${versionId}`);
  return row.id;
}

describe("Module 9 refund claim state machine: RequestRefund, TransitionRefundClaim, DisputeRefund (Phase A)", () => {
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

  it("creates a refund claim in RECEIVED for an already-filed return, not the old flat EVIDENCE_REVIEW status", async () => {
    const response = await requestRefundRoute(CLAIMANT, "rv-rf-A");
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.resource.status).toBe("RECEIVED");
  });

  it("walks the full happy path to PAYMENT_PENDING, computing the debt offset live against tax_obligations", async () => {
    const claimId = await claimIdFor("rv-rf-A");
    // Seed the taxpayer's outstanding statutory debt only now — after the claim was
    // created — to prove the offset is computed live at PAYMENT_AUTHORISATION time,
    // never cached from claim-creation time.
    await env.DB.prepare(`INSERT INTO tax_obligations (id,organisation_id,taxpayer_id,obligation_type,period_code,due_date,amount_cents,currency,status,source_system,source_reference,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)`)
      .bind("ob-rf-1", "org-rf-claimant", "tp-rf-claimant", "VAT_RETURN", "2025-12", "2026-01-25", 300_000, "NAD", "PENDING", "VAT_MSA", null, "2026-08-01T00:00:00.000Z", "2026-08-01T00:00:00.000Z").run();

    // This claim is amount-tier HIGH (1,000,000 cents), so Phase C's enhanced maker-checker lane requires a
    // genuinely distinct officer at every stage, not just the final one — the two officers alternate below.
    expect((await transitionRoute(REFUND_OFFICER, claimId, "APPROVE")).status).toBe(200);
    let claim = await env.DB.prepare("SELECT status FROM refund_claims WHERE id=?").bind(claimId).first<{ status: string }>();
    expect(claim?.status).toBe("RISK_REVIEW");

    expect((await transitionRoute(REFUND_OFFICER_2, claimId, "APPROVE")).status).toBe(200);
    claim = await env.DB.prepare("SELECT status FROM refund_claims WHERE id=?").bind(claimId).first<{ status: string }>();
    expect(claim?.status).toBe("OFFICER_REVIEW");

    expect((await transitionRoute(REFUND_OFFICER, claimId, "APPROVE")).status).toBe(200);
    claim = await env.DB.prepare("SELECT status FROM refund_claims WHERE id=?").bind(claimId).first<{ status: string }>();
    expect(claim?.status).toBe("PAYMENT_AUTHORISATION");

    const final = await transitionRoute(REFUND_OFFICER_2, claimId, "APPROVE");
    expect(final.status).toBe(200);
    const finalBody = await final.json();
    expect(finalBody.resource.status).toBe("PAYMENT_PENDING");
    expect(finalBody.resource.offset_amount_cents).toBe(300_000);
    expect(finalBody.resource.net_payable_cents).toBe(700_000);
    expect(finalBody.resource.approved_by).toBe(REFUND_OFFICER_2.userId);

    // Deliberately never wrote a payment instruction — Module 9 Phase D's job.
    const paymentRow = await env.DB.prepare("SELECT COUNT(*) AS n FROM payment_instructions WHERE refund_claim_id=?").bind(claimId).first<{ n: number }>();
    expect(Number(paymentRow?.n ?? 0)).toBe(0);
  });

  it("rejects an out-of-order transition on an already-terminal claim", async () => {
    const claimId = await claimIdFor("rv-rf-A");
    const response = await transitionRoute(REFUND_OFFICER, claimId, "APPROVE");
    expect(response.status).toBe(422);
    const body = await response.json();
    expect(body.code).toBe("VALIDATION_FAILED");
  });

  it("routes REJECT -> DISPUTE -> RESOLVE_DISPUTE_OVERTURN back into RISK_REVIEW", async () => {
    await requestRefundRoute(CLAIMANT, "rv-rf-B");
    const claimId = await claimIdFor("rv-rf-B");
    expect((await transitionRoute(REFUND_OFFICER, claimId, "REJECT")).status).toBe(200);
    expect((await disputeRoute(CLAIMANT, claimId)).status).toBe(200);
    let claim = await env.DB.prepare("SELECT status FROM refund_claims WHERE id=?").bind(claimId).first<{ status: string; dispute_reason: string | null }>();
    expect(claim?.status).toBe("DISPUTED");
    const overturned = await transitionRoute(REFUND_OFFICER, claimId, "RESOLVE_DISPUTE_OVERTURN");
    expect(overturned.status).toBe(200);
    claim = await env.DB.prepare("SELECT status FROM refund_claims WHERE id=?").bind(claimId).first<{ status: string; dispute_reason: string | null }>();
    expect(claim?.status).toBe("RISK_REVIEW");
  });

  it("routes REJECT -> DISPUTE -> RESOLVE_DISPUTE_UPHOLD to a genuinely terminal CLOSED", async () => {
    await requestRefundRoute(CLAIMANT, "rv-rf-C");
    const claimId = await claimIdFor("rv-rf-C");
    expect((await transitionRoute(REFUND_OFFICER, claimId, "REJECT")).status).toBe(200);
    expect((await disputeRoute(CLAIMANT, claimId)).status).toBe(200);
    const upheld = await transitionRoute(REFUND_OFFICER, claimId, "RESOLVE_DISPUTE_UPHOLD");
    expect(upheld.status).toBe(200);
    const claim = await env.DB.prepare("SELECT status FROM refund_claims WHERE id=?").bind(claimId).first<{ status: string }>();
    expect(claim?.status).toBe("CLOSED");
  });

  it("only the original requester may dispute a rejected claim", async () => {
    await requestRefundRoute(CLAIMANT, "rv-rf-J");
    const claimId = await claimIdFor("rv-rf-J");
    expect((await transitionRoute(REFUND_OFFICER, claimId, "REJECT")).status).toBe(200);
    const response = await disputeRoute(COLLEAGUE, claimId);
    expect(response.status).toBe(403);
  });

  it("pauses on HOLD and resumes dynamically back into RECEIVED", async () => {
    await requestRefundRoute(CLAIMANT, "rv-rf-D");
    const claimId = await claimIdFor("rv-rf-D");
    expect((await transitionRoute(REFUND_OFFICER, claimId, "HOLD")).status).toBe(200);
    let claim = await env.DB.prepare("SELECT status,resume_status FROM refund_claims WHERE id=?").bind(claimId).first<{ status: string; resume_status: string | null }>();
    expect(claim?.status).toBe("ON_HOLD");
    expect(claim?.resume_status).toBe("RECEIVED");
    expect((await transitionRoute(REFUND_OFFICER, claimId, "RESUME")).status).toBe(200);
    claim = await env.DB.prepare("SELECT status,resume_status FROM refund_claims WHERE id=?").bind(claimId).first<{ status: string; resume_status: string | null }>();
    expect(claim?.status).toBe("RECEIVED");
    expect(claim?.resume_status).toBeNull();
  });

  it("pauses on REQUEST_INFORMATION from a deeper stage and resumes back into that same stage", async () => {
    await requestRefundRoute(CLAIMANT, "rv-rf-E");
    const claimId = await claimIdFor("rv-rf-E");
    expect((await transitionRoute(REFUND_OFFICER, claimId, "APPROVE")).status).toBe(200);
    expect((await transitionRoute(REFUND_OFFICER, claimId, "REQUEST_INFORMATION")).status).toBe(200);
    let claim = await env.DB.prepare("SELECT status FROM refund_claims WHERE id=?").bind(claimId).first<{ status: string }>();
    expect(claim?.status).toBe("EVIDENCE_REQUESTED");
    expect((await transitionRoute(REFUND_OFFICER, claimId, "RESUME")).status).toBe(200);
    claim = await env.DB.prepare("SELECT status FROM refund_claims WHERE id=?").bind(claimId).first<{ status: string }>();
    expect(claim?.status).toBe("RISK_REVIEW");
  });

  it("blocks a claim whose return isn't filed yet, and only recovers once the return is genuinely re-checked as FILED", async () => {
    const blocked = await requestRefundRoute(CLAIMANT, "rv-rf-F");
    expect(blocked.status).toBe(201);
    const claimId = await claimIdFor("rv-rf-F");
    let claim = await env.DB.prepare("SELECT status FROM refund_claims WHERE id=?").bind(claimId).first<{ status: string }>();
    expect(claim?.status).toBe("BLOCKED_RETURN_NOT_FILED");

    const stillDraft = await transitionRoute(REFUND_OFFICER, claimId, "RECHECK_ELIGIBILITY");
    expect(stillDraft.status).toBe(409);

    await env.DB.prepare("UPDATE vat_return_versions SET status='FILED' WHERE id=?").bind("rv-rf-F").run();
    const recovered = await transitionRoute(REFUND_OFFICER, claimId, "RECHECK_ELIGIBILITY");
    expect(recovered.status).toBe(200);
    claim = await env.DB.prepare("SELECT status FROM refund_claims WHERE id=?").bind(claimId).first<{ status: string }>();
    expect(claim?.status).toBe("RECEIVED");
  });

  it("denies maker-checker self-review even before checking the transition's structural validity", async () => {
    const now = "2026-08-01T00:00:00.000Z";
    await env.DB.prepare(`INSERT INTO refund_claims
      (id,claim_number,organisation_id,taxpayer_id,vat_return_version_id,amount_cents,currency,status,evidence_status,risk_tier,requested_by,requested_at,approved_by,approved_at,payment_instruction_id,resume_status,offset_amount_cents,net_payable_cents,dispute_reason)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NULL,NULL,NULL,NULL,0,NULL,NULL)`)
      .bind("claim-rf-self", "RFD-RF-SELF", "org-rf-claimant", "tp-rf-claimant", "rv-rf-G", 1_000_000, "NAD", "RECEIVED", "PENDING_REVIEW", "HIGH", REFUND_OFFICER.userId, now).run();
    const response = await transitionRoute(REFUND_OFFICER, "claim-rf-self", "APPROVE");
    expect(response.status).toBe(403);
  });

  it("denies a taxpayer-scoped actor without refunds:review from transitioning a claim", async () => {
    await requestRefundRoute(CLAIMANT, "rv-rf-H");
    const claimId = await claimIdFor("rv-rf-H");
    const response = await transitionRoute(CLAIMANT, claimId, "APPROVE");
    expect(response.status).toBe(403);
  });
});
