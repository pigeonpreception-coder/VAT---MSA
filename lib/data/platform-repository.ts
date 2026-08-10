import { env } from "cloudflare:workers";
import { ensureDatabase } from "@/db/runtime";
import { AccessDeniedError, isNationalScope } from "@/lib/auth";
import { sha256Hex, stableStringify } from "@/lib/domain/invoice";
import { safeFileName, validateOfflineBatch, validateReportParameters, type OfflineBatchSubmission } from "@/lib/domain/platform";
import type { UserContext } from "@/lib/domain/types";
import { RepositoryConflictError } from "./repository";

type OrgScope = { organisation_id: string; taxpayer_id: string; legal_name: string };

export class PlatformResourceError extends Error {
  readonly status: number;
  constructor(message: string, status = 422) { super(message); this.name = "PlatformResourceError"; this.status = status; }
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

async function auditRecord(db: D1Database, actor: UserContext, action: string, type: string, id: string, details: Record<string, unknown>, now: string) {
  const eventId = crypto.randomUUID();
  const prior = await db.prepare("SELECT event_hash FROM audit_events ORDER BY occurred_at DESC LIMIT 1").first<{ event_hash: string }>();
  const body = JSON.stringify(details);
  const hash = await sha256Hex(`${prior?.event_hash ?? "GENESIS"}|${eventId}|${actor.userId}|${body}|${now}`);
  return db.prepare("INSERT INTO audit_events VALUES (?,?,?,?,?,?,?,?,?,?,?)").bind(eventId, actor.userId, actor.role, action, type, id, "SUCCESS", body, prior?.event_hash ?? null, hash, now);
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

export async function uploadDocument(input: { file: File; ownerDomain: string; ownerResourceId: string; classification: string; organisationId?: string | null }, actor: UserContext, correlationId: string) {
  const db = await ensureDatabase();
  const scope = await resolveOrganisation(db, actor, input.organisationId);
  const allowedTypes = new Set(["application/pdf", "image/png", "image/jpeg", "text/csv", "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"]);
  if (!allowedTypes.has(input.file.type)) throw new PlatformResourceError("File type is not allowed for governed evidence.", 415);
  if (input.file.size < 1 || input.file.size > 10_485_760) throw new PlatformResourceError("Evidence files must contain 1 byte to 10 MiB.", 413);
  const ownerDomain = input.ownerDomain.trim().toUpperCase();
  if (!new Set(["EXPENSE", "IMPORT", "AUDIT_CASE", "VAT_ADJUSTMENT", "REFUND", "BANK_IMPORT"]).has(ownerDomain)) throw new PlatformResourceError("Owner domain is not supported.");
  if (!/^[A-Za-z0-9][A-Za-z0-9._:-]{1,99}$/.test(input.ownerResourceId)) throw new PlatformResourceError("Owner resource id is invalid.");
  const classification = input.classification.trim().toUpperCase();
  if (!new Set(["INTERNAL", "CONFIDENTIAL", "TAX_CONFIDENTIAL", "RESTRICTED"]).has(classification)) throw new PlatformResourceError("Document classification is invalid.");
  const bytes = await input.file.arrayBuffer();
  const digest = new Uint8Array(await crypto.subtle.digest("SHA-256", bytes));
  const checksum = Array.from(digest, (byte) => byte.toString(16).padStart(2, "0")).join("");
  const id = crypto.randomUUID();
  const fileName = safeFileName(input.file.name);
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

export async function runInlineReport(code: string, parametersInput: unknown, actor: UserContext) {
  const parameters = validateReportParameters(parametersInput);
  const db = await ensureDatabase();
  const definition = await db.prepare("SELECT * FROM report_definitions WHERE code=? AND status='ACTIVE'").bind(code.toUpperCase()).first<{ id: string; code: string; audience: string }>();
  if (!definition) throw new PlatformResourceError("Report definition was not found.", 404);
  const scope = isNationalScope(actor) ? null : await resolveOrganisation(db, actor);
  let resultSummary: Record<string, number>;
  if (definition.code === "VAT_POSITION") {
    const row = scope
      ? await db.prepare("SELECT COUNT(*) AS periods,COALESCE(SUM(net_payable_cents),0) AS net_cents FROM vat_return_versions WHERE organisation_id=? AND status<>'SUPERSEDED'").bind(scope.organisation_id).first<{ periods: number; net_cents: number }>()
      : await db.prepare("SELECT COUNT(*) AS periods,COALESCE(SUM(net_payable_cents),0) AS net_cents FROM vat_return_versions WHERE status<>'SUPERSEDED'").first<{ periods: number; net_cents: number }>();
    resultSummary = { periods: Number(row?.periods ?? 0), net_cents: Number(row?.net_cents ?? 0) };
  } else if (definition.code === "COMPLIANCE_CASELOAD") {
    const row = scope ? await db.prepare("SELECT COUNT(*) AS cases,SUM(CASE WHEN status<>'CLOSED' THEN 1 ELSE 0 END) AS open_cases FROM audit_cases WHERE organisation_id=?").bind(scope.organisation_id).first<{ cases: number; open_cases: number }>() : await db.prepare("SELECT COUNT(*) AS cases,SUM(CASE WHEN status<>'CLOSED' THEN 1 ELSE 0 END) AS open_cases FROM audit_cases").first<{ cases: number; open_cases: number }>();
    resultSummary = { cases: Number(row?.cases ?? 0), open_cases: Number(row?.open_cases ?? 0) };
  } else {
    const row = scope ? await db.prepare("SELECT COUNT(*) AS invoices,COALESCE(SUM(total_cents),0) AS total_cents,COALESCE(SUM(tax_cents),0) AS tax_cents FROM invoices WHERE supplier_taxpayer_id=?").bind(scope.taxpayer_id).first<{ invoices: number; total_cents: number; tax_cents: number }>() : await db.prepare("SELECT COUNT(*) AS invoices,COALESCE(SUM(total_cents),0) AS total_cents,COALESCE(SUM(tax_cents),0) AS tax_cents FROM invoices").first<{ invoices: number; total_cents: number; tax_cents: number }>();
    resultSummary = { invoices: Number(row?.invoices ?? 0), total_cents: Number(row?.total_cents ?? 0), tax_cents: Number(row?.tax_cents ?? 0) };
  }
  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  await db.prepare(`INSERT INTO report_runs
    (id,report_definition_id,organisation_id,taxpayer_id,parameters,status,row_count,result_summary,output_document_id,requested_by,requested_at,completed_at,expires_at,error_code)
    VALUES (?,?,?,?,?,'COMPLETED_INLINE',?,?,NULL,?,?,?,?,NULL)`).bind(id, definition.id, scope?.organisation_id ?? null, scope?.taxpayer_id ?? null, JSON.stringify(parameters), Object.keys(resultSummary).length, JSON.stringify(resultSummary), actor.userId, now, now, new Date(Date.now() + 86_400_000).toISOString()).run();
  return { id, report_code: definition.code, status: "COMPLETED_INLINE", result_summary: resultSummary, requested_at: now };
}
