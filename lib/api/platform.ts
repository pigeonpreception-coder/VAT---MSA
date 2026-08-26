import { AccessDeniedError, getCurrentUser, requirePermission } from "@/lib/auth";
import { completeDocumentScan, downloadDocument, getDocumentVersionHistory, getPlatformSnapshot, PlatformResourceError, receiveOfflineBatch, runInlineReport, setDocumentRetentionHold, supersedeDocument, uploadDocument } from "@/lib/data/platform-repository";
import { RepositoryConflictError } from "@/lib/data/repository";
import { PlatformValidationError } from "@/lib/domain/platform";
import { emitStructuredSecurityLog, enforceRateLimits, readBoundedJson, recordSecurityEvent, requestContext, RequestGuardError } from "@/lib/security/request";

function problem(status: number, code: string, title: string, detail: string, correlationId: string, errors?: unknown) {
  return Response.json({ type: `https://vat-msa.local/problems/${code.toLowerCase().replaceAll("_", "-")}`, title, status, code, detail, correlationId, ...(errors ? { errors } : {}) }, { status, headers: { "content-type": "application/problem+json", "x-correlation-id": correlationId, "cache-control": "no-store" } });
}

function failure(error: unknown, correlationId: string) {
  if (error instanceof PlatformValidationError) return problem(422, "VALIDATION_FAILED", "Validation failed", error.message, correlationId, error.messages.map((item) => ({ ...item, severity: "ERROR" })));
  if (error instanceof PlatformResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, correlationId);
  if (error instanceof RepositoryConflictError) return problem(409, "PLATFORM_CONFLICT", "Conflict", error.message, correlationId);
  if (error instanceof RequestGuardError) return problem(error.status, error.code, "Bad request", error.message, correlationId);
  if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, correlationId);
  return problem(500, "INTERNAL_ERROR", "Internal error", "The platform operation could not be completed.", correlationId);
}

export async function handlePlatformList(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "platform:read");
    return Response.json(await getPlatformSnapshot(user), { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) { return failure(error, context.correlationId); }
}

export async function handleOfflineBatch(request: Request) {
  const context = await requestContext(request);
  const startedAt = Date.now();
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    requirePermission(user, "offline:sync");
    await enforceRateLimits([{ key: `offline:actor:${user.userId}`, limit: 30, windowSeconds: 60 }, { key: `offline:scope:${user.taxpayerId ?? user.role}`, limit: 120, windowSeconds: 60 }, { key: "offline:global", limit: 2_000, windowSeconds: 60 }]);
    const result = await receiveOfflineBatch(await readBoundedJson<never>(request, 5_242_880), user, context.correlationId);
    emitStructuredSecurityLog({ level: "WARN", event: "OFFLINE_BATCH", correlationId: context.correlationId, actorId, outcome: String(result?.status ?? "REJECTED"), durationMs: Date.now() - startedAt });
    return Response.json({ batch: result }, { status: 202, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) await recordSecurityEvent({ eventType: "AUTHORISATION_DENIED", severity: "HIGH", actorId, context, action: "OFFLINE_BATCH", outcome: "DENIED", details: { status: error.status } }).catch(() => undefined);
    return failure(error, context.correlationId);
  }
}

export async function handleReportRun(request: Request, code: string) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "reports:run");
    await enforceRateLimits([{ key: `reports:actor:${user.userId}`, limit: 20, windowSeconds: 60 }, { key: "reports:global", limit: 500, windowSeconds: 60 }]);
    const result = await runInlineReport(code, await readBoundedJson<never>(request, 32_768), user);
    return Response.json({ report_run: result }, { status: 201, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) { return failure(error, context.correlationId); }
}

export async function handleDocumentUpload(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "documents:upload");
    const contentType = request.headers.get("content-type") ?? "";
    if (!contentType.toLowerCase().startsWith("multipart/form-data")) throw new PlatformResourceError("Content-Type must be multipart/form-data.", 415);
    const length = Number(request.headers.get("content-length") ?? "0");
    if (!Number.isSafeInteger(length) || length < 1) throw new PlatformResourceError("A bounded Content-Length header is required.", 411);
    if (length > 11_010_048) throw new PlatformResourceError("Multipart evidence request exceeds 10.5 MiB.", 413);
    await enforceRateLimits([{ key: `documents:actor:${user.userId}`, limit: 20, windowSeconds: 300 }, { key: `documents:scope:${user.taxpayerId ?? user.role}`, limit: 100, windowSeconds: 300 }]);
    const form = await request.formData();
    const file = form.get("file");
    if (!(file instanceof File)) throw new PlatformResourceError("Multipart field 'file' is required.");
    const result = await uploadDocument({ file, ownerDomain: String(form.get("owner_domain") ?? ""), ownerResourceId: String(form.get("owner_resource_id") ?? ""), classification: String(form.get("classification") ?? "TAX_CONFIDENTIAL"), organisationId: String(form.get("organisation_id") ?? "") || null }, user, context.correlationId);
    return Response.json({ document: result, next_action: "External malware scanning must mark the object clean before it can become available." }, { status: 201, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) { return failure(error, context.correlationId); }
}

