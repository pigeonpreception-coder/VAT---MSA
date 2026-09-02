import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";
import { createFakeR2Bucket } from "@/tests/support/fake-r2";

/**
 * Module 6 Phase B: AuthorizedDownload (previously there was no way to
 * retrieve an uploaded document at all — app/api/v1/documents/route.ts only
 * ever exported POST) and a direct document-level retention hold
 * (previously the only way to toggle document_metadata.legal_hold was
 * indirectly, via Module 4's evidence-custody SET_LEGAL_HOLD/
 * RELEASE_LEGAL_HOLD action, and only once a document had been cited as
 * audit evidence). Proven through the real route handlers
 * (app/api/v1/documents/[id]/download, .../retention-hold, dispatched via
 * lib/api/platform.ts) and lib/data/platform-repository.ts. See
 * tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const OWNER: FixtureUser = { userId: "usr-dochold-owner", externalUserId: "ext-dochold-owner", email: "owner@dochold-test.test" };
const NAMRA_ADMIN: FixtureUser = { userId: "usr-dochold-namra", externalUserId: "ext-dochold-namra", email: "namra@dochold-test.test" };

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

/** Security fix 2026-08-27 (SECURITY_GAP_ASSESSMENT.md item #7): uploadDocument now sniffs the file's own leading bytes against its declared MIME type, so a PDF upload's fixture content must actually start with the real PDF magic bytes ("%PDF-"). */
async function multipartRequest(url: string, fields: Record<string, string>, file: { name: string; type: string; content: string }): Promise<Request> {
  const form = new FormData();
  for (const [key, value] of Object.entries(fields)) form.set(key, value);
  const content = file.type === "application/pdf" ? `%PDF-1.4\n${file.content}` : file.content;
  form.set("file", new File([content], file.name, { type: file.type }));
  const probe = new Request(url, { method: "POST", body: form });
  const byteLength = (await probe.clone().arrayBuffer()).byteLength;
  return new Request(url, { method: "POST", body: form, headers: { "content-length": String(byteLength) } });
}

