import { env } from "cloudflare:workers";
import { ensureDatabase } from "@/db/runtime";
import { AccessDeniedError, hasPermission, isNationalScope } from "@/lib/auth";
import { appendAuditEvent } from "@/lib/data/audit-repository";
import { sha256Hex, stableStringify } from "@/lib/domain/invoice";
import { safeFileName, validateDocumentHold, validateDocumentScanResult, validateExportCancellation, validateExportCommand, validateOfflineBatch, validatePlatformChangeDecision, validatePlatformChangeRequest, validateProvisionStaff, validatePublishDataProductCommand, validateReportParameters, validateRunModelCommand, type OfflineBatchSubmission } from "@/lib/domain/platform";
import type { UserContext } from "@/lib/domain/types";
import { RepositoryConflictError } from "./repository";

type OrgScope = { organisation_id: string; taxpayer_id: string; legal_name: string };
type IdempotencyRow = { request_hash: string; resource_id: string };

export class PlatformResourceError extends Error {
  readonly status: number;
  constructor(message: string, status = 422) { super(message); this.name = "PlatformResourceError"; this.status = status; }
}

function validateIdempotencyKey(key: string) {
  if (key.length < 16 || key.length > 128) throw new PlatformResourceError("Idempotency-Key must contain 16 to 128 characters.");
}

async function priorCommand(db: D1Database, actorId: string, type: string, key: string, hash: string): Promise<string | null> {
  const prior = await db.prepare(`SELECT request_hash,resource_id FROM command_idempotency
    WHERE actor_id=? AND command_type=? AND idempotency_key=?`).bind(actorId, type, key).first<IdempotencyRow>();
  if (!prior) return null;
  if (prior.request_hash !== hash) throw new RepositoryConflictError("The idempotency key was already used for a different command payload.");
  return prior.resource_id;
}

function commandRecord(db: D1Database, actorId: string, type: string, key: string, hash: string, resourceType: string, resourceId: string, now: string) {
  return db.prepare(`INSERT INTO command_idempotency
    (id,actor_id,command_type,idempotency_key,request_hash,resource_type,resource_id,created_at)
    VALUES (?,?,?,?,?,?,?,?)`).bind(crypto.randomUUID(), actorId, type, key, hash, resourceType, resourceId, now);
}

async function resolveOrganisation(db: D1Database, actor: UserContext, requested?: string | null) {
  if (isNationalScope(actor)) {
    const row = requested
      ? await db.prepare("SELECT o.id AS organisation_id,o.taxpayer_id,o.legal_name FROM organisations o WHERE o.id=? AND o.status='ACTIVE'").bind(requested).first<OrgScope>()
      : await db.prepare("SELECT o.id AS organisation_id,o.taxpayer_id,o.legal_name FROM organisations o WHERE o.status='ACTIVE' ORDER BY o.id LIMIT 1").first<OrgScope>();
    if (!row) throw new PlatformResourceError("No active organisation is available in the requested scope.", 404);
    return row;
  }
  const row = await db.prepare("SELECT id AS organisation_id,taxpayer_id,legal_name FROM organisations WHERE taxpayer_id=? AND status='ACTIVE'").bind(actor.taxpayerId ?? "__none__").first<OrgScope>();
  if (!row) throw new AccessDeniedError("Your account is not assigned to an active organisation.");
  if (requested && requested !== row.organisation_id) throw new AccessDeniedError("The requested organisation is outside your authorised scope.");
  return row;
}

/** Module 8 Phase D: delegates to the single shared hash-chain writer — see lib/data/audit-repository.ts's appendAuditEvent. */
async function auditRecord(db: D1Database, actor: UserContext, action: string, type: string, id: string, details: Record<string, unknown>, now: string) {
  return appendAuditEvent(db, actor, action, type, id, details, now);
}

