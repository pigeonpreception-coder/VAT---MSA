import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";
import { env } from "@/tests/fakes/cloudflare-workers";
import { __setRequestHeaders } from "@/tests/fakes/next-headers";
import { createFakeD1 } from "@/tests/support/fake-d1";
import { createFakeR2Bucket } from "@/tests/support/fake-r2";

/**
 * Module 6 Phase A: the document scan-completion path (previously every
 * uploaded document stayed status='QUARANTINED', scan_status=
 * 'PENDING_EXTERNAL_SCANNER' forever — nothing in the repo ever transitioned
 * it) and the Version concept via SupersedeDocument's supersedes_document_id
 * chain. Proven through the real route handlers (app/api/v1/documents,
 * app/api/v1/documents/[id]/scan-result, .../supersession, .../versions,
 * dispatched via lib/api/platform.ts) and lib/data/platform-repository.ts.
 * See tests/routes/module-1-access-control.test.ts for why this needs the
 * cloudflare:workers/next/headers fakes and the fake D1 at all.
 */

type FixtureUser = { userId: string; externalUserId: string; email: string };

const OWNER: FixtureUser = { userId: "usr-doc-owner", externalUserId: "ext-doc-owner", email: "owner@doc-test.test" };
const NAMRA_ADMIN: FixtureUser = { userId: "usr-doc-namra", externalUserId: "ext-doc-namra", email: "namra@doc-test.test" };

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
      VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind("tp-doc-taxpayer", "VAT-DOC-001", "TIN-DOC-001", "Document Test Co (Pty) Ltd", null, "PRIVATE_COMPANY", "ACTIVE", "MONTHLY", "1 Document Street", "finance@doc-test.test", now),
    db.prepare(`INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)`)
      .bind("org-doc-taxpayer", "tp-doc-taxpayer", "Document Test Co (Pty) Ltd", null, "ACTIVE", now, now),
    db.prepare(`INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind("idp-doc-workspace", "SITES_WORKSPACE", "Workspace authenticated identity", "PLATFORM", "AUTHENTICATION", null, "ACTIVE", "CONFIGURED", now, now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(OWNER.userId, OWNER.externalUserId, OWNER.email, "Owner", "TAXPAYER_OWNER", "tp-doc-taxpayer", "ACTIVE", now),
    db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,?,?,?)`)
      .bind(NAMRA_ADMIN.userId, NAMRA_ADMIN.externalUserId, NAMRA_ADMIN.email, "NamRA Admin", "PILOT_ADMIN", null, "ACTIVE", now),
    ...[OWNER, NAMRA_ADMIN].map((user) =>
      db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
        VALUES (?,?,?,?,?,?,?,?,?)`).bind(`ilink-${user.userId}`, user.userId, "idp-doc-workspace", user.externalUserId, user.email, "PILOT", "ACTIVE", now, now)),
  ]);
}

async function uploadDocumentRoute(actor: FixtureUser, content = "governed evidence bytes"): Promise<Response> {
  const { POST } = await import("@/app/api/v1/documents/route");
  actingAs(actor);
  const request = await multipartRequest("https://vat-msa.local/api/v1/documents", { owner_domain: "EXPENSE", owner_resource_id: "exp-0001", classification: "TAX_CONFIDENTIAL" }, { name: "receipt.pdf", type: "application/pdf", content });
  return POST(request);
}

async function scanResultRoute(documentId: string, actor: FixtureUser, outcome: "CLEAN" | "INFECTED", key = crypto.randomUUID()): Promise<Response> {
  const { POST } = await import("@/app/api/v1/documents/[id]/scan-result/route");
  actingAs(actor);
  return POST(jsonRequest(`https://vat-msa.local/api/v1/documents/${documentId}/scan-result`, { schema_version: "1.0.0", outcome }, key), { params: Promise.resolve({ id: documentId }) });
}

async function supersedeRoute(documentId: string, actor: FixtureUser, content = "replacement evidence bytes"): Promise<Response> {
  const { POST } = await import("@/app/api/v1/documents/[id]/supersession/route");
  actingAs(actor);
  const request = await multipartRequest(`https://vat-msa.local/api/v1/documents/${documentId}/supersession`, {}, { name: "receipt-v2.pdf", type: "application/pdf", content });
  return POST(request, { params: Promise.resolve({ id: documentId }) });
}

async function versionsRoute(documentId: string, actor: FixtureUser): Promise<Response> {
  const { GET } = await import("@/app/api/v1/documents/[id]/versions/route");
  actingAs(actor);
  return GET(new Request(`https://vat-msa.local/api/v1/documents/${documentId}/versions`), { params: Promise.resolve({ id: documentId }) });
}