/** Module 6 Phase A CompleteDocumentScan: records an external scanner's verdict on a quarantined document. */
export async function handleDocumentScanResult(request: Request, documentId: string) {
  const context = await requestContext(request);
  const startedAt = Date.now();
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    requirePermission(user, "documents:manage");
    await enforceRateLimits([{ key: `documents-scan:actor:${user.userId}`, limit: 60, windowSeconds: 60 }, { key: "documents-scan:global", limit: 1_000, windowSeconds: 60 }]);
    const idempotencyKey = request.headers.get("idempotency-key") ?? "";
    const payload = await readBoundedJson<never>(request, 4_096);
    const result = await completeDocumentScan(documentId, payload, user, idempotencyKey, context.correlationId);
    emitStructuredSecurityLog({ level: "INFO", event: "COMPLETE_DOCUMENT_SCAN", correlationId: context.correlationId, actorId, outcome: "SUCCESS", durationMs: Date.now() - startedAt });
    return Response.json({ document: result }, { status: 200, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) await recordSecurityEvent({ eventType: "AUTHORISATION_DENIED", severity: "HIGH", actorId, context, action: "COMPLETE_DOCUMENT_SCAN", outcome: "DENIED", details: { status: error.status } }).catch(() => undefined);
    return failure(error, context.correlationId);
  }
}

/** Module 6 Phase A SupersedeDocument: uploads a replacement for an already-clean document, linking the two as one version chain. */
export async function handleDocumentSupersession(request: Request, documentId: string) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "documents:upload");
    const contentType = request.headers.get("content-type") ?? "";
    if (!contentType.toLowerCase().startsWith("multipart/form-data")) throw new PlatformResourceError("Content-Type must be multipart/form-data.", 415);
    const length = Number(request.headers.get("content-length") ?? "0");
    if (!Number.isSafeInteger(length) || length < 1) throw new PlatformResourceError("A bounded Content-Length header is required.", 411);
    if (length > 11_010_048) throw new PlatformResourceError("Multipart evidence request exceeds 10.5 MiB.", 413);
    await enforceRateLimits([{ key: `documents:actor:${user.userId}`, limit: 20, windowSeconds: 300 }, { key: `documents:scope:${user.taxpayerId ?? user.role}`, limit: 100, windowSeconds: 300 }]);
    const form = await request.formData();
    const file = form.get("file");
    if (!(file instanceof File)) throw new PlatformResourceError("Multipart field 'file' is required.");
    const result = await supersedeDocument(documentId, { file, organisationId: String(form.get("organisation_id") ?? "") || null }, user, context.correlationId);
    return Response.json({ document: result, next_action: "External malware scanning must mark the object clean before it can become available." }, { status: 201, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) { return failure(error, context.correlationId); }
}

/** Module 6 Phase A GetDocumentVersionHistory. */
export async function handleDocumentVersionHistory(request: Request, documentId: string) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "documents:read");
    const result = await getDocumentVersionHistory(documentId, user);
    return Response.json(result, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) { return failure(error, context.correlationId); }
}

/** Module 6 Phase B ApplyRetentionHold/ReleaseRetentionHold, a direct hold on a document independent of Module 4's evidence-citation path. */
export async function handleDocumentRetentionHold(request: Request, documentId: string) {
  const context = await requestContext(request);
  const startedAt = Date.now();
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    requirePermission(user, "documents:manage");
    await enforceRateLimits([{ key: `documents-hold:actor:${user.userId}`, limit: 60, windowSeconds: 60 }, { key: "documents-hold:global", limit: 1_000, windowSeconds: 60 }]);
    const idempotencyKey = request.headers.get("idempotency-key") ?? "";
    const payload = await readBoundedJson<never>(request, 4_096);
    const result = await setDocumentRetentionHold(documentId, payload, user, idempotencyKey, context.correlationId);
    emitStructuredSecurityLog({ level: "INFO", event: "SET_DOCUMENT_RETENTION_HOLD", correlationId: context.correlationId, actorId, outcome: "SUCCESS", durationMs: Date.now() - startedAt });
    return Response.json({ document: result }, { status: 200, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) await recordSecurityEvent({ eventType: "AUTHORISATION_DENIED", severity: "HIGH", actorId, context, action: "SET_DOCUMENT_RETENTION_HOLD", outcome: "DENIED", details: { status: error.status } }).catch(() => undefined);
    return failure(error, context.correlationId);
  }
}

/** Module 6 Phase B AuthorizedDownload: streams the document's actual bytes back, refusing anything not currently ACTIVE or SUPERSEDED. */
export async function handleDocumentDownload(request: Request, documentId: string) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "documents:read");
    await enforceRateLimits([{ key: `documents-download:actor:${user.userId}`, limit: 60, windowSeconds: 300 }, { key: `documents-download:scope:${user.taxpayerId ?? user.role}`, limit: 200, windowSeconds: 300 }]);
    const result = await downloadDocument(documentId, user, context.correlationId);
    return new Response(result.bytes, {
      status: 200,
      headers: {
        "content-type": result.contentType,
        "content-disposition": `attachment; filename="${result.fileName.replaceAll('"', "")}"`,
        "x-correlation-id": context.correlationId,
        "cache-control": "no-store",
      },
    });
  } catch (error) { return failure(error, context.correlationId); }
}
