import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 9 Phase B: RequestRefund previously derived a claim purely from
 * the live vat_return_versions row at the moment of the call — nothing
 * about the return, its invoices or the taxpayer's reconciliation state
 * was ever pinned down, so a later correction to the same return (or a
 * later mutation of its invoices) would silently change what a claim's
 * "evidence" appeared to be, with no way to prove what was actually true
 * when the claim was filed. This suite proves the real fix: a
 * claim_snapshot/claim_snapshot_hash captured once at claim time and never
 * touched again by any later transition, plus a persisted, explainable
 * pass/fail check battery (refund_claim_checks) — formalising the
 * eligibility/duplicate gates requestRefund already enforced, adding two
 * genuinely new advisory checks (a live debt-offset preview and a refund
 * claim frequency anomaly signal), and explicitly declaring identity/bank/
 * sanctions screening NOT_CONFIGURED rather than fabricating a pass, since
 * no such provider exists anywhere in this codebase. Proven through the
 * real route handlers (app/api/v1/refunds/..., dispatched via
 * lib/api/compliance.ts) and lib/data/compliance-repository.ts. See
 * tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const CLAIMANT: FixtureUser = { userId: "usr-rf2-claimant", externalUserId: "ext-rf2-claimant", email: "owner@rf2-claimant.test" };
