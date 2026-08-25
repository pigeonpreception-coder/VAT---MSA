import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";

/**
 * Module 4 Phase D: the evidence sub-model, proven through the real route
 * handlers (app/api/v1/audit-cases/[id]/evidence, .../notes and
 * app/api/v1/audit-evidence/[id]/custody-events, all dispatched via
 * lib/api/compliance.ts) and lib/data/compliance-repository.ts's
 * addEvidence/recordEvidenceCustodyEvent/getCaseEvidence/addCaseNote/
 * getCaseNotes. See tests/routes/module-1-access-control.test.ts for why
 * this needs the cloudflare:workers/next/headers fakes and the fake D1.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const TAXPAYER_OWNER: FixtureUser = { userId: "usr-ev-taxpayer-owner", externalUserId: "ext-ev-taxpayer-owner", email: "owner@ev-taxpayer.test" };
const OTHER_TAXPAYER: FixtureUser = { userId: "usr-ev-other-taxpayer", externalUserId: "ext-ev-other-taxpayer", email: "owner@ev-other.test" };
const NAMRA_OFFICER: FixtureUser = { userId: "usr-ev-namra", externalUserId: "ext-ev-namra", email: "namra@ev.test" };
const NAMRA_SUPERVISOR: FixtureUser = { userId: "usr-ev-supervisor", externalUserId: "ext-ev-supervisor", email: "supervisor@ev.test" };
const NAMRA_AUDITOR: FixtureUser = { userId: "usr-ev-auditor", externalUserId: "ext-ev-auditor", email: "auditor@ev.test" };

const NATIONAL_ACTOR_POOL: readonly FixtureUser[] = [NAMRA_OFFICER, NAMRA_SUPERVISOR, NAMRA_AUDITOR];
let nationalActorIndex = 0;
function nextNationalActor(): FixtureUser {
  const actor = NATIONAL_ACTOR_POOL[nationalActorIndex % NATIONAL_ACTOR_POOL.length];
  nationalActorIndex += 1;
  return actor;
}

function actingAs(user: FixtureUser): void {
  __setRequestHeaders({ "oai-authenticated-user-id": user.externalUserId, "oai-authenticated-user-email": user.email });
}

function jsonRequest(url: string, body: unknown, idempotencyKey: string): Request {
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
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-ev-taxpayer", "VAT-EV-001", "TIN-EV-001", "Evidence Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Evidence Street", "finance@ev-taxpayer.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-ev-taxpayer", "tp-ev-taxpayer", "Evidence Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-ev-other", "VAT-EV-002", "TIN-EV-002", "Other Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "2 Evidence Street", "finance@ev-other.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-ev-other", "tp-ev-other", "Other Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-ev-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(TAXPAYER_OWNER.userId, TAXPAYER_OWNER.externalUserId, TAXPAYER_OWNER.email, "Taxpayer Owner", "TAXPAYER_OWNER", "tp-ev-taxpayer", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OTHER_TAXPAYER.userId, OTHER_TAXPAYER.externalUserId, OTHER_TAXPAYER.email, "Other Taxpayer", "TAXPAYER_OWNER", "tp-ev-other", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_OFFICER.userId, NAMRA_OFFICER.externalUserId, NAMRA_OFFICER.email, "NamRA Officer", "NAMRA_COMPLIANCE_OFFICER", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_SUPERVISOR.userId, NAMRA_SUPERVISOR.externalUserId, NAMRA_SUPERVISOR.email, "NamRA Supervisor", "NAMRA_SUPERVISOR", null, "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_AUDITOR.userId, NAMRA_AUDITOR.externalUserId, NAMRA_AUDITOR.email, "NamRA Auditor", "NAMRA_AUDITOR", null, "ACTIVE", now),
    ...[TAXPAYER_OWNER, OTHER_TAXPAYER, NAMRA_OFFICER, NAMRA_SUPERVISOR, NAMRA_AUDITOR].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-ev-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
    // A certified invoice with a real payload_hash to cite as evidence.
    db.prepare(`INSERT INTO invoices
      (id,invoice_number,document_type,source_system,source_document_id,supplier_taxpayer_id,supplier_name,supplier_vat_number,customer_taxpayer_id,customer_name,customer_vat_number,issue_date,currency,line_net_cents,tax_cents,total_cents,status,risk_level,payload_hash,transaction_id,certificate_id,verification_token,created_at,certified_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`)
      .bind("inv-ev-1", "INV-EV-0001", "TAX_INVOICE", "PILOT", "doc-ev-1", "tp-ev-taxpayer", "Evidence Co (Pty) Ltd", "VAT-EV-001", null, "Cash sale", null, "2026-07-01", "NAD", 10_000_00, 1_500_00, 11_500_00, "MATCHED", "LOW", "hash-ev-1-abc123", "txn-ev-1", "cert-ev-1", "verify-ev-1", now, now),
    // A second certified invoice, used only by the VERIFY custody-event test so it doesn't collide with the first invoice's active PRESERVED citation.
    db.prepare(`INSERT INTO invoices
      (id,invoice_number,document_type,source_system,source_document_id,supplier_taxpayer_id,supplier_name,supplier_vat_number,customer_taxpayer_id,customer_name,customer_vat_number,issue_date,currency,line_net_cents,tax_cents,total_cents,status,risk_level,payload_hash,transaction_id,certificate_id,verification_token,created_at,certified_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`)
      .bind("inv-ev-2", "INV-EV-0002", "TAX_INVOICE", "PILOT", "doc-ev-2", "tp-ev-taxpayer", "Evidence Co (Pty) Ltd", "VAT-EV-001", null, "Cash sale", null, "2026-07-02", "NAD", 5_000_00, 750_00, 5_750_00, "MATCHED", "LOW", "hash-ev-2-def456", "txn-ev-2", "cert-ev-2", "verify-ev-2", now, now),
    // A clean-scanned document already uploaded (Module 22 pipeline).
    db.prepare(`INSERT INTO document_metadata
      (id,organisation_id,owner_domain,owner_resource_id,object_key,file_name,content_type,size_bytes,checksum_sha256,classification,scan_status,status,uploaded_by,uploaded_at,retained_until,legal_hold)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,0)`)
      .bind("doc-ev-clean", "org-ev-taxpayer", "AUDIT_CASE", "case-ev-placeholder", "quarantine/org-ev-taxpayer/doc-ev-clean/statement.pdf", "statement.pdf", "application/pdf", 2048, "b".repeat(64), "TAX_CONFIDENTIAL", "CLEAN", "AVAILABLE", NAMRA_OFFICER.userId, now),
    // A still-quarantined document (should be refused as evidence).
    db.prepare(`INSERT INTO document_metadata
      (id,organisation_id,owner_domain,owner_resource_id,object_key,file_name,content_type,size_bytes,checksum_sha256,classification,scan_status,status,uploaded_by,uploaded_at,retained_until,legal_hold)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,0)`)
      .bind("doc-ev-quarantined", "org-ev-taxpayer", "AUDIT_CASE", "case-ev-placeholder", "quarantine/org-ev-taxpayer/doc-ev-quarantined/statement.pdf", "unscanned.pdf", "application/pdf", 1024, "c".repeat(64), "TAX_CONFIDENTIAL", "PENDING_EXTERNAL_SCANNER", "QUARANTINED", NAMRA_OFFICER.userId, now),
  ]);
}

async function openCase(title: string): Promise<string> {
  const { POST } = await import("@/app/api/v1/audit-cases/route");
  actingAs(nextNationalActor());
  const response = await POST(jsonRequest("https://vat-msa.local/api/v1/audit-cases", {
    schema_version: "1.0.0", taxpayer_id: "tp-ev-taxpayer", case_type: "VAT_AUDIT", title,
    opening_reason: "Matched evidence fell below the controlled review threshold for the period.", risk_tier: "HIGH",
  }, crypto.randomUUID()));
  expect(response.status).toBe(201);
  const body = await response.json();
  return body.resource.id as string;
}

async function addEvidence(caseId: string, body: Record<string, unknown>, actor: FixtureUser = nextNationalActor(), key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/audit-cases/[id]/evidence/route");
  actingAs(actor);
  return POST(
    jsonRequest(`https://vat-msa.local/api/v1/audit-cases/${caseId}/evidence`, { schema_version: "1.0.0", ...body }, key),
    { params: Promise.resolve({ id: caseId }) },
  );
}

async function custodyEvent(evidenceId: string, body: Record<string, unknown>, actor: FixtureUser = nextNationalActor(), key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/audit-evidence/[id]/custody-events/route");
  actingAs(actor);
  return POST(
    jsonRequest(`https://vat-msa.local/api/v1/audit-evidence/${evidenceId}/custody-events`, { schema_version: "1.0.0", ...body }, key),
    { params: Promise.resolve({ id: evidenceId }) },
  );
}

async function addNote(caseId: string, body: Record<string, unknown>, actor: FixtureUser = nextNationalActor(), key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/audit-cases/[id]/notes/route");
  actingAs(actor);
  return POST(
    jsonRequest(`https://vat-msa.local/api/v1/audit-cases/${caseId}/notes`, { schema_version: "1.0.0", ...body }, key),
    { params: Promise.resolve({ id: caseId }) },
  );
}

describe("Module 4 evidence sub-model (Phase D)", () => {
  let caseId: string;

  beforeAll(async () => {
    vi.stubEnv("NODE_ENV", "production");
    env.DB = createFakeD1();
    const { ensureDatabase } = await import("@/db/runtime");
    await ensureDatabase();
    await seedFixture();
    caseId = await openCase("Evidence sub-model test case");
  });

  afterAll(() => {
    vi.unstubAllEnvs();
  });

  it("cites a canonical invoice as evidence, deriving its checksum from the invoice's own payload_hash", async () => {
    const response = await addEvidence(caseId, { source_resource_type: "INVOICE", source_resource_id: "inv-ev-1", description: "The certified invoice underpinning the referral." });
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.resource.status).toBe("PRESERVED");
    expect(body.resource.checksum_sha256).toBe("hash-ev-1-abc123");
    expect(body.resource.evidence_type).toBe("CERTIFIED_RECORD");
  });

  it("cites an already-uploaded, clean-scanned document as evidence", async () => {
    const response = await addEvidence(caseId, { source_resource_type: "DOCUMENT", source_resource_id: "doc-ev-clean", description: "A bank statement uploaded and scanned clean." });
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.resource.document_id).toBe("doc-ev-clean");
    expect(body.resource.checksum_sha256).toBe("b".repeat(64));
    expect(body.resource.evidence_type).toBe("UPLOADED_DOCUMENT");
  });

  it("refuses to cite a still-quarantined document as evidence", async () => {
    const response = await addEvidence(caseId, { source_resource_type: "DOCUMENT", source_resource_id: "doc-ev-quarantined", description: "An unscanned document that should be refused." });
    expect(response.status).toBe(409);
  });

  it("accepts an externally supplied OTHER record with an officer-provided checksum", async () => {
    const response = await addEvidence(caseId, { source_resource_type: "OTHER", source_resource_id: "witness-statement-1", description: "A signed witness statement collected during the site visit.", checksum_sha256: "d".repeat(64) });
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.resource.checksum_sha256).toBe("d".repeat(64));
    expect(body.resource.evidence_type).toBe("EXTERNAL_RECORD");
  });

  it("returns 404 for a citation of a non-existent invoice", async () => {
    const response = await addEvidence(caseId, { source_resource_type: "INVOICE", source_resource_id: crypto.randomUUID(), description: "A citation of an invoice that does not exist." });
    expect(response.status).toBe(404);
  });

  it("enforces only one PRESERVED evidence row per (case, source resource): a duplicate citation without supersession is rejected", async () => {
    const first = await addEvidence(caseId, { source_resource_type: "OTHER", source_resource_id: "duplicate-source-1", description: "First citation of this external source.", checksum_sha256: "e".repeat(64) });
    expect(first.status).toBe(201);
    const duplicate = await addEvidence(caseId, { source_resource_type: "OTHER", source_resource_id: "duplicate-source-1", description: "A second, uncorrected citation of the same source.", checksum_sha256: "e".repeat(64) });
    expect(duplicate.status).toBe(409);
  });

  it("supersedes a prior evidence row via immutable versioning: the old row flips to SUPERSEDED, the new one is PRESERVED", async () => {
    const first = await addEvidence(caseId, { source_resource_type: "OTHER", source_resource_id: "versioned-source-1", description: "Initial citation, later found to have a transcription error.", checksum_sha256: "f".repeat(64) });
    const firstBody = await first.json();
    const firstId = firstBody.resource.id as string;

    const second = await addEvidence(caseId, { source_resource_type: "OTHER", source_resource_id: "versioned-source-1", description: "Corrected citation with the transcription error fixed.", checksum_sha256: "1".repeat(64), supersedes_evidence_id: firstId });
    expect(second.status).toBe(201);
    const secondBody = await second.json();
    expect(secondBody.resource.status).toBe("PRESERVED");
    expect(secondBody.resource.previous_version_id).toBe(firstId);

    const { GET } = await import("@/app/api/v1/audit-cases/[id]/evidence/route");
    actingAs(NAMRA_SUPERVISOR);
    const registerResponse = await GET(new Request(`https://vat-msa.local/api/v1/audit-cases/${caseId}/evidence`), { params: Promise.resolve({ id: caseId }) });
    const register = await registerResponse.json();
    const firstRow = register.evidence.find((row: { id: string }) => row.id === firstId);
    expect(firstRow.status).toBe("SUPERSEDED");
    const supersededEvent = register.custodyEvents.find((row: { audit_evidence_id: string; action: string }) => row.audit_evidence_id === firstId && row.action === "SUPERSEDED");
    expect(supersededEvent).toBeTruthy();
  });

  it("rejects superseding evidence that is already SUPERSEDED or does not exist on this case", async () => {
    const first = await addEvidence(caseId, { source_resource_type: "OTHER", source_resource_id: "double-supersede-source", description: "Original citation for the double-supersede test.", checksum_sha256: "2".repeat(64) });
    const firstId = (await first.json()).resource.id as string;
    const second = await addEvidence(caseId, { source_resource_type: "OTHER", source_resource_id: "double-supersede-source", description: "First correction of the double-supersede test.", checksum_sha256: "3".repeat(64), supersedes_evidence_id: firstId });
    expect(second.status).toBe(201);

    const thirdAttempt = await addEvidence(caseId, { source_resource_type: "OTHER", source_resource_id: "double-supersede-source", description: "Attempting to supersede the already-superseded original.", checksum_sha256: "4".repeat(64), supersedes_evidence_id: firstId });
    expect(thirdAttempt.status).toBe(409);

    const missingAttempt = await addEvidence(caseId, { source_resource_type: "OTHER", source_resource_id: "unrelated-source", description: "Superseding an id that was never added to this case.", checksum_sha256: "5".repeat(64), supersedes_evidence_id: crypto.randomUUID() });
    expect(missingAttempt.status).toBe(404);
  });

  it("denies a taxpayer-side actor adding evidence (cases:manage is a national-scope permission)", async () => {
    const response = await addEvidence(caseId, { source_resource_type: "OTHER", source_resource_id: "denied-source-1", description: "A taxpayer attempting to add evidence to their own case.", checksum_sha256: "6".repeat(64) }, TAXPAYER_OWNER);
    expect(response.status).toBe(403);
  });

  it("VERIFYs an invoice-cited evidence row's integrity: matches when the invoice hash is unchanged", async () => {
    const evidence = await addEvidence(caseId, { source_resource_type: "INVOICE", source_resource_id: "inv-ev-2", description: "Re-verification target citing a certified invoice." }, nextNationalActor(), crypto.randomUUID());
    const evidenceBody = await evidence.json();
    const response = await custodyEvent(evidenceBody.resource.id, { action: "VERIFY" });
    expect(response.status).toBe(200);

    const { GET } = await import("@/app/api/v1/audit-cases/[id]/evidence/route");
    actingAs(NAMRA_SUPERVISOR);
    const registerResponse = await GET(new Request(`https://vat-msa.local/api/v1/audit-cases/${caseId}/evidence`), { params: Promise.resolve({ id: caseId }) });
    const register = await registerResponse.json();
    const verifyEvent = register.custodyEvents.find((row: { audit_evidence_id: string; action: string }) => row.audit_evidence_id === evidenceBody.resource.id && row.action === "VERIFY");
    expect(verifyEvent.integrity_verified).toBe(1);
  });

  it("VERIFYs OTHER (externally supplied) evidence as unverifiable — integrity_verified stays NULL", async () => {
    const evidence = await addEvidence(caseId, { source_resource_type: "OTHER", source_resource_id: "unverifiable-source-1", description: "External evidence with nothing this system can re-derive.", checksum_sha256: "7".repeat(64) });
    const evidenceBody = await evidence.json();
    await custodyEvent(evidenceBody.resource.id, { action: "VERIFY" });

    const { GET } = await import("@/app/api/v1/audit-cases/[id]/evidence/route");
    actingAs(NAMRA_SUPERVISOR);
    const registerResponse = await GET(new Request(`https://vat-msa.local/api/v1/audit-cases/${caseId}/evidence`), { params: Promise.resolve({ id: caseId }) });
    const register = await registerResponse.json();
    const verifyEvent = register.custodyEvents.find((row: { audit_evidence_id: string; action: string }) => row.audit_evidence_id === evidenceBody.resource.id && row.action === "VERIFY");
    expect(verifyEvent.integrity_verified).toBeNull();
  });

  it("sets and releases a legal hold on document-backed evidence, cascading to the underlying document_metadata row", async () => {
    const evidence = await addEvidence(caseId, { source_resource_type: "DOCUMENT", source_resource_id: "doc-ev-clean", description: "Duplicate citation blocked", supersedes_evidence_id: undefined }, nextNationalActor(), crypto.randomUUID());
    // doc-ev-clean was already cited earlier in this suite, so re-citing it without supersession should conflict — confirm that, then hold the original.
    expect(evidence.status).toBe(409);

    const { GET: GET_EVIDENCE } = await import("@/app/api/v1/audit-cases/[id]/evidence/route");
    actingAs(NAMRA_SUPERVISOR);
    const registerResponse = await GET_EVIDENCE(new Request(`https://vat-msa.local/api/v1/audit-cases/${caseId}/evidence`), { params: Promise.resolve({ id: caseId }) });
    const register = await registerResponse.json();
    const docEvidence = register.evidence.find((row: { document_id: string | null }) => row.document_id === "doc-ev-clean");
    expect(docEvidence).toBeTruthy();

    const hold = await custodyEvent(docEvidence.id, { action: "SET_LEGAL_HOLD", notes: "Preserved pending the taxpayer's formal dispute." });
    expect(hold.status).toBe(200);
    const heldBody = await hold.json();
    expect(heldBody.resource.legal_hold).toBe(1);

    const release = await custodyEvent(docEvidence.id, { action: "RELEASE_LEGAL_HOLD", notes: "Dispute period lapsed with no filing." });
    expect(release.status).toBe(200);
    expect((await release.json()).resource.legal_hold).toBe(0);
  });

  it("returns 404 for evidence and custody events on a non-existent evidence id", async () => {
    const response = await custodyEvent(crypto.randomUUID(), { action: "VERIFY" });
    expect(response.status).toBe(404);
  });

  it("adds an append-only case note and a corrected note that supersedes it without deleting the original", async () => {
    const first = await addNote(caseId, { body: "Contacted the taxpayer's accountant to confirm the delivery date of the goods." });
    expect(first.status).toBe(201);
    const firstBody = await first.json();

    const correction = await addNote(caseId, { body: "Correction: the accountant later confirmed a different delivery date than first recorded.", supersedes_note_id: firstBody.resource.id });
    expect(correction.status).toBe(201);
    const correctionBody = await correction.json();
    expect(correctionBody.resource.supersedes_note_id).toBe(firstBody.resource.id);

    const { GET } = await import("@/app/api/v1/audit-cases/[id]/notes/route");
    actingAs(NAMRA_AUDITOR);
    const notesResponse = await GET(new Request(`https://vat-msa.local/api/v1/audit-cases/${caseId}/notes`), { params: Promise.resolve({ id: caseId }) });
    const notesBody = await notesResponse.json();
    const originalNote = notesBody.notes.find((row: { id: string }) => row.id === firstBody.resource.id);
    expect(originalNote).toBeTruthy();
    expect(originalNote.body).toBe("Contacted the taxpayer's accountant to confirm the delivery date of the goods.");
  });

  it("denies a taxpayer-side actor adding a note, but allows the case's own taxpayer to read the evidence register and notes", async () => {
    const denied = await addNote(caseId, { body: "A taxpayer attempting to write their own case note." }, TAXPAYER_OWNER);
    expect(denied.status).toBe(403);

    const { GET: GET_EVIDENCE } = await import("@/app/api/v1/audit-cases/[id]/evidence/route");
    actingAs(TAXPAYER_OWNER);
    const evidenceRead = await GET_EVIDENCE(new Request(`https://vat-msa.local/api/v1/audit-cases/${caseId}/evidence`), { params: Promise.resolve({ id: caseId }) });
    expect(evidenceRead.status).toBe(200);

    const { GET: GET_NOTES } = await import("@/app/api/v1/audit-cases/[id]/notes/route");
    const notesRead = await GET_NOTES(new Request(`https://vat-msa.local/api/v1/audit-cases/${caseId}/notes`), { params: Promise.resolve({ id: caseId }) });
    expect(notesRead.status).toBe(200);
  });

  it("denies a different taxpayer from reading this case's evidence register or notes", async () => {
    const { GET: GET_EVIDENCE } = await import("@/app/api/v1/audit-cases/[id]/evidence/route");
    actingAs(OTHER_TAXPAYER);
    const evidenceRead = await GET_EVIDENCE(new Request(`https://vat-msa.local/api/v1/audit-cases/${caseId}/evidence`), { params: Promise.resolve({ id: caseId }) });
    expect(evidenceRead.status).toBe(403);

    const { GET: GET_NOTES } = await import("@/app/api/v1/audit-cases/[id]/notes/route");
    const notesRead = await GET_NOTES(new Request(`https://vat-msa.local/api/v1/audit-cases/${caseId}/notes`), { params: Promise.resolve({ id: caseId }) });
    expect(notesRead.status).toBe(403);
  });

  it("is idempotent on AddEvidence under a repeated key", async () => {
    const key = crypto.randomUUID();
    const actor = nextNationalActor();
    const first = await addEvidence(caseId, { source_resource_type: "OTHER", source_resource_id: "idempotent-source-1", description: "Idempotency check for evidence addition.", checksum_sha256: "8".repeat(64) }, actor, key);
    expect(first.status).toBe(201);
    const firstBody = await first.json();
    const retry = await addEvidence(caseId, { source_resource_type: "OTHER", source_resource_id: "idempotent-source-1", description: "Idempotency check for evidence addition.", checksum_sha256: "8".repeat(64) }, actor, key);
    expect(retry.status).toBe(201);
    expect((await retry.json()).resource.id).toBe(firstBody.resource.id);
  });
});