async function seedFixture(): Promise<void> {
  const db = env.DB;
  const now = "2026-08-01T00:00:00.000Z";
  await db.batch([
    db.prepare(`INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-dochold-taxpayer", "VAT-DOCHOLD-001", "TIN-DOCHOLD-001", "Document Hold Test Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Document Hold Street", "finance@dochold-test.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-dochold-taxpayer", "tp-dochold-taxpayer", "Document Hold Test Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-dochold-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OWNER.userId, OWNER.externalUserId, OWNER.email, "Owner", "TAXPAYER_OWNER", "tp-dochold-taxpayer", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_ADMIN.userId, NAMRA_ADMIN.externalUserId, NAMRA_ADMIN.email, "NamRA Admin", "PILOT_ADMIN", null, "ACTIVE", now),
    ...[OWNER, NAMRA_ADMIN].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-dochold-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
  ]);
}

async function uploadDocumentRoute(actor: FixtureUser, content = "governed evidence bytes"): Promise<Response> {
  const { POST } = await import("@/app/api/v1/documents/route");
  actingAs(actor);
  const request = await multipartRequest("https://vat-msa.local/api/v1/documents", { owner_domain: "EXPENSE", owner_resource_id: "exp-0001", classification: "TAX_CONFIDENTIAL" }, { name: "receipt.pdf", type: "application/pdf", content });
  return POST(request);
}

async function scanResultRoute(documentId: string, actor: FixtureUser, outcome: "CLEAN" | "INFECTED"): Promise<Response> {
  const { POST } = await import("@/app/api/v1/documents/[id]/scan-result/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/documents/${documentId}/scan-result`, { schema_version: "1.0.0", outcome }), { params: Promise.resolve({ id: documentId }) });
}

async function supersedeRoute(documentId: string, actor: FixtureUser, content: string): Promise<Response> {
  const { POST } = await import("@/app/api/v1/documents/[id]/supersession/route");
  actingAs(actor);
  const request = await multipartRequest(`https://vat-msa.local/api/v1/documents/${documentId}/supersession`, {}, { name: "receipt-v2.pdf", type: "application/pdf", content });
  return POST(request, { params: Promise.resolve({ id: documentId }) });
}

async function downloadRoute(documentId: string, actor: FixtureUser): Promise<Response> {
  const { GET } = await import("@/app/api/v1/documents/[id]/download/route");
  actingAs(actor);
  return GET(new Request(`https://vat-msa.local/api/v1/documents/${documentId}/download`), { params: Promise.resolve({ id: documentId }) });
}

async function retentionHoldRoute(documentId: string, actor: FixtureUser, body: Record<string, unknown>): Promise<Response> {
  const { POST } = await import("@/app/api/v1/documents/[id]/retention-hold/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/documents/${documentId}/retention-hold`, { schema_version: "1.0.0", ...body }), { params: Promise.resolve({ id: documentId }) });
}

describe("Module 6 document retention hold and authorized download (Phase B)", () => {
  beforeAll(async () => {
    vi.stubEnv("NODE_ENV", "production");
    env.DB = createFakeD1();
    env.DOCUMENTS = createFakeR2Bucket();
    const { ensureDatabase } = await import("@/db/runtime");
    await ensureDatabase();
    await seedFixture();
  });

  afterAll(() => {
    vi.unstubAllEnvs();
  });

  it("downloads a clean, active document with its original bytes and refuses a still-quarantined one", async () => {
    const uploaded = await uploadDocumentRoute(OWNER, "downloadable payload");
    const documentId = (await uploaded.json()).document.id as string;

    const beforeScan = await downloadRoute(documentId, OWNER);
    expect(beforeScan.status).toBe(409);

    await scanResultRoute(documentId, NAMRA_ADMIN, "CLEAN");
    const response = await downloadRoute(documentId, OWNER);
    expect(response.status).toBe(200);
    expect(await response.text()).toBe("%PDF-1.4\ndownloadable payload");
    expect(response.headers.get("content-disposition")).toContain("receipt.pdf");
  });

  it("refuses to download a rejected (infected) document", async () => {
    const uploaded = await uploadDocumentRoute(OWNER, "infected payload");
    const documentId = (await uploaded.json()).document.id as string;
    await scanResultRoute(documentId, NAMRA_ADMIN, "INFECTED");
    const response = await downloadRoute(documentId, OWNER);
    expect(response.status).toBe(409);
  });

  it("still allows downloading a superseded (historical) version", async () => {
    const uploaded = await uploadDocumentRoute(OWNER, "original historical payload");
    const originalId = (await uploaded.json()).document.id as string;
    await scanResultRoute(originalId, NAMRA_ADMIN, "CLEAN");
    await supersedeRoute(originalId, OWNER, "replacement payload");

    const response = await downloadRoute(originalId, OWNER);
    expect(response.status).toBe(200);
    expect(await response.text()).toBe("%PDF-1.4\noriginal historical payload");
  });

  it("returns 404 downloading a non-existent document", async () => {
    const response = await downloadRoute(crypto.randomUUID(), OWNER);
    expect(response.status).toBe(404);
  });

  it("applies and releases a retention hold, cascading to any audit evidence citing the document", async () => {
    const uploaded = await uploadDocumentRoute(OWNER, "held document payload");
    const documentId = (await uploaded.json()).document.id as string;
    await scanResultRoute(documentId, NAMRA_ADMIN, "CLEAN");

    const now = "2026-08-26T00:00:00.000Z";
    await env.DB.batch([
      env.DB.prepare(`INSERT INTO audit_cases (id,case_number,organisation_id,taxpayer_id,case_type,title,opening_reason,risk_tier,status,opened_by,opened_at,updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)`).bind("case-dochold-cascade", "CASE-DOCHOLD-0001", "org-dochold-taxpayer", "tp-dochold-taxpayer", "DESK_AUDIT", "Hold cascade test case", "Testing hold cascade", "LOW", "PROPOSED", NAMRA_ADMIN.userId, now, now),
      env.DB.prepare(`INSERT INTO audit_evidence (id,audit_case_id,evidence_type,source_resource_type,source_resource_id,document_id,checksum_sha256,description,status,added_by,added_at,legal_hold)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,0)`).bind("evidence-dochold-cascade", "case-dochold-cascade", "DOCUMENT", "DOCUMENT", documentId, documentId, "a".repeat(64), "Cited document under test", "PRESERVED", NAMRA_ADMIN.userId, now),
    ]);

    const applied = await retentionHoldRoute(documentId, NAMRA_ADMIN, { action: "APPLY", notes: "Subject to an active legal request.", retained_until: "2031-01-01" });
    expect(applied.status).toBe(200);
    const appliedBody = await applied.json();
    expect(appliedBody.document.legal_hold).toBe(1);
    expect(appliedBody.document.retained_until).toBe("2031-01-01");

    const evidenceAfterApply = await env.DB.prepare("SELECT legal_hold FROM audit_evidence WHERE id=?").bind("evidence-dochold-cascade").first<{ legal_hold: number }>();
    expect(evidenceAfterApply?.legal_hold).toBe(1);

    const released = await retentionHoldRoute(documentId, NAMRA_ADMIN, { action: "RELEASE", notes: "Legal request has concluded." });
    expect(released.status).toBe(200);
    expect((await released.json()).document.legal_hold).toBe(0);

    const evidenceAfterRelease = await env.DB.prepare("SELECT legal_hold FROM audit_evidence WHERE id=?").bind("evidence-dochold-cascade").first<{ legal_hold: number }>();
    expect(evidenceAfterRelease?.legal_hold).toBe(0);
  });

  it("denies an actor without documents:manage from setting a retention hold", async () => {
    const uploaded = await uploadDocumentRoute(OWNER, "denied hold payload");
    const documentId = (await uploaded.json()).document.id as string;
    const response = await retentionHoldRoute(documentId, OWNER, { action: "APPLY", notes: "Attempted by an unauthorised actor." });
    expect(response.status).toBe(403);
  });

  it("returns 404 setting a retention hold on a non-existent document", async () => {
    const response = await retentionHoldRoute(crypto.randomUUID(), NAMRA_ADMIN, { action: "APPLY", notes: "Attempted on an orphan document id." });
    expect(response.status).toBe(404);
  });
});