describe("Module 6 document scan lifecycle and versioning (Phase A)", () => {
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

  it("uploads a document into quarantine, pending scan", async () => {
    const response = await uploadDocumentRoute(OWNER, "quarantine baseline");
    expect(response.status).toBe(201);
    const body = await response.json();
    expect(body.document.status).toBe("QUARANTINED");
    expect(body.document.scan_status).toBe("PENDING_EXTERNAL_SCANNER");
  });

  it("rejects a file whose content doesn't match its declared type (security fix 2026-08-27, magic-byte content sniffing)", async () => {
    const { POST } = await import("@/app/api/v1/documents/route");
    actingAs(OWNER);
    const form = new FormData();
    form.set("owner_domain", "EXPENSE");
    form.set("owner_resource_id", "exp-0001");
    form.set("classification", "TAX_CONFIDENTIAL");
    // Declared application/pdf, but the actual bytes are plain text with none of the real PDF magic bytes.
    form.set("file", new File(["definitely not a pdf"], "fake.pdf", { type: "application/pdf" }));
    const probe = new Request("https://vat-msa.local/api/v1/documents", { method: "POST", body: form });
    const byteLength = (await probe.clone().arrayBuffer()).byteLength;
    const response = await POST(new Request("https://vat-msa.local/api/v1/documents", { method: "POST", body: form, headers: { "content-length": String(byteLength) } }));
    expect(response.status).toBe(415);
  });

  it("clears a quarantined document to ACTIVE on a CLEAN verdict", async () => {
    const uploaded = await uploadDocumentRoute(OWNER, "clean candidate");
    const documentId = (await uploaded.json()).document.id as string;

    const scanned = await scanResultRoute(documentId, NAMRA_ADMIN, "CLEAN");
    expect(scanned.status).toBe(200);
    const scannedBody = await scanned.json();
    expect(scannedBody.document.status).toBe("ACTIVE");
    expect(scannedBody.document.scan_status).toBe("CLEAN");
    expect(scannedBody.document.scanned_by).toBe(NAMRA_ADMIN.userId);
  });

  it("rejects an infected document to REJECTED and refuses to scan it twice", async () => {
    const uploaded = await uploadDocumentRoute(OWNER, "infected candidate");
    const documentId = (await uploaded.json()).document.id as string;

    const scanned = await scanResultRoute(documentId, NAMRA_ADMIN, "INFECTED");
    expect(scanned.status).toBe(200);
    expect((await scanned.json()).document.status).toBe("REJECTED");

    const scannedAgain = await scanResultRoute(documentId, NAMRA_ADMIN, "CLEAN");
    expect(scannedAgain.status).toBe(409);
  });

  it("denies an actor without documents:manage from recording a scan result", async () => {
    const uploaded = await uploadDocumentRoute(OWNER, "denied candidate");
    const documentId = (await uploaded.json()).document.id as string;
    const response = await scanResultRoute(documentId, OWNER, "CLEAN");
    expect(response.status).toBe(403);
  });

  it("supersedes a clean, active document with a new version, and the old one flips to SUPERSEDED", async () => {
    const uploaded = await uploadDocumentRoute(OWNER, "version 1 content");
    const originalId = (await uploaded.json()).document.id as string;
    await scanResultRoute(originalId, NAMRA_ADMIN, "CLEAN");

    const superseded = await supersedeRoute(originalId, OWNER, "version 2 content");
    expect(superseded.status).toBe(201);
    const supersededBody = await superseded.json();
    expect(supersededBody.document.status).toBe("QUARANTINED");
    expect(supersededBody.document.supersedes_document_id).toBe(originalId);
    const newId = supersededBody.document.id as string;

    const historyFromOld = await versionsRoute(originalId, OWNER);
    expect(historyFromOld.status).toBe(200);
    const historyFromOldBody = await historyFromOld.json();
    expect(historyFromOldBody.versions).toHaveLength(2);
    expect(historyFromOldBody.versions[0].id).toBe(originalId);
    expect(historyFromOldBody.versions[0].status).toBe("SUPERSEDED");
    expect(historyFromOldBody.versions[1].id).toBe(newId);

    const historyFromNew = await versionsRoute(newId, OWNER);
    const historyFromNewBody = await historyFromNew.json();
    expect(historyFromNewBody.versions.map((v: { id: string }) => v.id)).toEqual([originalId, newId]);
  });

  it("rejects superseding a document that has not yet been cleared", async () => {
    const uploaded = await uploadDocumentRoute(OWNER, "still quarantined");
    const documentId = (await uploaded.json()).document.id as string;
    const response = await supersedeRoute(documentId, OWNER, "attempted replacement");
    expect(response.status).toBe(409);
  });

  it("returns 404 scanning or superseding a non-existent document", async () => {
    const scanResponse = await scanResultRoute(crypto.randomUUID(), NAMRA_ADMIN, "CLEAN");
    expect(scanResponse.status).toBe(404);
    const supersedeResponse = await supersedeRoute(crypto.randomUUID(), OWNER, "orphan replacement");
    expect(supersedeResponse.status).toBe(404);
  });
});