function outbox(db: D1Database, type: string, id: string, event: string, partition: string, payload: Record<string, unknown>, now: string) {
  return db.prepare(`INSERT INTO outbox_events
    (id,aggregate_type,aggregate_id,event_type,event_version,partition_key,payload,status,publish_attempts,occurred_at,available_at,published_at,last_error)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(crypto.randomUUID(), type, id, event, 1, partition, JSON.stringify(payload), "PENDING", 0, now, now, null, null);
}

export async function getPlatformSnapshot(actor: UserContext) {
  const db = await ensureDatabase();
  const scoped = !isNationalScope(actor);
  const taxpayerId = actor.taxpayerId ?? "__none__";
  const organisation = scoped ? await resolveOrganisation(db, actor) : null;
  const orgId = organisation?.organisation_id ?? "__none__";
  const [integrations, clients, webhooks, sync, bankImportsResult, payments, devices, ranges, batches, conflicts, definitions, runs, components, documents, outboxState] = await Promise.all([
    scoped ? db.prepare("SELECT * FROM integration_connections WHERE organisation_id IS NULL OR organisation_id=? ORDER BY category,display_name").bind(orgId).all<Record<string, string | null>>() : db.prepare("SELECT * FROM integration_connections ORDER BY category,display_name").all<Record<string, string | null>>(),
    scoped ? db.prepare("SELECT * FROM api_clients WHERE organisation_id=? ORDER BY name").bind(orgId).all<Record<string, string | null>>() : db.prepare("SELECT c.*,o.legal_name FROM api_clients c JOIN organisations o ON o.id=c.organisation_id ORDER BY c.name").all<Record<string, string | null>>(),
    scoped ? db.prepare("SELECT w.* FROM webhook_subscriptions w JOIN api_clients c ON c.id=w.api_client_id WHERE c.organisation_id=?").bind(orgId).all<Record<string, string | null>>() : db.prepare("SELECT * FROM webhook_subscriptions").all<Record<string, string | null>>(),
    scoped ? db.prepare("SELECT * FROM sync_jobs WHERE organisation_id=? ORDER BY requested_at DESC LIMIT 100").bind(orgId).all<Record<string, string | number | null>>() : db.prepare("SELECT * FROM sync_jobs ORDER BY requested_at DESC LIMIT 200").all<Record<string, string | number | null>>(),
    scoped ? db.prepare("SELECT * FROM bank_imports WHERE organisation_id=? ORDER BY created_at DESC").bind(orgId).all<Record<string, string | number | null>>() : db.prepare("SELECT * FROM bank_imports ORDER BY created_at DESC LIMIT 200").all<Record<string, string | number | null>>(),
    scoped ? db.prepare("SELECT * FROM payment_instructions WHERE taxpayer_id=? ORDER BY approved_at DESC").bind(taxpayerId).all<Record<string, string | number | null>>() : db.prepare("SELECT * FROM payment_instructions ORDER BY approved_at DESC LIMIT 200").all<Record<string, string | number | null>>(),
    scoped ? db.prepare("SELECT * FROM offline_devices WHERE organisation_id=? ORDER BY display_name").bind(orgId).all<Record<string, string | number | null>>() : db.prepare("SELECT d.*,o.legal_name FROM offline_devices d JOIN organisations o ON o.id=d.organisation_id ORDER BY d.display_name").all<Record<string, string | number | null>>(),
    scoped ? db.prepare("SELECT r.* FROM offline_number_ranges r JOIN offline_devices d ON d.id=r.offline_device_id WHERE d.organisation_id=?").bind(orgId).all<Record<string, string | number | null>>() : db.prepare("SELECT * FROM offline_number_ranges").all<Record<string, string | number | null>>(),
    scoped ? db.prepare("SELECT b.* FROM offline_sync_batches b JOIN offline_devices d ON d.id=b.offline_device_id WHERE d.organisation_id=? ORDER BY b.received_at DESC LIMIT 100").bind(orgId).all<Record<string, string | number | null>>() : db.prepare("SELECT * FROM offline_sync_batches ORDER BY received_at DESC LIMIT 200").all<Record<string, string | number | null>>(),
    scoped ? db.prepare("SELECT c.* FROM offline_conflicts c JOIN offline_sync_batches b ON b.id=c.offline_sync_batch_id JOIN offline_devices d ON d.id=b.offline_device_id WHERE d.organisation_id=? ORDER BY c.created_at DESC").bind(orgId).all<Record<string, string | null>>() : db.prepare("SELECT * FROM offline_conflicts ORDER BY created_at DESC LIMIT 200").all<Record<string, string | null>>(),
    db.prepare("SELECT * FROM report_definitions WHERE status='ACTIVE' ORDER BY name").all<Record<string, string>>(),
    scoped ? db.prepare("SELECT r.*,d.code,d.name FROM report_runs r JOIN report_definitions d ON d.id=r.report_definition_id WHERE r.organisation_id=? ORDER BY r.requested_at DESC LIMIT 100").bind(orgId).all<Record<string, string | number | null>>() : db.prepare("SELECT r.*,d.code,d.name FROM report_runs r JOIN report_definitions d ON d.id=r.report_definition_id ORDER BY r.requested_at DESC LIMIT 200").all<Record<string, string | number | null>>(),
    db.prepare("SELECT * FROM service_components ORDER BY criticality DESC,display_name").all<Record<string, string | null>>(),
    scoped ? db.prepare("SELECT * FROM document_metadata WHERE organisation_id=? ORDER BY uploaded_at DESC LIMIT 100").bind(orgId).all<Record<string, string | number | null>>() : db.prepare("SELECT d.*,o.legal_name FROM document_metadata d JOIN organisations o ON o.id=d.organisation_id ORDER BY d.uploaded_at DESC LIMIT 200").all<Record<string, string | number | null>>(),
    db.prepare("SELECT status,COUNT(*) AS count FROM outbox_events GROUP BY status").all<{ status: string; count: number }>(),
  ]);
  return { integrations: integrations.results, clients: clients.results, webhooks: webhooks.results, syncJobs: sync.results, bankImports: bankImportsResult.results, payments: payments.results, devices: devices.results, numberRanges: ranges.results, batches: batches.results, conflicts: conflicts.results, reportDefinitions: definitions.results, reportRuns: runs.results, components: components.results, documents: documents.results, outbox: outboxState.results };
}

export async function getTechnicalPlatformSnapshot() {
  const db = await ensureDatabase();
  const [integrations, components, outboxState, clientState, webhookState, syncState, securityState] = await Promise.all([
    db.prepare(`SELECT provider_key,category,display_name,capabilities,configuration_status,operational_status,
      data_classification,last_health_check_at,last_health_outcome FROM integration_connections ORDER BY category,display_name`).all<Record<string, string | null>>(),
    db.prepare("SELECT * FROM service_components ORDER BY criticality DESC,display_name").all<Record<string, string | null>>(),
    db.prepare("SELECT status,COUNT(*) AS count FROM outbox_events GROUP BY status").all<{ status: string; count: number }>(),
    db.prepare("SELECT status,COUNT(*) AS count FROM api_clients GROUP BY status").all<{ status: string; count: number }>(),
    db.prepare("SELECT status,COUNT(*) AS count FROM webhook_subscriptions GROUP BY status").all<{ status: string; count: number }>(),
    db.prepare("SELECT status,COUNT(*) AS count FROM sync_jobs GROUP BY status").all<{ status: string; count: number }>(),
    db.prepare("SELECT severity,COUNT(*) AS count FROM security_events GROUP BY severity").all<{ severity: string; count: number }>(),
  ]);
  return { integrations: integrations.results, components: components.results, outbox: outboxState.results, apiClients: clientState.results, webhooks: webhookState.results, syncJobs: syncState.results, securityEvents: securityState.results };
}

export async function getDocumentCustodySummary(actor: UserContext) {
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(db, actor);
  const result = await db.prepare(`SELECT COUNT(*) AS total,
    SUM(CASE WHEN status='QUARANTINED' THEN 1 ELSE 0 END) AS quarantined,
    SUM(CASE WHEN scan_status='CLEAN' THEN 1 ELSE 0 END) AS clean
    FROM document_metadata WHERE organisation_id=?`).bind(organisation.organisation_id).first<{ total: number; quarantined: number; clean: number }>();
  return { total: Number(result?.total ?? 0), quarantined: Number(result?.quarantined ?? 0), clean: Number(result?.clean ?? 0) };
}

export async function getDeveloperPortalSnapshot(actor: UserContext) {
  if (actor.role === "DEVELOPER_PARTNER" && !actor.taxpayerId) return { clients: [], webhooks: [], provisioning: "ORGANISATION_LINK_REQUIRED" };
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(db, actor);
  const [clients, webhooks] = await Promise.all([
    db.prepare("SELECT id,name,client_key,scopes,status,rate_limit_profile,last_rotated_at,expires_at,created_at FROM api_clients WHERE organisation_id=? ORDER BY name").bind(organisation.organisation_id).all<Record<string, string | null>>(),
    db.prepare(`SELECT w.id,w.api_client_id,w.event_types,w.endpoint_url,w.status,w.created_at
      FROM webhook_subscriptions w JOIN api_clients c ON c.id=w.api_client_id
      WHERE c.organisation_id=? ORDER BY w.created_at DESC`).bind(organisation.organisation_id).all<Record<string, string | null>>(),
  ]);
  return { clients: clients.results, webhooks: webhooks.results, provisioning: "ORGANISED_SCOPE" };
}

const ALLOWED_DOCUMENT_TYPES = new Set(["application/pdf", "image/png", "image/jpeg", "text/csv", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"]);

/**
 * Security fix 2026-08-27 (SECURITY_GAP_ASSESSMENT.md item #7): the MIME
 * check above only ever looked at the client-supplied multipart
 * Content-Type — entirely caller-controlled, and the master architecture's
 * Sec 26 names this exact mistake ("never trust user-controlled ... MIME
 * types"). A .exe declared as application/pdf previously passed straight
 * through. This sniffs the file's own leading bytes against the format its
 * declared type claims to be, using the same signatures browsers/OS file
 * pickers use. PDF/PNG/JPEG/XLSX (a ZIP container) all have real magic
 * numbers; CSV is plain text with no signature to check, so it instead
 * fails a NUL-byte sniff test in a bounded prefix — the standard heuristic
 * for "this is binary data, not text" (mirrors what `file`(1)/git use).
 * This is content-sniffing, not malware scanning — CompleteDocumentScan's
 * human-asserted verdict remains the only scanning this codebase has; see
 * that command's own comment.
 */
function matchesDeclaredType(mimeType: string, bytes: Uint8Array): boolean {
  const prefix = bytes.subarray(0, 8);
  switch (mimeType) {
    case "application/pdf":
      return prefix[0] === 0x25 && prefix[1] === 0x50 && prefix[2] === 0x44 && prefix[3] === 0x46; // "%PDF"
    case "image/png":
      return prefix[0] === 0x89 && prefix[1] === 0x50 && prefix[2] === 0x4e && prefix[3] === 0x47 && prefix[4] === 0x0d && prefix[5] === 0x0a && prefix[6] === 0x1a && prefix[7] === 0x0a;
    case "image/jpeg":
      return prefix[0] === 0xff && prefix[1] === 0xd8 && prefix[2] === 0xff;
    case "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet":
      // XLSX is a ZIP container: local-file-header "PK\x03\x04", or the empty/spanned-archive variants.
      return prefix[0] === 0x50 && prefix[1] === 0x4b && (prefix[2] === 0x03 || prefix[2] === 0x05 || prefix[2] === 0x07);
    case "text/csv":
      return !bytes.subarray(0, 512).includes(0); // no NUL byte in a bounded prefix — plain text has none, binary usually does.
    default:
      return false;
  }
}

async function validateAndHashFile(file: File) {
  if (!ALLOWED_DOCUMENT_TYPES.has(file.type)) throw new PlatformResourceError("File type is not allowed for governed evidence.", 415);
  if (file.size < 1 || file.size > 10_485_760) throw new PlatformResourceError("Evidence files must contain 1 byte to 10 MiB.", 413);
  const bytes = await file.arrayBuffer();
  if (!matchesDeclaredType(file.type, new Uint8Array(bytes))) throw new PlatformResourceError("File content does not match its declared type.", 415);
  const digest = new Uint8Array(await crypto.subtle.digest("SHA-256", bytes));
  const checksum = Array.from(digest, (byte) => byte.toString(16).padStart(2, "0")).join("");
  return { bytes, checksum, fileName: safeFileName(file.name) };
}

export async function uploadDocument(input: { file: File; ownerDomain: string; ownerResourceId: string; classification: string; organisationId?: string | null }, actor: UserContext, correlationId: string) {
  const db = await ensureDatabase();
  const scope = await resolveOrganisation(db, actor, input.organisationId);
  const ownerDomain = input.ownerDomain.trim().toUpperCase();
  if (!new Set(["EXPENSE", "IMPORT", "AUDIT_CASE", "VAT_ADJUSTMENT", "REFUND", "BANK_IMPORT"]).has(ownerDomain)) throw new PlatformResourceError("Owner domain is not supported.");
  if (!/^[A-Za-z0-9][A-Za-z0-9._:-]{1,99}$/.test(input.ownerResourceId)) throw new PlatformResourceError("Owner resource id is invalid.");
  const classification = input.classification.trim().toUpperCase();
  if (!new Set(["INTERNAL", "CONFIDENTIAL", "TAX_CONFIDENTIAL", "RESTRICTED"]).has(classification)) throw new PlatformResourceError("Document classification is invalid.");
  const { bytes, checksum, fileName } = await validateAndHashFile(input.file);
  const id = crypto.randomUUID();
  const objectKey = `quarantine/${scope.organisation_id}/${id}/${fileName}`;
  const now = new Date().toISOString();
  await env.DOCUMENTS.put(objectKey, bytes, { httpMetadata: { contentType: input.file.type, contentDisposition: `attachment; filename="${fileName.replaceAll('"', "")}"` }, customMetadata: { organisationId: scope.organisation_id, documentId: id, checksumSha256: checksum, scanStatus: "PENDING_EXTERNAL_SCANNER" } });
  try {
    await db.batch([
      db.prepare(`INSERT INTO document_metadata
        (id,organisation_id,owner_domain,owner_resource_id,object_key,file_name,content_type,size_bytes,checksum_sha256,classification,scan_status,status,uploaded_by,uploaded_at,retained_until,legal_hold)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,'QUARANTINED',?,?,NULL,0)`).bind(id, scope.organisation_id, ownerDomain, input.ownerResourceId, objectKey, fileName, input.file.type, input.file.size, checksum, classification, "PENDING_EXTERNAL_SCANNER", actor.userId, now),
      outbox(db, "DOCUMENT", id, "DocumentQuarantined", scope.taxpayer_id, { document_id: id, owner_domain: ownerDomain, owner_resource_id: input.ownerResourceId, correlation_id: correlationId }, now),
      await auditRecord(db, actor, "DOCUMENT_QUARANTINED", "DOCUMENT", id, { organisationId: scope.organisation_id, ownerDomain, ownerResourceId: input.ownerResourceId, checksum, correlationId }, now),
    ]);
  } catch (error) {
    await env.DOCUMENTS.delete(objectKey).catch(() => undefined);
    throw error;
  }
  return { id, organisation_id: scope.organisation_id, owner_domain: ownerDomain, owner_resource_id: input.ownerResourceId, file_name: fileName, content_type: input.file.type, size_bytes: input.file.size, checksum_sha256: checksum, classification, scan_status: "PENDING_EXTERNAL_SCANNER", status: "QUARANTINED", uploaded_at: now };
}

/**
 * Module 6 Phase A CompleteDocumentScan: the missing scan-completion path.
 * Every uploaded document previously stayed status='QUARANTINED',
 * scan_status='PENDING_EXTERNAL_SCANNER' forever — nothing in the repo ever
 * wrote past that (verified via a full-repo grep before starting this
 * phase). This records an external scanner's verdict: CLEAN -> ACTIVE
 * (available), INFECTED -> REJECTED (permanently blocked; the object is
 * never deleted, so a rejected upload remains its own evidence). Restricted
 * to documents:manage (national/platform-admin roles only) since this
 * represents an external system's callback, not a taxpayer self-service
 * action — the same "officer-only, not the submitting party" posture
 * Module 2's CancelInvoice already established for invoices:cancel.
 */
export async function completeDocumentScan(documentId: string, payload: unknown, actor: UserContext, idempotencyKey: string, correlationId: string) {
  if (!isNationalScope(actor)) throw new AccessDeniedError("Only an authorised national platform role may record a document scan result.");
  validateIdempotencyKey(idempotencyKey);
  const input = validateDocumentScanResult(payload);
  const db = await ensureDatabase();
  const requestHash = await sha256Hex(stableStringify({ document_id: documentId, input }));
  const prior = await priorCommand(db, actor.userId, "COMPLETE_DOCUMENT_SCAN", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM document_metadata WHERE id=?").bind(prior).first<Record<string, unknown>>();
  const document = await db.prepare(`SELECT d.id,d.organisation_id,d.status,o.taxpayer_id FROM document_metadata d
    JOIN organisations o ON o.id=d.organisation_id WHERE d.id=?`).bind(documentId).first<{ id: string; organisation_id: string; status: string; taxpayer_id: string }>();
  if (!document) throw new PlatformResourceError("Document was not found.", 404);
  if (document.status !== "QUARANTINED") throw new RepositoryConflictError("Document has already been scanned.");
  const now = new Date().toISOString();
  const newStatus = input.outcome === "CLEAN" ? "ACTIVE" : "REJECTED";
  await db.batch([
    db.prepare("UPDATE document_metadata SET status=?,scan_status=?,scanned_by=?,scanned_at=? WHERE id=? AND status='QUARANTINED'").bind(newStatus, input.outcome, actor.userId, now, documentId),
    commandRecord(db, actor.userId, "COMPLETE_DOCUMENT_SCAN", idempotencyKey, requestHash, "DOCUMENT", documentId, now),
    outbox(db, "DOCUMENT", documentId, input.outcome === "CLEAN" ? "DocumentScanClean" : "DocumentScanInfected", document.taxpayer_id, { document_id: documentId, outcome: input.outcome, correlation_id: correlationId }, now),
    await auditRecord(db, actor, `DOCUMENT_SCAN_${input.outcome}`, "DOCUMENT", documentId, { organisationId: document.organisation_id, outcome: input.outcome, notes: input.notes, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM document_metadata WHERE id=?").bind(documentId).first<Record<string, unknown>>();
}

/**
 * Module 6 Phase A SupersedeDocument: the Version concept the playbook
 * names. document_metadata rows are themselves the version chain (the same
 * pattern audit_evidence.previous_version_id and
 * vat_return_versions.parent_version_id already use in this schema) rather
 * than a separate Version table. Only a CLEAN, ACTIVE document can be
 * superseded — you correct a live document, not one still awaiting scan or
 * already rejected/superseded (that status check also makes the chain a
 * strict linked list: a given document can only ever be superseded once,
 * since the second attempt finds status no longer ACTIVE). The superseded
 * row and its R2 object are never deleted, only flipped to
 * status='SUPERSEDED' — the same "never destroy, always append" posture
 * used everywhere else this codebase models a correction.
 */
export async function supersedeDocument(documentId: string, input: { file: File; organisationId?: string | null }, actor: UserContext, correlationId: string) {
  const db = await ensureDatabase();
  const original = await db.prepare("SELECT id,organisation_id,owner_domain,owner_resource_id,classification,status FROM document_metadata WHERE id=?")
    .bind(documentId).first<{ id: string; organisation_id: string; owner_domain: string; owner_resource_id: string; classification: string; status: string }>();
  if (!original) throw new PlatformResourceError("Document was not found.", 404);
  const scope = await resolveOrganisation(db, actor, input.organisationId ?? original.organisation_id);
  if (original.status !== "ACTIVE") throw new RepositoryConflictError("Only a clean, active document can be superseded.");
  const { bytes, checksum, fileName } = await validateAndHashFile(input.file);
  const id = crypto.randomUUID();
  const objectKey = `quarantine/${scope.organisation_id}/${id}/${fileName}`;
  const now = new Date().toISOString();
  await env.DOCUMENTS.put(objectKey, bytes, { httpMetadata: { contentType: input.file.type, contentDisposition: `attachment; filename="${fileName.replaceAll('"', "")}"` }, customMetadata: { organisationId: scope.organisation_id, documentId: id, checksumSha256: checksum, scanStatus: "PENDING_EXTERNAL_SCANNER" } });
  try {
    await db.batch([
      db.prepare(`INSERT INTO document_metadata
        (id,organisation_id,owner_domain,owner_resource_id,object_key,file_name,content_type,size_bytes,checksum_sha256,classification,scan_status,status,uploaded_by,uploaded_at,retained_until,legal_hold,supersedes_document_id)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,'QUARANTINED',?,?,NULL,0,?)`).bind(id, scope.organisation_id, original.owner_domain, original.owner_resource_id, objectKey, fileName, input.file.type, input.file.size, checksum, original.classification, "PENDING_EXTERNAL_SCANNER", actor.userId, now, documentId),
      db.prepare("UPDATE document_metadata SET status='SUPERSEDED' WHERE id=? AND status='ACTIVE'").bind(documentId),
      outbox(db, "DOCUMENT", id, "DocumentSuperseded", scope.taxpayer_id, { document_id: id, supersedes_document_id: documentId, correlation_id: correlationId }, now),
      await auditRecord(db, actor, "DOCUMENT_SUPERSEDED", "DOCUMENT", id, { organisationId: scope.organisation_id, supersedesDocumentId: documentId, checksum, correlationId }, now),
    ]);
  } catch (error) {
    await env.DOCUMENTS.delete(objectKey).catch(() => undefined);
    throw error;
  }
  return { id, organisation_id: scope.organisation_id, owner_domain: original.owner_domain, owner_resource_id: original.owner_resource_id, file_name: fileName, content_type: input.file.type, size_bytes: input.file.size, checksum_sha256: checksum, classification: original.classification, scan_status: "PENDING_EXTERNAL_SCANNER", status: "QUARANTINED", uploaded_at: now, supersedes_document_id: documentId };
}

/** Module 6 Phase A GetDocumentVersionHistory: walks the supersedes_document_id chain in both directions so calling it on any version returns the complete history, oldest first. */
export async function getDocumentVersionHistory(documentId: string, actor: UserContext) {
  const db = await ensureDatabase();
  const anchor = await db.prepare("SELECT * FROM document_metadata WHERE id=?").bind(documentId).first<Record<string, unknown>>();
  if (!anchor) throw new PlatformResourceError("Document was not found.", 404);
  await resolveOrganisation(db, actor, anchor.organisation_id as string);
  const chain: Array<Record<string, unknown>> = [anchor];
  let cursor = anchor;
  while (cursor.supersedes_document_id) {
    const prev = await db.prepare("SELECT * FROM document_metadata WHERE id=?").bind(cursor.supersedes_document_id as string).first<Record<string, unknown>>();
    if (!prev) break;
    chain.unshift(prev);
    cursor = prev;
  }
  cursor = anchor;
  for (;;) {
    const next = await db.prepare("SELECT * FROM document_metadata WHERE supersedes_document_id=?").bind(cursor.id as string).first<Record<string, unknown>>();
    if (!next) break;
    chain.push(next);
    cursor = next;
  }
  return { document_id: documentId, versions: chain };
}

/**
 * Module 6 Phase B ApplyRetentionHold/ReleaseRetentionHold: until now the
 * only way to toggle document_metadata.legal_hold was indirectly, via
 * Module 4's SET_LEGAL_HOLD/RELEASE_LEGAL_HOLD evidence-custody action, and
 * only once a document had already been cited as audit evidence. A
 * document a compliance officer wants preserved before — or without —
 * ever being cited as evidence had no hold path at all. This is that
 * direct path, and cascades the other way from Module 4's own cascade: if
 * this document is already cited by one or more audit_evidence rows, their
 * legal_hold flag is kept in sync too, so the two hold paths can never
 * disagree about the same underlying document.
 *
 * The playbook's stronger claim — "ApplyHold checked by every
 * deletion/retention job repo-wide" — is not built: a full-repo audit
 * before this phase confirmed no deletion or retention/purge job exists
 * anywhere in this codebase to wire it into. There is nothing to enforce
 * against yet, so nothing is stubbed against it.
 */
export async function setDocumentRetentionHold(documentId: string, payload: unknown, actor: UserContext, idempotencyKey: string, correlationId: string) {
  if (!isNationalScope(actor)) throw new AccessDeniedError("Only an authorised national platform role may set a document retention hold.");
  validateIdempotencyKey(idempotencyKey);
  const input = validateDocumentHold(payload);
  const db = await ensureDatabase();
  const requestHash = await sha256Hex(stableStringify({ document_id: documentId, input }));
  const prior = await priorCommand(db, actor.userId, "SET_DOCUMENT_RETENTION_HOLD", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM document_metadata WHERE id=?").bind(prior).first<Record<string, unknown>>();
  const document = await db.prepare(`SELECT d.id,d.organisation_id,o.taxpayer_id FROM document_metadata d
    JOIN organisations o ON o.id=d.organisation_id WHERE d.id=?`).bind(documentId).first<{ id: string; organisation_id: string; taxpayer_id: string }>();
  if (!document) throw new PlatformResourceError("Document was not found.", 404);
  const now = new Date().toISOString();
  const holdValue = input.action === "APPLY" ? 1 : 0;
  await db.batch([
    input.action === "APPLY"
      ? db.prepare("UPDATE document_metadata SET legal_hold=1,retained_until=COALESCE(?,retained_until) WHERE id=?").bind(input.retained_until ?? null, documentId)
      : db.prepare("UPDATE document_metadata SET legal_hold=0 WHERE id=?").bind(documentId),
    db.prepare("UPDATE audit_evidence SET legal_hold=? WHERE document_id=?").bind(holdValue, documentId),
    commandRecord(db, actor.userId, "SET_DOCUMENT_RETENTION_HOLD", idempotencyKey, requestHash, "DOCUMENT", documentId, now),
    outbox(db, "DOCUMENT", documentId, input.action === "APPLY" ? "DocumentRetentionHoldApplied" : "DocumentRetentionHoldReleased", document.taxpayer_id, { document_id: documentId, action: input.action, correlation_id: correlationId }, now),
    await auditRecord(db, actor, `DOCUMENT_RETENTION_HOLD_${input.action}`, "DOCUMENT", documentId, { organisationId: document.organisation_id, action: input.action, notes: input.notes, retainedUntil: input.retained_until, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM document_metadata WHERE id=?").bind(documentId).first<Record<string, unknown>>();
}

/**
 * Module 6 Phase B AuthorizedDownload: until now there was no way to
 * retrieve an uploaded document at all — app/api/v1/documents/route.ts
 * only ever exported POST. Refuses anything not currently ACTIVE or
 * SUPERSEDED (both passed a clean scan at some point; QUARANTINED hasn't
 * been scanned yet and REJECTED is permanently blocked) — the "no download
 * before clean scan" principle this module's matrix row already claimed
 * before this route genuinely existed to enforce it. Access is logged via
 * the same audit-events hash chain as every other command in this file,
 * not a bespoke access-log table — "access logging" is the requirement,
 * not a new entity.
 */
export async function downloadDocument(documentId: string, actor: UserContext, correlationId: string) {
  const db = await ensureDatabase();
  const document = await db.prepare(`SELECT d.*,o.taxpayer_id FROM document_metadata d
    JOIN organisations o ON o.id=d.organisation_id WHERE d.id=?`).bind(documentId)
    .first<{ organisation_id: string; object_key: string; content_type: string; file_name: string; status: string; taxpayer_id: string }>();
  if (!document) throw new PlatformResourceError("Document was not found.", 404);
  await resolveOrganisation(db, actor, document.organisation_id);
  if (document.status !== "ACTIVE" && document.status !== "SUPERSEDED") throw new RepositoryConflictError("The document is not available for download in its current state.");
  const object = await env.DOCUMENTS.get(document.object_key);
  if (!object) throw new PlatformResourceError("The document object could not be located in storage.", 404);
  const bytes = await object.arrayBuffer();
  const now = new Date().toISOString();
  const stmt = await auditRecord(db, actor, "DOCUMENT_DOWNLOADED", "DOCUMENT", documentId, { organisationId: document.organisation_id, correlationId }, now);
  await stmt.run();
  return { bytes, contentType: document.content_type, fileName: document.file_name };
}

export async function receiveOfflineBatch(payload: OfflineBatchSubmission, actor: UserContext, correlationId: string) {
  const batch = validateOfflineBatch(payload);
  const db = await ensureDatabase();
  const device = await db.prepare(`SELECT d.*,o.taxpayer_id FROM offline_devices d JOIN organisations o ON o.id=d.organisation_id
    WHERE (d.id=? OR d.device_code=?)`).bind(batch.device_id, batch.device_id).first<{ id: string; organisation_id: string; taxpayer_id: string; status: string; enrolment_status: string; public_key_reference: string | null; last_accepted_sequence: number; last_batch_hash: string | null }>();
  if (!device) throw new PlatformResourceError("Offline device is not enrolled.", 404);
  if (!isNationalScope(actor) && actor.taxpayerId !== device.taxpayer_id) throw new AccessDeniedError("The offline device is outside your authorised scope.");
  const batchHash = await sha256Hex(stableStringify({ device_id: batch.device_id, batch_id: batch.batch_id, sequence_from: batch.sequence_from, sequence_to: batch.sequence_to, created_at: batch.created_at, previous_batch_hash: batch.previous_batch_hash ?? null, documents: batch.documents }));
  const prior = await db.prepare("SELECT * FROM offline_sync_batches WHERE offline_device_id=? AND client_batch_id=?").bind(device.id, batch.batch_id).first<Record<string, unknown> & { batch_hash: string }>();
  if (prior) {
    if (prior.batch_hash !== batchHash) throw new RepositoryConflictError("Offline batch id was reused with different content.");
    return prior;
  }
  let rejection = "SIGNATURE_VERIFIER_NOT_CONFIGURED";
  if (device.status !== "ACTIVE" || device.enrolment_status !== "VERIFIED" || !device.public_key_reference) rejection = "DEVICE_TRUST_NOT_ESTABLISHED";
  else if (batch.sequence_from !== device.last_accepted_sequence + 1) rejection = "SEQUENCE_GAP_OR_REPLAY";
  else if ((device.last_batch_hash ?? null) !== (batch.previous_batch_hash ?? null)) rejection = "HASH_CHAIN_MISMATCH";
  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  await db.batch([
    db.prepare(`INSERT INTO offline_sync_batches
      (id,offline_device_id,client_batch_id,sequence_from,sequence_to,previous_batch_hash,batch_hash,signature,document_count,status,received_at,processed_at,rejection_reason)
      VALUES (?,?,?,?,?,?,?,?,?,'REJECTED',?,?,?)`).bind(id, device.id, batch.batch_id, batch.sequence_from, batch.sequence_to, batch.previous_batch_hash ?? null, batchHash, batch.device_signature, batch.documents.length, now, now, rejection),
    outbox(db, "OFFLINE_BATCH", id, "OfflineBatchRejected", device.taxpayer_id, { batch_id: id, device_id: device.id, reason: rejection, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "OFFLINE_BATCH_REJECTED", "OFFLINE_BATCH", id, { deviceId: device.id, rejection, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM offline_sync_batches WHERE id=?").bind(id).first<Record<string, unknown>>();
}

const MIN_CELL_SUPPRESSION_THRESHOLD = 10;

/**
 * Module 7 Phase A: enforces the audience-tier guardrail from
 * `08-enterprise-architecture/22-audit-refund-reporting.md`'s reporting
 * table before a report ever runs. Each tier's guardrail is genuinely
 * different in kind — a national-scope check, an executive-permission
 * check, a case-authority check, or a live delegation lookup — so this is
 * a real per-tier dispatch, not one generic gate applied six times with a
 * different label. `TAXPAYER` and `OPEN_DATA` need no extra check here:
 * `TAXPAYER` is already correctly scoped by the existing
 * resolveOrganisation-based own/all split every report already does, and
 * `OPEN_DATA`'s guardrail (minimum-cell suppression) is a result-shaping
 * concern handled where that report computes its own summary, not an
 * access concern.
 */
async function requireAudienceAccess(db: D1Database, definition: { audience: string }, actor: UserContext): Promise<{ delegatedTaxpayerIds?: string[] }> {
  switch (definition.audience) {
    case "TAXPAYER":
    case "OPEN_DATA":
      return {};
    case "NAMRA_OPERATIONS":
      if (!isNationalScope(actor)) throw new AccessDeniedError("This report is restricted to NamRA operations roles.");
      return {};
    case "EXECUTIVE":
      if (!isNationalScope(actor) || !hasPermission(actor, "reports:executive")) throw new AccessDeniedError("This report is restricted to executive roles.");
      return {};
    case "AUDITOR_LEGAL":
      if (!hasPermission(actor, "audit:read") && !hasPermission(actor, "cases:manage")) throw new AccessDeniedError("This report requires audit case authority.");
      return {};
    case "PRACTITIONER": {
      const delegations = await db.prepare("SELECT DISTINCT taxpayer_id FROM delegations WHERE delegate_user_id=? AND status='ACTIVE'").bind(actor.userId).all<{ taxpayer_id: string }>();
      const taxpayerIds = delegations.results.map((row) => row.taxpayer_id);
      if (taxpayerIds.length === 0) throw new AccessDeniedError("You have no active delegated taxpayers for this report.");
      return { delegatedTaxpayerIds: taxpayerIds };
    }
    default:
      throw new PlatformResourceError("Unsupported report audience.", 500);
  }
}

type ReportScope = { organisationId: string | null; taxpayerId: string | null; delegatedTaxpayerIds?: string[]; caseId?: string };

/**
 * Module 7 Phase C: the per-code query logic, extracted out of
 * runInlineReport so the same deterministic computation can be re-run at
 * publish time (see publishReportRun below) as the "reconciles to source
 * control totals" gate — a genuinely fresh recomputation against live
 * source data, not a second copy-pasted query that could drift from the
 * first. Auth (requireAudienceAccess, the CASE_EVIDENCE_SUMMARY case-lookup
 * check) stays the run step's job, not this function's — reconciliation
 * re-derives data, it does not re-authorise a different actor.
 */
async function computeReportResult(db: D1Database, code: string, scope: ReportScope): Promise<Record<string, number | boolean>> {
  if (code === "VAT_POSITION") {
    const row = scope.organisationId
      ? await db.prepare("SELECT COUNT(*) AS periods,COALESCE(SUM(net_payable_cents),0) AS net_cents FROM vat_return_versions WHERE organisation_id=? AND status<>'SUPERSEDED'").bind(scope.organisationId).first<{ periods: number; net_cents: number }>()
      : await db.prepare("SELECT COUNT(*) AS periods,COALESCE(SUM(net_payable_cents),0) AS net_cents FROM vat_return_versions WHERE status<>'SUPERSEDED'").first<{ periods: number; net_cents: number }>();
    return { periods: Number(row?.periods ?? 0), net_cents: Number(row?.net_cents ?? 0) };
  }
  if (code === "COMPLIANCE_CASELOAD") {
    const row = scope.organisationId ? await db.prepare("SELECT COUNT(*) AS cases,SUM(CASE WHEN status<>'CLOSED' THEN 1 ELSE 0 END) AS open_cases FROM audit_cases WHERE organisation_id=?").bind(scope.organisationId).first<{ cases: number; open_cases: number }>() : await db.prepare("SELECT COUNT(*) AS cases,SUM(CASE WHEN status<>'CLOSED' THEN 1 ELSE 0 END) AS open_cases FROM audit_cases").first<{ cases: number; open_cases: number }>();
    return { cases: Number(row?.cases ?? 0), open_cases: Number(row?.open_cases ?? 0) };
  }
  if (code === "SALES_VAT_SUMMARY") {
    const row = scope.taxpayerId ? await db.prepare("SELECT COUNT(*) AS invoices,COALESCE(SUM(total_cents),0) AS total_cents,COALESCE(SUM(tax_cents),0) AS tax_cents FROM invoices WHERE supplier_taxpayer_id=?").bind(scope.taxpayerId).first<{ invoices: number; total_cents: number; tax_cents: number }>() : await db.prepare("SELECT COUNT(*) AS invoices,COALESCE(SUM(total_cents),0) AS total_cents,COALESCE(SUM(tax_cents),0) AS tax_cents FROM invoices").first<{ invoices: number; total_cents: number; tax_cents: number }>();
    return { invoices: Number(row?.invoices ?? 0), total_cents: Number(row?.total_cents ?? 0), tax_cents: Number(row?.tax_cents ?? 0) };
  }
  if (code === "PORTFOLIO_EXCEPTIONS") {
    const taxpayerIds = scope.delegatedTaxpayerIds ?? [];
    const placeholders = taxpayerIds.map(() => "?").join(",");
    const row = taxpayerIds.length
      ? await db.prepare(`SELECT COUNT(*) AS exceptions,SUM(CASE WHEN status='OPEN' THEN 1 ELSE 0 END) AS open_exceptions FROM reconciliation_exceptions WHERE taxpayer_id IN (${placeholders})`).bind(...taxpayerIds).first<{ exceptions: number; open_exceptions: number }>()
      : { exceptions: 0, open_exceptions: 0 };
    return { exceptions: Number(row?.exceptions ?? 0), open_exceptions: Number(row?.open_exceptions ?? 0) };
  }
  if (code === "REVENUE_COMPLIANCE_TRENDS") {
    const [invoiceRow, caseRow] = await Promise.all([
      db.prepare("SELECT COUNT(*) AS invoices,COALESCE(SUM(total_cents),0) AS total_cents FROM invoices").first<{ invoices: number; total_cents: number }>(),
      db.prepare("SELECT COUNT(*) AS cases,SUM(CASE WHEN status<>'CLOSED' THEN 1 ELSE 0 END) AS open_cases FROM audit_cases").first<{ cases: number; open_cases: number }>(),
    ]);
    return { invoices: Number(invoiceRow?.invoices ?? 0), total_cents: Number(invoiceRow?.total_cents ?? 0), cases: Number(caseRow?.cases ?? 0), open_cases: Number(caseRow?.open_cases ?? 0) };
  }
  if (code === "CASE_EVIDENCE_SUMMARY") {
    const caseId = scope.caseId ?? "";
    const [evidenceRow, custodyRow] = await Promise.all([
      db.prepare("SELECT COUNT(*) AS evidence_items,SUM(CASE WHEN status='PRESERVED' THEN 1 ELSE 0 END) AS preserved_items FROM audit_evidence WHERE audit_case_id=?").bind(caseId).first<{ evidence_items: number; preserved_items: number }>(),
      db.prepare("SELECT COUNT(*) AS custody_events FROM audit_evidence_custody_events cce JOIN audit_evidence ae ON ae.id=cce.audit_evidence_id WHERE ae.audit_case_id=?").bind(caseId).first<{ custody_events: number }>(),
    ]);
    return { evidence_items: Number(evidenceRow?.evidence_items ?? 0), preserved_items: Number(evidenceRow?.preserved_items ?? 0), custody_events: Number(custodyRow?.custody_events ?? 0) };
  }
  if (code === "NATIONAL_VAT_AGGREGATE") {
    const row = await db.prepare("SELECT COUNT(*) AS invoices,COALESCE(SUM(total_cents),0) AS total_cents FROM invoices").first<{ invoices: number; total_cents: number }>();
    const invoiceCount = Number(row?.invoices ?? 0);
    const suppressed = invoiceCount < MIN_CELL_SUPPRESSION_THRESHOLD;
    return suppressed ? { invoices: 0, total_cents: 0, suppressed: true } : { invoices: invoiceCount, total_cents: Number(row?.total_cents ?? 0), suppressed: false };
  }
  throw new PlatformResourceError("This report definition has no runnable implementation.", 501);
}

const CURRENCY_BASIS = "NAD";

/**
 * Module 7 Phase C: the shared as-of-time / source-freshness / filters /
 * currency-basis / rule-version envelope, wrapping every report response
 * rather than left as a per-report convention. `currency_basis` is a real
 * constant, not a stub — every monetary figure in this schema (invoices,
 * accounting, expenses, obligations, refunds) is denominated in NAD only;
 * this is a genuinely single-currency pilot today, not a shortcut around
 * multi-currency support that exists elsewhere. `rule_version` reuses
 * `report_definitions.query_version` — the version of this report's own
 * computation logic — rather than Module 2's VAT rule version, since most
 * of these reports (case evidence, exceptions, caseload) have no VAT rule
 * dependency to version at all; a report that genuinely needs the VAT rule
 * in effect for its period can find it via its own invoice/return rows.
 */
function buildReportEnvelope(definition: { audience: string; freshness_tier: string; guardrail: string; query_version: string }, filters: Record<string, unknown>, asOf: string) {
  return { as_of: asOf, audience: definition.audience, freshness_tier: definition.freshness_tier, guardrail: definition.guardrail, filters, currency_basis: CURRENCY_BASIS, rule_version: definition.query_version };
}

/**
 * Module 7 Phase A: previously any report code other than VAT_POSITION or
 * COMPLIANCE_CASELOAD — including the already-seeded SALES_VAT_SUMMARY —
 * silently fell through to a generic invoices-summary query rather than
 * running its own real implementation. Every seeded code now has its own
 * explicit branch; a genuinely unimplemented definition now fails closed
 * (501) instead of silently substituting an unrelated report.
 */
export async function runInlineReport(code: string, parametersInput: unknown, actor: UserContext) {
  const parameters = validateReportParameters(parametersInput);
  const db = await ensureDatabase();
  const definition = await db.prepare("SELECT * FROM report_definitions WHERE code=? AND status='ACTIVE'").bind(code.toUpperCase())
    .first<{ id: string; code: string; audience: string; freshness_tier: string; guardrail: string; query_version: string }>();
  if (!definition) throw new PlatformResourceError("Report definition was not found.", 404);
  const guardrailContext = await requireAudienceAccess(db, definition, actor);
  const orgScope = isNationalScope(actor) ? null : await resolveOrganisation(db, actor);
  let taxpayerIdForRun = orgScope?.taxpayer_id ?? null;
  let organisationIdForRun = orgScope?.organisation_id ?? null;
  let caseId: string | undefined;

  if (definition.code === "PORTFOLIO_EXCEPTIONS" || definition.code === "REVENUE_COMPLIANCE_TRENDS" || definition.code === "NATIONAL_VAT_AGGREGATE") {
    taxpayerIdForRun = null;
    organisationIdForRun = null;
  } else if (definition.code === "CASE_EVIDENCE_SUMMARY") {
    caseId = typeof parameters.case_id === "string" ? parameters.case_id.trim() : "";
    if (!caseId) throw new PlatformResourceError("case_id is required for this report.");
    const auditCase = await db.prepare("SELECT id,taxpayer_id,organisation_id FROM audit_cases WHERE id=?").bind(caseId).first<{ id: string; taxpayer_id: string; organisation_id: string }>();
    if (!auditCase) throw new PlatformResourceError("Audit case was not found.", 404);
    if (!isNationalScope(actor) && actor.taxpayerId !== auditCase.taxpayer_id) throw new AccessDeniedError("The audit case is outside your authorised taxpayer scope.");
    taxpayerIdForRun = auditCase.taxpayer_id;
    organisationIdForRun = auditCase.organisation_id;
  }

  const reportScope: ReportScope = { organisationId: organisationIdForRun, taxpayerId: taxpayerIdForRun, delegatedTaxpayerIds: guardrailContext.delegatedTaxpayerIds, caseId };
  const resultSummary = await computeReportResult(db, definition.code, reportScope);

  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  await db.prepare(`INSERT INTO report_runs
    (id,report_definition_id,organisation_id,taxpayer_id,parameters,status,row_count,result_summary,output_document_id,requested_by,requested_at,completed_at,expires_at,error_code,scope_snapshot,published_by,published_at)
    VALUES (?,?,?,?,?,'COMPLETED_INLINE',?,?,NULL,?,?,?,?,NULL,?,NULL,NULL)`).bind(id, definition.id, organisationIdForRun, taxpayerIdForRun, JSON.stringify(parameters), Object.keys(resultSummary).length, JSON.stringify(resultSummary), actor.userId, now, now, new Date(Date.now() + 86_400_000).toISOString(), JSON.stringify(reportScope)).run();
  return { id, report_code: definition.code, status: "COMPLETED_INLINE", envelope: buildReportEnvelope(definition, parameters, now), result_summary: resultSummary, requested_at: now };
}

/**
 * Module 7 Phase C: `PublishReport` — the "reconciliation-to-source-control-
 * totals as a hard publication gate" the playbook names. This system has no
 * separate warehouse/control-totals ledger yet (that is Phase D's governed
 * read-replica work), so "reconciles to source control totals" is built as
 * a genuine live re-derivation: computeReportResult is re-run against the
 * exact same scope the original run used (persisted in `scope_snapshot`,
 * since e.g. a PRACTITIONER-tier report's delegated-taxpayer set is
 * resolved once at run time and cannot be safely re-derived from whoever
 * happens to call PublishReport) and compared to the stored
 * `result_summary`. If the underlying source rows have changed since the
 * run completed — a new invoice certified, a VAT return superseded, a case
 * closed — the two diverge and publication is refused (409), forcing a
 * fresh run before the figure can become official. A run can only be
 * published once; publishing is idempotent-guarded the same way every other
 * command in this file is.
 */
export async function publishReportRun(reportRunId: string, payload: unknown, actor: UserContext, idempotencyKey: string, correlationId: string) {
  validateIdempotencyKey(idempotencyKey);
  validateExportCommand(payload);
  const db = await ensureDatabase();
  const requestHash = await sha256Hex(stableStringify({ report_run_id: reportRunId }));
  const prior = await priorCommand(db, actor.userId, "PUBLISH_REPORT_RUN", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM report_runs WHERE id=?").bind(prior).first<Record<string, unknown>>();
  const run = await db.prepare(`SELECT r.*,d.code,d.audience,d.freshness_tier,d.guardrail,d.query_version
      FROM report_runs r JOIN report_definitions d ON d.id=r.report_definition_id WHERE r.id=?`).bind(reportRunId)
    .first<{ id: string; status: string; organisation_id: string | null; taxpayer_id: string | null; parameters: string; result_summary: string; scope_snapshot: string | null; requested_by: string; requested_at: string; code: string; audience: string; freshness_tier: string; guardrail: string; query_version: string }>();
  if (!run) throw new PlatformResourceError("Report run was not found.", 404);
  if (!isNationalScope(actor) && run.requested_by !== actor.userId) throw new AccessDeniedError("You may only publish a report run you requested.");
  if (run.status !== "COMPLETED_INLINE") throw new RepositoryConflictError(run.status === "PUBLISHED" ? "This report run has already been published." : "Only a completed report run can be published.");
  const scope: ReportScope = run.scope_snapshot ? JSON.parse(run.scope_snapshot) : { organisationId: run.organisation_id, taxpayerId: run.taxpayer_id };
  const parameters = JSON.parse(run.parameters) as Record<string, unknown>;
  const liveResult = await computeReportResult(db, run.code, scope);
  const storedResult = JSON.parse(run.result_summary) as Record<string, unknown>;
  if (JSON.stringify(liveResult) !== JSON.stringify(storedResult)) {
    throw new RepositoryConflictError("The underlying data has changed since this report run completed; run the report again before publishing.");
  }
  const now = new Date().toISOString();
  await db.batch([
    db.prepare("UPDATE report_runs SET status='PUBLISHED',published_by=?,published_at=? WHERE id=? AND status='COMPLETED_INLINE'").bind(actor.userId, now, reportRunId),
    commandRecord(db, actor.userId, "PUBLISH_REPORT_RUN", idempotencyKey, requestHash, "REPORT_RUN", reportRunId, now),
    outbox(db, "REPORT_RUN", reportRunId, "ReportRunPublished", run.taxpayer_id ?? reportRunId, { report_run_id: reportRunId, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "REPORT_RUN_PUBLISHED", "REPORT_RUN", reportRunId, { code: run.code, correlationId }, now),
  ]);
  return { id: reportRunId, report_code: run.code, status: "PUBLISHED", envelope: buildReportEnvelope(run, parameters, now), result_summary: storedResult, requested_at: run.requested_at, published_at: now };
}

const SENSITIVE_CLASSIFICATIONS = new Set(["TAX_CONFIDENTIAL", "RESTRICTED"]);
const EXPORT_SIZE_LIMIT_BYTES = 200 * 1_024;
const EXPORT_EXPIRY_MS = 7 * 24 * 60 * 60 * 1_000;

type ReportRunForExport = { id: string; status: string; organisation_id: string | null; taxpayer_id: string | null; result_summary: string; requested_by: string; requested_at: string; code: string; name: string; audience: string; freshness_tier: string; classification: string };

function buildExportContent(run: ReportRunForExport, watermark: string): Uint8Array {
  const summary = JSON.parse(run.result_summary) as Record<string, unknown>;
  const lines = [
    `# code:${run.code}`,
    `# name:${run.name}`,
    `# audience:${run.audience}`,
    `# freshness_tier:${run.freshness_tier}`,
    `# as_of:${run.requested_at ?? ""}`,
    `# watermark:${watermark}`,
    "field,value",
    ...Object.entries(summary).map(([key, value]) => `${key},${String(value)}`),
  ];
  return new TextEncoder().encode(`${lines.join("\n")}\n`);
}

async function loadReportRunForExport(db: D1Database, reportRunId: string) {
  const run = await db.prepare(`SELECT r.id,r.status,r.organisation_id,r.taxpayer_id,r.result_summary,r.requested_by,r.requested_at,
      d.code,d.name,d.audience,d.freshness_tier,d.classification
      FROM report_runs r JOIN report_definitions d ON d.id=r.report_definition_id WHERE r.id=?`).bind(reportRunId)
    .first<ReportRunForExport>();
  if (!run) throw new PlatformResourceError("Report run was not found.", 404);
  return run;
}

/**
 * Module 7 Phase B RequestExport: generates the export file inline (this
 * codebase has no queue/cron infrastructure to defer it onto — verified
 * empty `queues`/`triggers` in wrangler.json before this phase started) and
 * stores it as a document_metadata row, reusing Module 6's
 * QUARANTINED/ACTIVE/REJECTED lifecycle as the approval gate: a sensitive
 * report's export starts QUARANTINED (not downloadable) until
 * ApproveExport; a non-sensitive report's export is created directly ACTIVE
 * (auto-approved, no human gate needed). Expiry lives only on
 * report_exports.expires_at — document_metadata.retained_until means "keep
 * at least until," the opposite of an export's "available until," so it is
 * deliberately left NULL here.
 */
export async function requestReportExport(reportRunId: string, payload: unknown, actor: UserContext, idempotencyKey: string, correlationId: string, hasFreshStepUp: boolean) {
  validateIdempotencyKey(idempotencyKey);
  validateExportCommand(payload);
  const db = await ensureDatabase();
  const requestHash = await sha256Hex(stableStringify({ report_run_id: reportRunId }));
  const prior = await priorCommand(db, actor.userId, "REQUEST_REPORT_EXPORT", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM report_exports WHERE id=?").bind(prior).first<Record<string, unknown>>();
  const run = await loadReportRunForExport(db, reportRunId);
  if (!isNationalScope(actor) && run.requested_by !== actor.userId) throw new AccessDeniedError("You may only export a report run you requested.");
  if (run.status !== "COMPLETED_INLINE" && run.status !== "PUBLISHED") throw new RepositoryConflictError("Only a completed report run can be exported.");
  const scope = await resolveOrganisation(db, actor, run.organisation_id ?? undefined);
  const sensitive = SENSITIVE_CLASSIFICATIONS.has(run.classification);
  if (sensitive && !hasFreshStepUp) throw new AccessDeniedError("Exporting a report of this classification requires a fresh step-up authentication.");
  const now = new Date().toISOString();
  const watermark = `issued_to:${actor.userId} at:${now} correlation:${correlationId}`;
  const bytes = buildExportContent(run, watermark);
  if (bytes.byteLength > EXPORT_SIZE_LIMIT_BYTES) throw new PlatformResourceError("The generated export exceeds the maximum allowed size.", 413);
  const fileName = safeFileName(`${run.code}-${run.id}.csv`);
  const documentId = crypto.randomUUID();
  const objectKey = `exports/${scope.organisation_id}/${documentId}/${fileName}`;
  const documentStatus = sensitive ? "QUARANTINED" : "ACTIVE";
  const checksum = Array.from(new Uint8Array(await crypto.subtle.digest("SHA-256", bytes as BufferSource)), (byte) => byte.toString(16).padStart(2, "0")).join("");
  await env.DOCUMENTS.put(objectKey, bytes, { httpMetadata: { contentType: "text/csv", contentDisposition: `attachment; filename="${fileName}"` }, customMetadata: { organisationId: scope.organisation_id, documentId, reportRunId: run.id } });
  const exportId = crypto.randomUUID();
  const expiresAt = new Date(Date.now() + EXPORT_EXPIRY_MS).toISOString();
  try {
    await db.batch([
      db.prepare(`INSERT INTO document_metadata
        (id,organisation_id,owner_domain,owner_resource_id,object_key,file_name,content_type,size_bytes,checksum_sha256,classification,scan_status,status,uploaded_by,uploaded_at,retained_until,legal_hold)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,0)`).bind(documentId, scope.organisation_id, "REPORT_EXPORT", run.id, objectKey, fileName, "text/csv", bytes.byteLength, checksum, run.classification, "CLEAN", documentStatus, actor.userId, now),
      db.prepare(`INSERT INTO report_exports
        (id,report_run_id,document_id,status,requires_step_up,watermark,requested_by,requested_at,approved_by,approved_at,cancelled_by,cancelled_at,cancellation_reason,expires_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,NULL,NULL,NULL,?)`).bind(exportId, run.id, documentId, sensitive ? "PENDING_APPROVAL" : "APPROVED", sensitive ? 1 : 0, watermark, actor.userId, now, sensitive ? null : actor.userId, sensitive ? null : now, expiresAt),
      commandRecord(db, actor.userId, "REQUEST_REPORT_EXPORT", idempotencyKey, requestHash, "REPORT_EXPORT", exportId, now),
      outbox(db, "REPORT_EXPORT", exportId, sensitive ? "ReportExportPendingApproval" : "ReportExportApproved", scope.taxpayer_id, { export_id: exportId, report_run_id: run.id, sensitive, correlation_id: correlationId }, now),
      await auditRecord(db, actor, "REPORT_EXPORT_REQUESTED", "REPORT_EXPORT", exportId, { reportRunId: run.id, classification: run.classification, sensitive, correlationId }, now),
    ]);
  } catch (error) {
    await env.DOCUMENTS.delete(objectKey).catch(() => undefined);
    throw error;
  }
  return db.prepare("SELECT * FROM report_exports WHERE id=?").bind(exportId).first<Record<string, unknown>>();
}

/** Module 7 Phase B ApproveExport: maker-checker gate on a sensitive export. Restricted to national platform roles (the same posture as CompleteDocumentScan/SetDocumentRetentionHold) and refuses the requester's own request. */
export async function approveReportExport(exportId: string, payload: unknown, actor: UserContext, idempotencyKey: string, correlationId: string, hasFreshStepUp: boolean) {
  if (!isNationalScope(actor)) throw new AccessDeniedError("Only an authorised national platform role may approve a report export.");
  validateIdempotencyKey(idempotencyKey);
  validateExportCommand(payload);
  const db = await ensureDatabase();
  const requestHash = await sha256Hex(stableStringify({ export_id: exportId }));
  const prior = await priorCommand(db, actor.userId, "APPROVE_REPORT_EXPORT", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM report_exports WHERE id=?").bind(prior).first<Record<string, unknown>>();
  const row = await db.prepare("SELECT * FROM report_exports WHERE id=?").bind(exportId).first<{ id: string; document_id: string; status: string; requires_step_up: number; requested_by: string }>();
  if (!row) throw new PlatformResourceError("Report export was not found.", 404);
  if (row.status !== "PENDING_APPROVAL") throw new RepositoryConflictError("Only a pending report export can be approved.");
  if (row.requested_by === actor.userId) throw new AccessDeniedError("You may not approve a report export you requested yourself.");
  if (row.requires_step_up && !hasFreshStepUp) throw new AccessDeniedError("Approving this report export requires a fresh step-up authentication.");
  const now = new Date().toISOString();
  await db.batch([
    db.prepare("UPDATE report_exports SET status='APPROVED',approved_by=?,approved_at=? WHERE id=? AND status='PENDING_APPROVAL'").bind(actor.userId, now, exportId),
    db.prepare("UPDATE document_metadata SET status='ACTIVE' WHERE id=? AND status='QUARANTINED'").bind(row.document_id),
    commandRecord(db, actor.userId, "APPROVE_REPORT_EXPORT", idempotencyKey, requestHash, "REPORT_EXPORT", exportId, now),
    outbox(db, "REPORT_EXPORT", exportId, "ReportExportApproved", exportId, { export_id: exportId, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "REPORT_EXPORT_APPROVED", "REPORT_EXPORT", exportId, { correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM report_exports WHERE id=?").bind(exportId).first<Record<string, unknown>>();
}

/** Module 7 Phase B CancelReport: withdraws a still-pending export, either by the original requester or an authorised national role. */
export async function cancelReportExport(exportId: string, payload: unknown, actor: UserContext, idempotencyKey: string, correlationId: string) {
  validateIdempotencyKey(idempotencyKey);
  const input = validateExportCancellation(payload);
  const db = await ensureDatabase();
  const requestHash = await sha256Hex(stableStringify({ export_id: exportId, input }));
  const prior = await priorCommand(db, actor.userId, "CANCEL_REPORT_EXPORT", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM report_exports WHERE id=?").bind(prior).first<Record<string, unknown>>();
  const row = await db.prepare("SELECT * FROM report_exports WHERE id=?").bind(exportId).first<{ id: string; document_id: string; status: string; requested_by: string }>();
  if (!row) throw new PlatformResourceError("Report export was not found.", 404);
  if (!isNationalScope(actor) && row.requested_by !== actor.userId) throw new AccessDeniedError("You may only cancel a report export you requested.");
  if (row.status !== "PENDING_APPROVAL") throw new RepositoryConflictError("Only a pending report export can be cancelled.");
  const now = new Date().toISOString();
  await db.batch([
    db.prepare("UPDATE report_exports SET status='CANCELLED',cancelled_by=?,cancelled_at=?,cancellation_reason=? WHERE id=? AND status='PENDING_APPROVAL'").bind(actor.userId, now, input.reason, exportId),
    db.prepare("UPDATE document_metadata SET status='REJECTED' WHERE id=? AND status='QUARANTINED'").bind(row.document_id),
    commandRecord(db, actor.userId, "CANCEL_REPORT_EXPORT", idempotencyKey, requestHash, "REPORT_EXPORT", exportId, now),
    outbox(db, "REPORT_EXPORT", exportId, "ReportExportCancelled", exportId, { export_id: exportId, reason: input.reason, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "REPORT_EXPORT_CANCELLED", "REPORT_EXPORT", exportId, { reason: input.reason, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM report_exports WHERE id=?").bind(exportId).first<Record<string, unknown>>();
}

async function loadReportExportForActor(db: D1Database, exportId: string, actor: UserContext) {
  const row = await db.prepare(`SELECT x.*,r.taxpayer_id AS run_taxpayer_id FROM report_exports x
      JOIN report_runs r ON r.id=x.report_run_id WHERE x.id=?`).bind(exportId)
    .first<Record<string, unknown> & { requested_by: string; run_taxpayer_id: string | null }>();
  if (!row) throw new PlatformResourceError("Report export was not found.", 404);
  if (!isNationalScope(actor) && row.requested_by !== actor.userId) throw new AccessDeniedError("You may only access a report export you requested.");
  return row;
}

/** Module 7 Phase B: status lookup for a report export (does not return file bytes). */
export async function getReportExport(exportId: string, actor: UserContext) {
  const db = await ensureDatabase();
  return loadReportExportForActor(db, exportId, actor);
}

/**
 * Module 7 Phase B AuthorizedDownload for exports: deliberately its own
 * function rather than a reuse of Module 6's downloadDocument, since a
 * report export additionally gates on report_exports.status='APPROVED' and
 * report_exports.expires_at, neither of which downloadDocument knows
 * about.
 */
export async function downloadReportExport(exportId: string, actor: UserContext, correlationId: string) {
  const db = await ensureDatabase();
  const row = await loadReportExportForActor(db, exportId, actor);
  if (row.status !== "APPROVED") throw new RepositoryConflictError("The report export is not approved for download.");
  if (new Date(row.expires_at as string).getTime() <= Date.now()) throw new PlatformResourceError("The report export has expired.", 410);
  const document = await db.prepare("SELECT object_key,content_type,file_name,status FROM document_metadata WHERE id=?").bind(row.document_id as string)
    .first<{ object_key: string; content_type: string; file_name: string; status: string }>();
  if (!document || document.status !== "ACTIVE") throw new PlatformResourceError("The report export document is not available for download.", 404);
  const object = await env.DOCUMENTS.get(document.object_key);
  if (!object) throw new PlatformResourceError("The report export object could not be located in storage.", 404);
  const bytes = await object.arrayBuffer();
  const now = new Date().toISOString();
  const stmt = await auditRecord(db, actor, "REPORT_EXPORT_DOWNLOADED", "REPORT_EXPORT", exportId, { documentId: row.document_id, correlationId }, now);
  await stmt.run();
  return { bytes, contentType: document.content_type, fileName: document.file_name };
}

/**
 * Module 7 Phase D: Analytics is greenfield in this codebase — a 2026-08-26
 * audit confirmed nothing beyond the "ARCHITECTURE ONLY" label existed. This
 * deployment has no separate governed read replica/warehouse (the same
 * Cloudflare D1 binding backs both the live fiscal write path and every
 * read), so "PublishDataProduct against a governed read replica only, never
 * the live fiscal write store" is built as the strongest real analog
 * available: a DataProduct's `RunModel` step may only be fed by an
 * already-`PUBLISHED`, already-reconciled report run (Phase C's
 * `publishReportRun`) — never a live query against invoices/vat_return_
 * versions/audit_cases/etc. The only tables `runAnalyticsModel` and
 * `publishDataProduct` ever read are `report_runs`/`report_definitions`/
 * `data_products`/`metrics`/`analytics_model_runs`/`data_product_snapshots`
 * — never a fiscal source table directly. `DataProduct`/`Metric`/`Lineage`
 * definitions are deliberately seed-only, the same posture Module 7 Phase A
 * already established for `ReportDefinition`: defining a new governed
 * metric is a governance/config action out of scope for this pilot, not
 * something this phase invents a maker-checker workflow for.
 */
const SUPPRESSED_RESULT_MESSAGE = "The source report run is minimum-cell suppressed and cannot feed a certified analytics model.";

export async function listDataProducts() {
  const db = await ensureDatabase();
  const products = await db.prepare(`SELECT dp.id,dp.code,dp.name,dp.description,dp.status,rd.code AS source_report_code,rd.name AS source_report_name
    FROM data_products dp JOIN report_definitions rd ON rd.id=dp.source_report_definition_id
    WHERE dp.status='ACTIVE' ORDER BY dp.code`).all<{ id: string; code: string; name: string; description: string; status: string; source_report_code: string; source_report_name: string }>();
  return Promise.all(products.results.map(async (product) => {
    const [lineage, metrics, latestSnapshot] = await Promise.all([
      db.prepare("SELECT source_type,source_id,source_label FROM data_product_lineage WHERE data_product_id=? ORDER BY recorded_at").bind(product.id).all<{ source_type: string; source_id: string; source_label: string }>(),
      db.prepare("SELECT code,name,field,unit,status FROM metrics WHERE data_product_id=? ORDER BY code").bind(product.id).all<{ code: string; name: string; field: string; unit: string; status: string }>(),
      db.prepare("SELECT id,snapshot,published_by,published_at FROM data_product_snapshots WHERE data_product_id=? ORDER BY published_at DESC LIMIT 1").bind(product.id).first<{ id: string; snapshot: string; published_by: string; published_at: string }>(),
    ]);
    return {
      id: product.id, code: product.code, name: product.name, description: product.description,
      source: { report_code: product.source_report_code, report_name: product.source_report_name },
      lineage: lineage.results,
      certified_metrics: metrics.results.filter((metric) => metric.status === "CERTIFIED"),
      latest_snapshot: latestSnapshot ? { id: latestSnapshot.id, snapshot: JSON.parse(latestSnapshot.snapshot), published_by: latestSnapshot.published_by, published_at: latestSnapshot.published_at } : null,
    };
  }));
}

/** Module 7 Phase D RunModel. */
export async function runAnalyticsModel(dataProductId: string, payload: unknown, actor: UserContext, idempotencyKey: string, correlationId: string) {
  if (!isNationalScope(actor)) throw new AccessDeniedError("Only an authorised national platform role may run an analytics model.");
  validateIdempotencyKey(idempotencyKey);
  const input = validateRunModelCommand(payload);
  const db = await ensureDatabase();
  const requestHash = await sha256Hex(stableStringify({ data_product_id: dataProductId, input }));
  const prior = await priorCommand(db, actor.userId, "RUN_ANALYTICS_MODEL", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM analytics_model_runs WHERE id=?").bind(prior).first<Record<string, unknown>>();
  const dataProduct = await db.prepare("SELECT id,source_report_definition_id FROM data_products WHERE id=? AND status='ACTIVE'").bind(dataProductId).first<{ id: string; source_report_definition_id: string }>();
  if (!dataProduct) throw new PlatformResourceError("Data product was not found.", 404);
  const reportRun = await db.prepare("SELECT id,report_definition_id,status,result_summary FROM report_runs WHERE id=?").bind(input.report_run_id)
    .first<{ id: string; report_definition_id: string; status: string; result_summary: string }>();
  if (!reportRun) throw new PlatformResourceError("Report run was not found.", 404);
  if (reportRun.report_definition_id !== dataProduct.source_report_definition_id) throw new PlatformResourceError("The report run does not match this data product's governed source report definition.");
  if (reportRun.status !== "PUBLISHED") throw new RepositoryConflictError("Only a published, reconciled report run may feed an analytics model.");
  const sourceResult = JSON.parse(reportRun.result_summary) as Record<string, unknown>;
  if (sourceResult.suppressed === true) throw new RepositoryConflictError(SUPPRESSED_RESULT_MESSAGE);
  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  await db.batch([
    db.prepare(`INSERT INTO analytics_model_runs
      (id,data_product_id,report_run_id,status,model_output,requested_by,requested_at)
      VALUES (?,?,?,'COMPLETED',?,?,?)`).bind(id, dataProductId, input.report_run_id, JSON.stringify(sourceResult), actor.userId, now),
    commandRecord(db, actor.userId, "RUN_ANALYTICS_MODEL", idempotencyKey, requestHash, "ANALYTICS_MODEL_RUN", id, now),
    await auditRecord(db, actor, "ANALYTICS_MODEL_RUN", "ANALYTICS_MODEL_RUN", id, { dataProductId, reportRunId: input.report_run_id, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM analytics_model_runs WHERE id=?").bind(id).first<Record<string, unknown>>();
}

/**
 * Module 7 Phase D PublishDataProduct: promotes a completed ModelRun to be
 * the data product's current snapshot, then checks every CERTIFIED metric
 * on this data product against the previous snapshot's value. A percentage
 * change at or beyond the metric's own `anomaly_threshold_pct` raises a
 * genuine, explainable `AnomalyCandidate` — persisted as a queryable row
 * (`analytics_anomaly_candidates`), not just a fire-and-forget event,
 * matching Module 4's "always return the explainable factor" precedent for
 * risk indicators. The first-ever publish for a data product has nothing to
 * compare against, so it never raises an anomaly.
 */
export async function publishDataProduct(dataProductId: string, payload: unknown, actor: UserContext, idempotencyKey: string, correlationId: string) {
  if (!isNationalScope(actor)) throw new AccessDeniedError("Only an authorised national platform role may publish a data product.");
  validateIdempotencyKey(idempotencyKey);
  const input = validatePublishDataProductCommand(payload);
  const db = await ensureDatabase();
  const requestHash = await sha256Hex(stableStringify({ data_product_id: dataProductId, input }));
  const prior = await priorCommand(db, actor.userId, "PUBLISH_DATA_PRODUCT", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM data_product_snapshots WHERE id=?").bind(prior).first<Record<string, unknown>>();
  const dataProduct = await db.prepare("SELECT id FROM data_products WHERE id=? AND status='ACTIVE'").bind(dataProductId).first<{ id: string }>();
  if (!dataProduct) throw new PlatformResourceError("Data product was not found.", 404);
  const modelRun = await db.prepare("SELECT id,model_output,status FROM analytics_model_runs WHERE id=? AND data_product_id=?").bind(input.model_run_id, dataProductId)
    .first<{ id: string; model_output: string; status: string }>();
  if (!modelRun) throw new PlatformResourceError("Model run was not found for this data product.", 404);
  if (modelRun.status !== "COMPLETED") throw new RepositoryConflictError("Only a completed model run can be published.");
  const alreadyPublished = await db.prepare("SELECT id FROM data_product_snapshots WHERE model_run_id=?").bind(input.model_run_id).first<{ id: string }>();
  if (alreadyPublished) throw new RepositoryConflictError("This model run has already been published.");
  const previous = await db.prepare("SELECT id,snapshot FROM data_product_snapshots WHERE data_product_id=? ORDER BY published_at DESC LIMIT 1").bind(dataProductId)
    .first<{ id: string; snapshot: string }>();
  const now = new Date().toISOString();
  const snapshotId = crypto.randomUUID();
  const modelOutput = JSON.parse(modelRun.model_output) as Record<string, unknown>;
  const previousOutput = previous ? (JSON.parse(previous.snapshot) as Record<string, unknown>) : null;

  const certifiedMetrics = await db.prepare("SELECT code,field,anomaly_threshold_pct FROM metrics WHERE data_product_id=? AND status='CERTIFIED'").bind(dataProductId)
    .all<{ code: string; field: string; anomaly_threshold_pct: number }>();
  const anomalyStatements: D1PreparedStatement[] = [];
  const anomalyOutboxStatements: D1PreparedStatement[] = [];
  const anomalyCount = { value: 0 };
  for (const metric of certifiedMetrics.results) {
    const currentValue = Number(modelOutput[metric.field]);
    const previousValue = previousOutput ? Number(previousOutput[metric.field]) : null;
    if (previousValue === null || !Number.isFinite(previousValue) || previousValue === 0 || !Number.isFinite(currentValue)) continue;
    const pctChange = ((currentValue - previousValue) / Math.abs(previousValue)) * 100;
    if (Math.abs(pctChange) < metric.anomaly_threshold_pct) continue;
    anomalyCount.value += 1;
    const anomalyId = crypto.randomUUID();
    anomalyStatements.push(db.prepare(`INSERT INTO analytics_anomaly_candidates
      (id,data_product_snapshot_id,metric_code,previous_value,current_value,pct_change,threshold_pct,detected_at)
      VALUES (?,?,?,?,?,?,?,?)`).bind(anomalyId, snapshotId, metric.code, previousValue, currentValue, pctChange, metric.anomaly_threshold_pct, now));
    anomalyOutboxStatements.push(outbox(db, "DATA_PRODUCT", dataProductId, "AnomalyCandidate", dataProductId, {
      data_product_id: dataProductId, snapshot_id: snapshotId, metric_code: metric.code,
      previous_value: previousValue, current_value: currentValue, pct_change: pctChange, threshold_pct: metric.anomaly_threshold_pct, correlation_id: correlationId,
    }, now));
  }

  await db.batch([
    db.prepare(`INSERT INTO data_product_snapshots
      (id,data_product_id,model_run_id,snapshot,previous_snapshot_id,published_by,published_at)
      VALUES (?,?,?,?,?,?,?)`).bind(snapshotId, dataProductId, input.model_run_id, JSON.stringify(modelOutput), previous?.id ?? null, actor.userId, now),
    ...anomalyStatements,
    commandRecord(db, actor.userId, "PUBLISH_DATA_PRODUCT", idempotencyKey, requestHash, "DATA_PRODUCT_SNAPSHOT", snapshotId, now),
    outbox(db, "DATA_PRODUCT", dataProductId, "AnalyticsRefreshed", dataProductId, { data_product_id: dataProductId, snapshot_id: snapshotId, correlation_id: correlationId }, now),
    ...anomalyOutboxStatements,
    await auditRecord(db, actor, "DATA_PRODUCT_PUBLISHED", "DATA_PRODUCT_SNAPSHOT", snapshotId, { dataProductId, modelRunId: input.model_run_id, anomalies: anomalyCount.value, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM data_product_snapshots WHERE id=?").bind(snapshotId).first<Record<string, unknown>>();
}

/** Module 7 Phase D QueryApprovedMetrics: reads only `metrics`/`data_product_snapshots`, never a live fiscal table. */
export async function queryApprovedMetrics(filter: { dataProductId?: string; code?: string }) {
  const db = await ensureDatabase();
  const conditions = ["m.status='CERTIFIED'"];
  const params: string[] = [];
  if (filter.dataProductId) { conditions.push("m.data_product_id=?"); params.push(filter.dataProductId); }
  if (filter.code) { conditions.push("m.code=?"); params.push(filter.code.toUpperCase()); }
  const metrics = await db.prepare(`SELECT m.code,m.name,m.unit,m.field,m.data_product_id,dp.code AS data_product_code,dp.name AS data_product_name
    FROM metrics m JOIN data_products dp ON dp.id=m.data_product_id
    WHERE ${conditions.join(" AND ")} ORDER BY m.code`).bind(...params)
    .all<{ code: string; name: string; unit: string; field: string; data_product_id: string; data_product_code: string; data_product_name: string }>();
  return Promise.all(metrics.results.map(async (metric) => {
    const snapshot = await db.prepare("SELECT snapshot,published_at FROM data_product_snapshots WHERE data_product_id=? ORDER BY published_at DESC LIMIT 1").bind(metric.data_product_id)
      .first<{ snapshot: string; published_at: string }>();
    const value = snapshot ? ((JSON.parse(snapshot.snapshot) as Record<string, unknown>)[metric.field] ?? null) : null;
    return {
      code: metric.code, name: metric.name, unit: metric.unit,
      data_product_code: metric.data_product_code, data_product_name: metric.data_product_name,
      value, as_of: snapshot?.published_at ?? null, status: snapshot ? "AVAILABLE" : "NO_DATA",
    };
  }));
}

export async function listAnomalyCandidates(filter: { dataProductId?: string }) {
  const db = await ensureDatabase();
  const rows = filter.dataProductId
    ? await db.prepare(`SELECT a.id,a.metric_code,a.previous_value,a.current_value,a.pct_change,a.threshold_pct,a.detected_at,s.data_product_id
        FROM analytics_anomaly_candidates a JOIN data_product_snapshots s ON s.id=a.data_product_snapshot_id
        WHERE s.data_product_id=? ORDER BY a.detected_at DESC LIMIT 200`).bind(filter.dataProductId).all<Record<string, unknown>>()
    : await db.prepare(`SELECT a.id,a.metric_code,a.previous_value,a.current_value,a.pct_change,a.threshold_pct,a.detected_at,s.data_product_id
        FROM analytics_anomaly_candidates a JOIN data_product_snapshots s ON s.id=a.data_product_snapshot_id
        ORDER BY a.detected_at DESC LIMIT 200`).all<Record<string, unknown>>();
  return rows.results;
}

/**
 * Module 8 Phase A: FeatureFlag/PlatformConfig/AccessPolicy/ChangeRequest —
 * a 2026-08-26 audit found zero code anywhere for any of these, despite the
 * architecture matrix's "VERIFIED FOUNDATION" label on this domain row.
 * Definitions (which flags/config keys/policies exist) are seed-only, the
 * same posture already established for ReportDefinition (Module 7 Phase A)
 * and DataProduct/Metric (Module 7 Phase D) — deciding a new governed knob
 * should exist at all is a deploy-time/governance action, out of scope for
 * a runtime command. Only the VALUE of an existing definition is
 * runtime-changeable, and only through a real maker-checker gate:
 * RequestPlatformChange stages a proposed value as a PENDING change_requests
 * row (capturing a snapshot of the previous value so the diff is always
 * reconstructable); DecidePlatformChange applies or rejects it, refusing
 * self-decision the same way every other maker-checker command in this
 * codebase does. These seeded config values (step-up window, export size
 * cap, minimum-cell suppression threshold, rate-limit defaults) are
 * illustrative/documentary today — they mirror the real hardcoded constants
 * those other modules already enforce, but changing a row here does not yet
 * feed back into those modules' own hardcoded values. Wiring every consumer
 * to read live from platform_config is a larger, cross-module change
 * deliberately left for when a second consumer actually needs it.
 */
type PlatformChangeTargetType = "FEATURE_FLAG" | "PLATFORM_CONFIG" | "ACCESS_POLICY";

async function loadPlatformChangeTarget(db: D1Database, targetType: PlatformChangeTargetType, targetId: string): Promise<Record<string, unknown>> {
  if (targetType === "FEATURE_FLAG") {
    const row = await db.prepare("SELECT enabled FROM feature_flags WHERE id=? AND status='ACTIVE'").bind(targetId).first<{ enabled: number }>();
    if (!row) throw new PlatformResourceError("Feature flag was not found.", 404);
    return { enabled: row.enabled === 1 };
  }
  if (targetType === "PLATFORM_CONFIG") {
    const row = await db.prepare("SELECT value FROM platform_config WHERE id=? AND status='ACTIVE'").bind(targetId).first<{ value: string }>();
    if (!row) throw new PlatformResourceError("Platform config entry was not found.", 404);
    return { value: row.value };
  }
  const row = await db.prepare("SELECT parameters FROM access_policies WHERE id=? AND status='ACTIVE'").bind(targetId).first<{ parameters: string }>();
  if (!row) throw new PlatformResourceError("Access policy was not found.", 404);
  return { parameters: JSON.parse(row.parameters) as Record<string, unknown> };
}

function validatePlatformChangeShape(targetType: PlatformChangeTargetType, proposedValue: Record<string, unknown>): Record<string, unknown> {
  if (targetType === "FEATURE_FLAG") {
    if (typeof proposedValue.enabled !== "boolean") throw new PlatformResourceError("proposed_value.enabled must be a boolean for a feature flag change.");
    return { enabled: proposedValue.enabled };
  }
  if (targetType === "PLATFORM_CONFIG") {
    if (proposedValue.value === undefined) throw new PlatformResourceError("proposed_value.value is required for a platform config change.");
    return { value: proposedValue.value };
  }
  if (!proposedValue.parameters || typeof proposedValue.parameters !== "object" || Array.isArray(proposedValue.parameters)) {
    throw new PlatformResourceError("proposed_value.parameters must be an object for an access policy change.");
  }
  return { parameters: proposedValue.parameters };
}

/** Module 8 Phase A RequestPlatformChange (ChangeFeature/ChangePolicy/ChangeConfig unified). */
export async function requestPlatformChange(actor: UserContext, payload: unknown, idempotencyKey: string, correlationId: string) {
  validateIdempotencyKey(idempotencyKey);
  const input = validatePlatformChangeRequest(payload);
  const db = await ensureDatabase();
  const requestHash = await sha256Hex(stableStringify({ input }));
  const prior = await priorCommand(db, actor.userId, "REQUEST_PLATFORM_CHANGE", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM change_requests WHERE id=?").bind(prior).first<Record<string, unknown>>();
  const previousValue = await loadPlatformChangeTarget(db, input.target_type, input.target_id);
  const proposedValue = validatePlatformChangeShape(input.target_type, input.proposed_value);
  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  await db.batch([
    db.prepare(`INSERT INTO change_requests
      (id,target_type,target_id,previous_value,proposed_value,reason,status,requested_by,requested_at,decided_by,decided_at,decision_notes)
      VALUES (?,?,?,?,?,?,'PENDING',?,?,NULL,NULL,NULL)`).bind(id, input.target_type, input.target_id, JSON.stringify(previousValue), JSON.stringify(proposedValue), input.reason, actor.userId, now),
    commandRecord(db, actor.userId, "REQUEST_PLATFORM_CHANGE", idempotencyKey, requestHash, "CHANGE_REQUEST", id, now),
    outbox(db, "CHANGE_REQUEST", id, "PlatformChangeRequested", id, { change_request_id: id, target_type: input.target_type, target_id: input.target_id, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "PLATFORM_CHANGE_REQUESTED", "CHANGE_REQUEST", id, { targetType: input.target_type, targetId: input.target_id, reason: input.reason, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM change_requests WHERE id=?").bind(id).first<Record<string, unknown>>();
}

/** Module 8 Phase A DecidePlatformChange: maker-checker on a pending change request, refusing the requester's own decision. */
export async function decidePlatformChange(changeRequestId: string, actor: UserContext, payload: unknown, idempotencyKey: string, correlationId: string) {
  validateIdempotencyKey(idempotencyKey);
  const input = validatePlatformChangeDecision(payload);
  const db = await ensureDatabase();
  const requestHash = await sha256Hex(stableStringify({ change_request_id: changeRequestId, input }));
  const prior = await priorCommand(db, actor.userId, "DECIDE_PLATFORM_CHANGE", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM change_requests WHERE id=?").bind(prior).first<Record<string, unknown>>();
  const row = await db.prepare("SELECT id,target_type,target_id,proposed_value,status,requested_by FROM change_requests WHERE id=?").bind(changeRequestId)
    .first<{ id: string; target_type: PlatformChangeTargetType; target_id: string; proposed_value: string; status: string; requested_by: string }>();
  if (!row) throw new PlatformResourceError("Change request was not found.", 404);
  if (row.status !== "PENDING") throw new RepositoryConflictError("Only a pending change request can be decided.");
  if (row.requested_by === actor.userId) throw new AccessDeniedError("You may not decide a platform change request you submitted yourself.");
  const now = new Date().toISOString();
  const applyStatements: D1PreparedStatement[] = [];
  if (input.decision === "APPROVE") {
    const proposed = JSON.parse(row.proposed_value) as Record<string, unknown>;
    if (row.target_type === "FEATURE_FLAG") {
      applyStatements.push(db.prepare("UPDATE feature_flags SET enabled=?,version=version+1,updated_by=?,updated_at=? WHERE id=? AND status='ACTIVE'").bind(proposed.enabled ? 1 : 0, actor.userId, now, row.target_id));
    } else if (row.target_type === "PLATFORM_CONFIG") {
      applyStatements.push(db.prepare("UPDATE platform_config SET value=?,version=version+1,updated_by=?,updated_at=? WHERE id=? AND status='ACTIVE'").bind(String(proposed.value), actor.userId, now, row.target_id));
    } else {
      applyStatements.push(db.prepare("UPDATE access_policies SET parameters=?,version=version+1,updated_by=?,updated_at=? WHERE id=? AND status='ACTIVE'").bind(JSON.stringify(proposed.parameters), actor.userId, now, row.target_id));
    }
  }
  const newStatus = input.decision === "APPROVE" ? "APPLIED" : "REJECTED";
  await db.batch([
    db.prepare("UPDATE change_requests SET status=?,decided_by=?,decided_at=?,decision_notes=? WHERE id=? AND status='PENDING'").bind(newStatus, actor.userId, now, input.notes, changeRequestId),
    ...applyStatements,
    commandRecord(db, actor.userId, "DECIDE_PLATFORM_CHANGE", idempotencyKey, requestHash, "CHANGE_REQUEST", changeRequestId, now),
    outbox(db, "CHANGE_REQUEST", changeRequestId, input.decision === "APPROVE" ? "PlatformChangeApplied" : "PlatformChangeRejected", changeRequestId, { change_request_id: changeRequestId, correlation_id: correlationId }, now),
    await auditRecord(db, actor, `PLATFORM_CHANGE_${newStatus}`, "CHANGE_REQUEST", changeRequestId, { targetType: row.target_type, targetId: row.target_id, notes: input.notes, correlationId }, now),
  ]);
  return db.prepare("SELECT * FROM change_requests WHERE id=?").bind(changeRequestId).first<Record<string, unknown>>();
}

export async function listPlatformChangeRequests(filter: { status?: string }) {
  const db = await ensureDatabase();
  const rows = filter.status
    ? await db.prepare("SELECT * FROM change_requests WHERE status=? ORDER BY requested_at DESC LIMIT 200").bind(filter.status).all<Record<string, unknown>>()
    : await db.prepare("SELECT * FROM change_requests ORDER BY requested_at DESC LIMIT 200").all<Record<string, unknown>>();
  return rows.results;
}

type FeatureFlagRow = { id: string; key: string; name: string; description: string; rollout_scope: string; enabled: number; version: number; updated_at: string | null };
type PlatformConfigRow = { id: string; key: string; category: string; description: string; value: string; version: number; updated_at: string | null };
type AccessPolicyRow = { id: string; code: string; name: string; policy_type: string; description: string; parameters: string; version: number; updated_at: string | null };

/** Module 8 Phase A GetConfig. */
export async function getPlatformConfig() {
  const db = await ensureDatabase();
  const [flags, config, policies] = await Promise.all([
    db.prepare("SELECT id,key,name,description,rollout_scope,enabled,version,updated_at FROM feature_flags WHERE status='ACTIVE' ORDER BY key").all<FeatureFlagRow>(),
    db.prepare("SELECT id,key,category,description,value,version,updated_at FROM platform_config WHERE status='ACTIVE' ORDER BY category,key").all<PlatformConfigRow>(),
    db.prepare("SELECT id,code,name,policy_type,description,parameters,version,updated_at FROM access_policies WHERE status='ACTIVE' ORDER BY policy_type,code").all<AccessPolicyRow>(),
  ]);
  return {
    feature_flags: flags.results.map((row) => ({ id: row.id, key: row.key, name: row.name, description: row.description, rollout_scope: row.rollout_scope, enabled: row.enabled === 1, version: row.version, updated_at: row.updated_at })),
    platform_config: config.results.map((row) => ({ id: row.id, key: row.key, category: row.category, description: row.description, value: row.value, version: row.version, updated_at: row.updated_at })),
    access_policies: policies.results.map((row) => ({ id: row.id, code: row.code, name: row.name, policy_type: row.policy_type, description: row.description, parameters: JSON.parse(row.parameters) as Record<string, unknown>, version: row.version, updated_at: row.updated_at })),
  };
}

/**
 * Module 8 Phase A ProvisionStaff: creates a platform/NamRA staff account —
 * no organisation, a national-scope role from PLATFORM_STAFF_ROLES. The
 * only prior way one of these accounts came into existence was a hardcoded
 * db/runtime.ts seed row; this is a genuinely new capability, not a
 * relabelling of Organisation Administration's inviteEmployee (Module 1),
 * which onboards TAXPAYER-side staff into one organisation.
 */
export async function provisionPlatformStaff(actor: UserContext, payload: unknown, idempotencyKey: string, correlationId: string) {
  validateIdempotencyKey(idempotencyKey);
  const input = validateProvisionStaff(payload);
  const db = await ensureDatabase();
  const requestHash = await sha256Hex(stableStringify({ input }));
  const prior = await priorCommand(db, actor.userId, "PROVISION_PLATFORM_STAFF", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT id,external_user_id,email,display_name,role,status,created_at FROM app_users WHERE id=?").bind(prior).first<Record<string, unknown>>();
  const duplicate = await db.prepare("SELECT id FROM app_users WHERE external_user_id=? OR lower(email)=lower(?)").bind(input.external_user_id, input.email).first<{ id: string }>();
  if (duplicate) throw new RepositoryConflictError("A platform staff account with this identity or email already exists.");
  const provider = await db.prepare("SELECT id FROM identity_providers WHERE provider_key='SITES_WORKSPACE' AND status='ACTIVE' AND configuration_status='CONFIGURED'").first<{ id: string }>();
  if (!provider) throw new PlatformResourceError("The platform identity provider is not configured.", 500);
  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  await db.batch([
    db.prepare("INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES (?,?,?,?,?,NULL,'ACTIVE',?)")
      .bind(id, input.external_user_id, input.email, input.display_name, input.role, now),
    db.prepare(`INSERT INTO identity_links (id,user_id,provider_id,subject,email_at_link,assurance_level,status,linked_at,last_authenticated_at)
      VALUES (?,?,?,?,?,?,?,?,NULL)`).bind(crypto.randomUUID(), id, provider.id, input.external_user_id, input.email, "PLATFORM_AUTHENTICATED", "ACTIVE", now),
    commandRecord(db, actor.userId, "PROVISION_PLATFORM_STAFF", idempotencyKey, requestHash, "APP_USER", id, now),
    outbox(db, "APP_USER", id, "PlatformStaffProvisioned", id, { user_id: id, role: input.role, correlation_id: correlationId }, now),
    await auditRecord(db, actor, "PLATFORM_STAFF_PROVISIONED", "APP_USER", id, { role: input.role, email: input.email, correlationId }, now),
  ]);
  return { id, external_user_id: input.external_user_id, email: input.email, display_name: input.display_name, role: input.role, status: "ACTIVE", created_at: now };
}