const OTHER_TAXPAYER: FixtureUser = { userId: "usr-rf2-other", externalUserId: "ext-rf2-other", email: "owner@rf2-other.test" };
const FREQUENT_CLAIMANT: FixtureUser = { userId: "usr-rf2-frequent", externalUserId: "ext-rf2-frequent", email: "owner@rf2-frequent.test" };
const REFUND_OFFICER: FixtureUser = { userId: "usr-rf2-officer", externalUserId: "ext-rf2-officer", email: "officer@rf2-test.test" };

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
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-rf2-claimant", "VAT-RF2-001", "TIN-RF2-001", "Freeze Test Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Freeze Street", "finance@rf2-claimant.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-rf2-claimant", "tp-rf2-claimant", "Freeze Test Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-rf2-other", "VAT-RF2-002", "TIN-RF2-002", "Other Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "2 Freeze Street", "finance@rf2-other.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-rf2-other", "tp-rf2-other", "Other Co (Pty) Ltd", null, "ACTIVE", now, now),
    // A separate taxpayer purely for the ANOMALY_CLAIM_FREQUENCY test, so its claim count starts clean
    // rather than inheriting tp-rf2-claimant's other fixture claims.
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-rf2-frequent", "VAT-RF2-003", "TIN-RF2-003", "Frequent Filer Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "3 Freeze Street", "finance@rf2-frequent.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-rf2-frequent", "tp-rf2-frequent", "Frequent Filer Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-rf2-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(CLAIMANT.userId, CLAIMANT.externalUserId, CLAIMANT.email, "Freeze Test Owner", "TAXPAYER_OWNER", "tp-rf2-claimant", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OTHER_TAXPAYER.userId, OTHER_TAXPAYER.externalUserId, OTHER_TAXPAYER.email, "Other Owner", "TAXPAYER_OWNER", "tp-rf2-other", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(FREQUENT_CLAIMANT.userId, FREQUENT_CLAIMANT.externalUserId, FREQUENT_CLAIMANT.email, "Frequent Filer Owner", "TAXPAYER_OWNER", "tp-rf2-frequent", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(REFUND_OFFICER.userId, REFUND_OFFICER.externalUserId, REFUND_OFFICER.email, "NamRA Refund Officer", "NAMRA_REFUND_OFFICER", null, "ACTIVE", now),
    ...[CLAIMANT, OTHER_TAXPAYER, FREQUENT_CLAIMANT, REFUND_OFFICER].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-rf2-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
    db.prepare(`INSERT INTO tax_rule_sets (id,jurisdiction,version,effective_from,effective_to,standard_rate_bps,legal_authority_reference,status,approved_by,approved_at,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("trs-rf2-2026", "NA", "2026.1", "2026-01-01", null, 1500, "VAT Act", "ACTIVE", null, null, now),
    // Two VAT periods/return versions for tp-rf2-claimant: G is the dedicated freeze/immutability claim,
    // F stays DRAFT for ELIGIBILITY_RETURN_FILED.
    ...["F", "G"].map((suffix, index) =>
      db.prepare(`INSERT INTO vat_periods (id,organisation_id,taxpayer_id,period_code,period_start,period_end,due_date,status,lock_version,close_requested_by,close_requested_at,closed_by,closed_at,created_at,updated_at)
        VALUES (?,?,?,?,?,?,?,?,0,NULL,NULL,NULL,NULL,?,?)`)
        .bind(`vp-rf2-${suffix}`, "org-rf2-claimant", "tp-rf2-claimant", `2026-0${index + 4}`, `2026-0${index + 4}-01`, `2026-0${index + 4}-28`, `2026-0${index + 4}-25`, "FILED", now, now)),
    db.prepare(`INSERT INTO vat_return_versions (id,vat_period_id,organisation_id,taxpayer_id,version_number,parent_version_id,tax_rule_set_id,output_tax_cents,input_tax_cents,adjustment_cents,net_payable_cents,status,ledger_snapshot_hash,generated_by,generated_at,approved_by,approved_at,superseded_at)
      VALUES (?,?,?,?,1,NULL,?,?,?,0,?,'FILED',?,?,?,?,?,NULL)`)
      .bind("rv-rf2-G", "vp-rf2-G", "org-rf2-claimant", "tp-rf2-claimant", "trs-rf2-2026", 0, 500_000, -500_000, "hash-rf2-G", CLAIMANT.userId, now, CLAIMANT.userId, now),
    db.prepare(`INSERT INTO vat_return_versions (id,vat_period_id,organisation_id,taxpayer_id,version_number,parent_version_id,tax_rule_set_id,output_tax_cents,input_tax_cents,adjustment_cents,net_payable_cents,status,ledger_snapshot_hash,generated_by,generated_at,approved_by,approved_at,superseded_at)
      VALUES (?,?,?,?,1,NULL,?,?,?,0,?,'DRAFT',?,?,?,NULL,NULL,NULL)`)
      .bind("rv-rf2-F", "vp-rf2-F", "org-rf2-claimant", "tp-rf2-claimant", "trs-rf2-2026", 0, 500_000, -500_000, "hash-rf2-F", CLAIMANT.userId, now),
    // Three VAT periods/return versions for tp-rf2-frequent, all FILED with a negative net position,
    // purely to drive the ANOMALY_CLAIM_FREQUENCY test.
    ...["1", "2", "3"].map((suffix, index) =>
      db.prepare(`INSERT INTO vat_periods (id,organisation_id,taxpayer_id,period_code,period_start,period_end,due_date,status,lock_version,close_requested_by,close_requested_at,closed_by,closed_at,created_at,updated_at)
        VALUES (?,?,?,?,?,?,?,?,0,NULL,NULL,NULL,NULL,?,?)`)
        .bind(`vp-rf2-freq${suffix}`, "org-rf2-frequent", "tp-rf2-frequent", `2026-0${index + 1}`, `2026-0${index + 1}-01`, `2026-0${index + 1}-28`, `2026-0${index + 1}-25`, "FILED", now, now)),
    ...["1", "2", "3"].map((suffix) =>
      db.prepare(`INSERT INTO vat_return_versions (id,vat_period_id,organisation_id,taxpayer_id,version_number,parent_version_id,tax_rule_set_id,output_tax_cents,input_tax_cents,adjustment_cents,net_payable_cents,status,ledger_snapshot_hash,generated_by,generated_at,approved_by,approved_at,superseded_at)
        VALUES (?,?,?,?,1,NULL,?,?,?,0,?,'FILED',?,?,?,?,?,NULL)`)
        .bind(`rv-rf2-freq${suffix}`, `vp-rf2-freq${suffix}`, "org-rf2-frequent", "tp-rf2-frequent", "trs-rf2-2026", 0, 500_000, -500_000, `hash-rf2-freq${suffix}`, FREQUENT_CLAIMANT.userId, now, FREQUENT_CLAIMANT.userId, now)),
    // One PENDING statutory obligation, for the DEBT_OFFSET_PREVIEW rationale.
    db.prepare(`INSERT INTO tax_obligations (id,organisation_id,taxpayer_id,obligation_type,period_code,due_date,amount_cents,currency,status,source_system,source_reference,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)`)
      .bind("ob-rf2-1", "org-rf2-claimant", "tp-rf2-claimant", "VAT_RETURN", "2025-12", "2026-01-25", 250_000, "NAD", "PENDING", "VAT_MSA", null, now, now),
    // Two invoices and one OPEN reconciliation exception in claim G's own period (2026-05), for the
    // invoiceEvidence/reconciliation freeze test.
    db.prepare(`INSERT INTO invoices
      (id,invoice_number,document_type,source_system,source_document_id,supplier_taxpayer_id,supplier_name,supplier_vat_number,customer_taxpayer_id,customer_name,customer_vat_number,issue_date,currency,line_net_cents,tax_cents,total_cents,status,risk_level,payload_hash,transaction_id,certificate_id,verification_token,created_at,certified_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`)
      .bind("inv-rf2-1", "INV-RF2-0001", "TAX_INVOICE", "PILOT", "doc-rf2-1", "tp-rf2-claimant", "Freeze Test Co (Pty) Ltd", "VAT-RF2-001", null, "Cash sale", null, "2026-05-05", "NAD", 100_000, 15_000, 115_000, "CERTIFIED", "LOW", "hash-inv-rf2-1", "txn-rf2-1", "cert-rf2-1", "verify-rf2-1", now, now),
    db.prepare(`INSERT INTO reconciliation_exceptions VALUES (?,?,?,?,?,?,?,?,NULL,NULL,NULL,NULL)`)
      .bind("exc-rf2-1", "inv-rf2-1", "tp-rf2-claimant", "LEDGER_MISMATCH", "HIGH", "OPEN", "Ledger mismatch detected.", now),
  ]);
}

async function requestRefundRoute(actor: FixtureUser, versionId: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/refunds/route");
  actingAs(actor);
  return POST(jsonRequest("https://vat-msa.local/api/v1/refunds", { schema_version: "1.0.0", vat_return_version_id: versionId }));
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

type CheckResult = { check_code: string; status: string; rationale: string };

describe("Module 9 refund claim freeze & integrity checks: claim_snapshot, refund_claim_checks, GetRefundClaimChecks (Phase B)", () => {
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

  it("freezes a claim_snapshot at claim time, and the frozen snapshot never changes even after the source return/invoices/reconciliation state is mutated", async () => {
    const created = await requestRefundRoute(CLAIMANT, "rv-rf2-G");
    expect(created.status).toBe(201);
    const claimId = await claimIdFor("rv-rf2-G");

    const before = await checksRoute(REFUND_OFFICER, claimId);
    expect(before.status).toBe(200);
    const beforeBody = await before.json();
    const beforeSnapshot = JSON.parse(beforeBody.claim.claimSnapshot);
    expect(beforeSnapshot.returnVersion.netPayableCents).toBe(-500_000);
    expect(beforeSnapshot.invoiceEvidence.count).toBe(1);
    expect(beforeSnapshot.reconciliation).toEqual({ openExceptions: 1, totalExceptions: 1 });
    const beforeHash = beforeBody.claim.claimSnapshotHash;
    expect(beforeHash).toBeTruthy();

    // Mutate the source data after the claim was frozen: correct the return's net position,
    // add a new certified invoice in the same period, and open a second reconciliation exception.
    await env.DB.prepare("UPDATE vat_return_versions SET net_payable_cents=-999999 WHERE id=?").bind("rv-rf2-G").run();
    await env.DB.prepare(`INSERT INTO invoices
      (id,invoice_number,document_type,source_system,source_document_id,supplier_taxpayer_id,supplier_name,supplier_vat_number,customer_taxpayer_id,customer_name,customer_vat_number,issue_date,currency,line_net_cents,tax_cents,total_cents,status,risk_level,payload_hash,transaction_id,certificate_id,verification_token,created_at,certified_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`)
      .bind("inv-rf2-2", "INV-RF2-0002", "TAX_INVOICE", "PILOT", "doc-rf2-2", "tp-rf2-claimant", "Freeze Test Co (Pty) Ltd", "VAT-RF2-001", null, "Cash sale", null, "2026-05-12", "NAD", 50_000, 7_500, 57_500, "CERTIFIED", "LOW", "hash-inv-rf2-2", "txn-rf2-2", "cert-rf2-2", "verify-rf2-2", "2026-08-15T00:00:00.000Z", "2026-08-15T00:00:00.000Z").run();
    await env.DB.prepare(`INSERT INTO reconciliation_exceptions VALUES (?,?,?,?,?,?,?,?,NULL,NULL,NULL,NULL)`)
      .bind("exc-rf2-2", "inv-rf2-1", "tp-rf2-claimant", "LEDGER_MISMATCH", "MEDIUM", "OPEN", "A second, later exception.", "2026-08-15T00:00:00.000Z").run();

    const after = await checksRoute(REFUND_OFFICER, claimId);
    const afterBody = await after.json();
    const afterSnapshot = JSON.parse(afterBody.claim.claimSnapshot);
    expect(afterSnapshot.returnVersion.netPayableCents).toBe(-500_000);
    expect(afterSnapshot.invoiceEvidence.count).toBe(1);
    expect(afterSnapshot.reconciliation).toEqual({ openExceptions: 1, totalExceptions: 1 });
    expect(afterBody.claim.claimSnapshotHash).toBe(beforeHash);
  });

  it("persists an explainable pass/fail check battery, formalising eligibility gates and adding a live debt-offset preview", async () => {
    const claimId = await claimIdFor("rv-rf2-G");
    const response = await checksRoute(REFUND_OFFICER, claimId);
    const body = await response.json();
    const checks: CheckResult[] = body.checks;
    const byCode = Object.fromEntries(checks.map((c) => [c.check_code, c]));

    expect(byCode.ELIGIBILITY_NEGATIVE_NET_POSITION.status).toBe("PASS");
    expect(byCode.ELIGIBILITY_RETURN_FILED.status).toBe("PASS");
    expect(byCode.DUPLICATE_CLAIM.status).toBe("PASS");
    expect(byCode.DEBT_OFFSET_PREVIEW.status).toBe("PASS");
    expect(byCode.DEBT_OFFSET_PREVIEW.rationale).toContain("250000 cents");
    expect(byCode.IDENTITY_VERIFICATION.status).toBe("NOT_CONFIGURED");
    expect(byCode.BANK_ACCOUNT_OWNERSHIP.status).toBe("NOT_CONFIGURED");
    expect(byCode.SANCTIONS_SCREENING.status).toBe("NOT_CONFIGURED");
  });

  it("marks ELIGIBILITY_RETURN_FILED as FAIL, without fabricating a PASS, for a claim blocked on an unfiled return", async () => {
    const blocked = await requestRefundRoute(CLAIMANT, "rv-rf2-F");
    expect(blocked.status).toBe(201);
    const claimId = await claimIdFor("rv-rf2-F");
    const response = await checksRoute(REFUND_OFFICER, claimId);
    const body = await response.json();
    const checks: CheckResult[] = body.checks;
    const byCode = Object.fromEntries(checks.map((c) => [c.check_code, c]));
    expect(byCode.ELIGIBILITY_RETURN_FILED.status).toBe("FAIL");
    expect(byCode.ELIGIBILITY_NEGATIVE_NET_POSITION.status).toBe("PASS");
  });

  it("flags ANOMALY_CLAIM_FREQUENCY once a taxpayer's third claim lands inside the trailing 90 days, without blocking claim creation", async () => {
    const first = await requestRefundRoute(FREQUENT_CLAIMANT, "rv-rf2-freq1");
    const second = await requestRefundRoute(FREQUENT_CLAIMANT, "rv-rf2-freq2");
    const third = await requestRefundRoute(FREQUENT_CLAIMANT, "rv-rf2-freq3");
    expect(first.status).toBe(201);
    expect(second.status).toBe(201);
    expect(third.status).toBe(201);

    const firstChecks = (await (await checksRoute(REFUND_OFFICER, await claimIdFor("rv-rf2-freq1"))).json()).checks as CheckResult[];
    expect(firstChecks.find((c) => c.check_code === "ANOMALY_CLAIM_FREQUENCY")?.status).toBe("PASS");

    const thirdChecks = (await (await checksRoute(REFUND_OFFICER, await claimIdFor("rv-rf2-freq3"))).json()).checks as CheckResult[];
    expect(thirdChecks.find((c) => c.check_code === "ANOMALY_CLAIM_FREQUENCY")?.status).toBe("FAIL");
  });

  it("denies GetRefundClaimChecks to an actor outside the claim's taxpayer scope, and allows the claim's own taxpayer", async () => {
    const claimId = await claimIdFor("rv-rf2-G");
    const denied = await checksRoute(OTHER_TAXPAYER, claimId);
    expect(denied.status).toBe(403);
    const allowed = await checksRoute(CLAIMANT, claimId);
    expect(allowed.status).toBe(200);
  });

  it("returns 404 for a refund claim that does not exist", async () => {
    const response = await checksRoute(REFUND_OFFICER, crypto.randomUUID());
    expect(response.status).toBe(404);
  });
});
