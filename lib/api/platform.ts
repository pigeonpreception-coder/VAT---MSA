import { AccessDeniedError, getCurrentUser, requirePermission } from "@/lib/auth";
import { approveReportExport, cancelReportExport, completeDocumentScan, decidePlatformChange, downloadDocument, downloadReportExport, getDocumentVersionHistory, getPlatformConfig, getPlatformSnapshot, getReportExport, getTechnicalPlatformSnapshot, listAnomalyCandidates, listDataProducts, listPlatformChangeRequests, PlatformResourceError, provisionPlatformStaff, publishDataProduct, publishReportRun, queryApprovedMetrics, receiveOfflineBatch, requestPlatformChange, requestReportExport, runAnalyticsModel, runInlineReport, setDocumentRetentionHold, supersedeDocument, uploadDocument } from "@/lib/data/platform-repository";
import { RepositoryConflictError } from "@/lib/data/repository";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { PlatformValidationError } from "@/lib/domain/platform";
import { emitStructuredSecurityLog, enforceRateLimits, readBoundedJson, recordAuthorizationDenial, recordRateLimitBreach, requestContext, type RequestContext, RequestGuardError } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

async function hasFreshStepUp(request: Request, user: Parameters<typeof requireStepUp>[1]): Promise<boolean> {
  try { await requireStepUp(request, user); return true; } catch { return false; }
}

function problem(status: number, code: string, title: string, detail: string, correlationId: string, errors?: unknown) {
  return Response.json({ type: `https://vat-msa.local/problems/${code.toLowerCase().replaceAll("_", "-")}`, title, status, code, detail, correlationId, ...(errors ? { errors } : {}) }, { status, headers: { "content-type": "application/problem+json", "x-correlation-id": correlationId, "cache-control": "no-store" } });
}

/**
 * Security fix 2026-08-27 (SECURITY_GAP_ASSESSMENT.md item #4): the
 * RequestGuardError branch returned without ever recording
 * RATE_LIMIT_ABUSE's input event, and roughly half this file's handlers
 * (the read-only ones, plus a few writes) relied solely on this shared
 * fallback for AccessDeniedError too — which previously recorded nothing.
 * Now takes the full RequestContext (not just the correlationId string)
 * and records both centrally; the individual write handlers' own inline
 * AccessDeniedError recording was removed as redundant (see each
 * handler's own comment history) rather than left to double-record.
 */
async function failure(error: unknown, context: RequestContext) {
  const correlationId = context.correlationId;
  if (error instanceof PlatformValidationError) return problem(422, "VALIDATION_FAILED", "Validation failed", error.message, correlationId, error.messages.map((item) => ({ ...item, severity: "ERROR" })));
  if (error instanceof PlatformResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, correlationId);
  if (error instanceof RepositoryConflictError) return problem(409, "PLATFORM_CONFLICT", "Conflict", error.message, correlationId);
  if (error instanceof RequestGuardError) {
    await recordRateLimitBreach(context, error);
    return problem(error.status, error.code, "Bad request", error.message, correlationId);
  }
  if (error instanceof AccessDeniedError) {
    await recordAuthorizationDenial(context, error.message, error.status);
    return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, correlationId);
  }
  return problem(500, "INTERNAL_ERROR", "Internal error", "The platform operation could not be completed.", correlationId);
}

const TECHNICAL_ONLY_ROLES = new Set(["SUPER_ADMIN", "INFRASTRUCTURE_ADMIN"]);

/**
 * Module 8 Phase A fix: a 2026-08-26 audit found the "finance-data exclusion
 * from technical admin" separation was enforced only at the Next.js page
 * level (app/integrations/page.tsx branches on the actor's role before
 * calling this same snapshot), not here at the API route the matrix implies
 * covers it uniformly. SUPER_ADMIN/INFRASTRUCTURE_ADMIN hold no
 * organisation/taxpayer scope, so getPlatformSnapshot's own scoping already
 * returned them nothing in practice — but that was incidental, not a
 * structural guarantee. This makes it structural: a technical-only actor is
 * routed to the technical snapshot outright, never reaching a query that
 * touches payment_instructions/bank_imports at all.
 */
export async function handlePlatformList(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "platform:read");
    const result = TECHNICAL_ONLY_ROLES.has(user.role) ? await getTechnicalPlatformSnapshot() : await getPlatformSnapshot(user);
    return Response.json(result, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) { return failure(error, context); }
}

export async function handleOfflineBatch(request: Request) {
  const context = await requestContext(request);
  const startedAt = Date.now();
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    await requireLicensedPermission(user, "offline:sync");
    await enforceRateLimits([{ key: `offline:actor:${user.userId}`, limit: 30, windowSeconds: 60 }, { key: `offline:scope:${user.taxpayerId ?? user.role}`, limit: 120, windowSeconds: 60 }, { key: "offline:global", limit: 2_000, windowSeconds: 60 }]);
    const result = await receiveOfflineBatch(await readBoundedJson<never>(request, 5_242_880), user, context.correlationId);
    emitStructuredSecurityLog({ level: "WARN", event: "OFFLINE_BATCH", correlationId: context.correlationId, actorId, outcome: String(result?.status ?? "REJECTED"), durationMs: Date.now() - startedAt });
    return Response.json({ batch: result }, { status: 202, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    return failure(error, context);
  }
}

export async function handleReportRun(request: Request, code: string) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    await requireLicensedPermission(user, "reports:run");
    await enforceRateLimits([{ key: `reports:actor:${user.userId}`, limit: 20, windowSeconds: 60 }, { key: "reports:global", limit: 500, windowSeconds: 60 }]);
    const result = await runInlineReport(code, await readBoundedJson<never>(request, 32_768), user);
    return Response.json({ report_run: result }, { status: 201, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) { return failure(error, context); }
}

export async function handleDocumentUpload(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    await requireLicensedPermission(user, "documents:upload");
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
  } catch (error) { return failure(error, context); }
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
    return failure(error, context);
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
  } catch (error) { return failure(error, context); }
}

/** Module 6 Phase A GetDocumentVersionHistory. */
export async function handleDocumentVersionHistory(request: Request, documentId: string) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "documents:read");
    const result = await getDocumentVersionHistory(documentId, user);
    return Response.json(result, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) { return failure(error, context); }
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
    return failure(error, context);
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
  } catch (error) { return failure(error, context); }
}

/** Module 7 Phase C PublishReport: reconciles the run's stored result against a fresh recomputation of the same source data before marking it the official, published figure. */
export async function handleReportRunPublication(request: Request, reportRunId: string) {
  const context = await requestContext(request);
  const startedAt = Date.now();
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    requirePermission(user, "reports:run");
    await enforceRateLimits([{ key: `reports-publish:actor:${user.userId}`, limit: 20, windowSeconds: 300 }, { key: "reports-publish:global", limit: 500, windowSeconds: 300 }]);
    const idempotencyKey = request.headers.get("idempotency-key") ?? "";
    const payload = await readBoundedJson<never>(request, 4_096);
    const result = await publishReportRun(reportRunId, payload, user, idempotencyKey, context.correlationId);
    emitStructuredSecurityLog({ level: "INFO", event: "PUBLISH_REPORT_RUN", correlationId: context.correlationId, actorId, outcome: "SUCCESS", durationMs: Date.now() - startedAt });
    return Response.json({ report_run: result }, { status: 200, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    return failure(error, context);
  }
}

/** Module 7 Phase B RequestExport: generates a report run's downloadable export, auto-approved unless the report's classification is sensitive. */
export async function handleReportExportRequest(request: Request, reportRunId: string) {
  const context = await requestContext(request);
  const startedAt = Date.now();
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    requirePermission(user, "reports:run");
    await enforceRateLimits([{ key: `reports-export:actor:${user.userId}`, limit: 20, windowSeconds: 300 }, { key: "reports-export:global", limit: 500, windowSeconds: 300 }]);
    const idempotencyKey = request.headers.get("idempotency-key") ?? "";
    const payload = await readBoundedJson<never>(request, 4_096);
    const result = await requestReportExport(reportRunId, payload, user, idempotencyKey, context.correlationId, await hasFreshStepUp(request, user));
    emitStructuredSecurityLog({ level: "INFO", event: "REQUEST_REPORT_EXPORT", correlationId: context.correlationId, actorId, outcome: "SUCCESS", durationMs: Date.now() - startedAt });
    return Response.json({ report_export: result }, { status: 201, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    return failure(error, context);
  }
}

/** Module 7 Phase B ApproveExport: maker-checker approval of a sensitive export. */
export async function handleReportExportApproval(request: Request, exportId: string) {
  const context = await requestContext(request);
  const startedAt = Date.now();
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    requirePermission(user, "reports:run");
    await enforceRateLimits([{ key: `reports-export-approve:actor:${user.userId}`, limit: 60, windowSeconds: 300 }, { key: "reports-export-approve:global", limit: 1_000, windowSeconds: 300 }]);
    const idempotencyKey = request.headers.get("idempotency-key") ?? "";
    const payload = await readBoundedJson<never>(request, 4_096);
    const result = await approveReportExport(exportId, payload, user, idempotencyKey, context.correlationId, await hasFreshStepUp(request, user));
    emitStructuredSecurityLog({ level: "INFO", event: "APPROVE_REPORT_EXPORT", correlationId: context.correlationId, actorId, outcome: "SUCCESS", durationMs: Date.now() - startedAt });
    return Response.json({ report_export: result }, { status: 200, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    return failure(error, context);
  }
}

/** Module 7 Phase B CancelReport (export withdrawal). */
export async function handleReportExportCancellation(request: Request, exportId: string) {
  const context = await requestContext(request);
  const startedAt = Date.now();
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    requirePermission(user, "reports:run");
    await enforceRateLimits([{ key: `reports-export-cancel:actor:${user.userId}`, limit: 60, windowSeconds: 300 }, { key: "reports-export-cancel:global", limit: 1_000, windowSeconds: 300 }]);
    const idempotencyKey = request.headers.get("idempotency-key") ?? "";
    const payload = await readBoundedJson<never>(request, 4_096);
    const result = await cancelReportExport(exportId, payload, user, idempotencyKey, context.correlationId);
    emitStructuredSecurityLog({ level: "INFO", event: "CANCEL_REPORT_EXPORT", correlationId: context.correlationId, actorId, outcome: "SUCCESS", durationMs: Date.now() - startedAt });
    return Response.json({ report_export: result }, { status: 200, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    return failure(error, context);
  }
}

/** Module 7 Phase B: status lookup for a report export. */
export async function handleReportExportStatus(request: Request, exportId: string) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "reports:read");
    const result = await getReportExport(exportId, user);
    return Response.json({ report_export: result }, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) { return failure(error, context); }
}

/** Module 7 Phase B AuthorizedDownload for exports: refuses anything not currently APPROVED and unexpired. */
export async function handleReportExportDownload(request: Request, exportId: string) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "reports:read");
    await enforceRateLimits([{ key: `reports-export-download:actor:${user.userId}`, limit: 60, windowSeconds: 300 }, { key: "reports-export-download:global", limit: 1_000, windowSeconds: 300 }]);
    const result = await downloadReportExport(exportId, user, context.correlationId);
    return new Response(result.bytes, {
      status: 200,
      headers: {
        "content-type": result.contentType,
        "content-disposition": `attachment; filename="${result.fileName.replaceAll('"', "")}"`,
        "x-correlation-id": context.correlationId,
        "cache-control": "no-store",
      },
    });
  } catch (error) { return failure(error, context); }
}

/** Module 7 Phase D: DataProduct list, with lineage, certified metrics and the latest published snapshot. */
export async function handleAnalyticsDataProducts(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "reports:read");
    const result = await listDataProducts();
    return Response.json({ data_products: result }, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) { return failure(error, context); }
}

/** Module 7 Phase D RunModel: computes a ModelRun from an already-published, reconciled report run. */
export async function handleAnalyticsModelRun(request: Request, dataProductId: string) {
  const context = await requestContext(request);
  const startedAt = Date.now();
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    requirePermission(user, "reports:run");
    await enforceRateLimits([{ key: `analytics-model-run:actor:${user.userId}`, limit: 20, windowSeconds: 300 }, { key: "analytics-model-run:global", limit: 500, windowSeconds: 300 }]);
    const idempotencyKey = request.headers.get("idempotency-key") ?? "";
    const payload = await readBoundedJson<never>(request, 4_096);
    const result = await runAnalyticsModel(dataProductId, payload, user, idempotencyKey, context.correlationId);
    emitStructuredSecurityLog({ level: "INFO", event: "RUN_ANALYTICS_MODEL", correlationId: context.correlationId, actorId, outcome: "SUCCESS", durationMs: Date.now() - startedAt });
    return Response.json({ model_run: result }, { status: 201, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    return failure(error, context);
  }
}

/** Module 7 Phase D PublishDataProduct: promotes a completed ModelRun to the data product's current snapshot and checks certified metrics for anomalies. */
export async function handleAnalyticsDataProductPublication(request: Request, dataProductId: string) {
  const context = await requestContext(request);
  const startedAt = Date.now();
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    requirePermission(user, "reports:run");
    await enforceRateLimits([{ key: `analytics-publish:actor:${user.userId}`, limit: 20, windowSeconds: 300 }, { key: "analytics-publish:global", limit: 500, windowSeconds: 300 }]);
    const idempotencyKey = request.headers.get("idempotency-key") ?? "";
    const payload = await readBoundedJson<never>(request, 4_096);
    const result = await publishDataProduct(dataProductId, payload, user, idempotencyKey, context.correlationId);
    emitStructuredSecurityLog({ level: "INFO", event: "PUBLISH_DATA_PRODUCT", correlationId: context.correlationId, actorId, outcome: "SUCCESS", durationMs: Date.now() - startedAt });
    return Response.json({ snapshot: result }, { status: 201, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    return failure(error, context);
  }
}

/** Module 7 Phase D QueryApprovedMetrics: certified metrics only, each with its current value from the latest published snapshot. */
export async function handleAnalyticsMetrics(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "reports:read");
    const params = new URL(request.url).searchParams;
    const result = await queryApprovedMetrics({ dataProductId: params.get("data_product_id")?.trim() || undefined, code: params.get("code")?.trim() || undefined });
    return Response.json({ metrics: result }, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) { return failure(error, context); }
}

/** Module 7 Phase D: queryable AnomalyCandidate list, not just a fire-and-forget outbox event. */
export async function handleAnalyticsAnomalies(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "reports:read");
    const params = new URL(request.url).searchParams;
    const result = await listAnomalyCandidates({ dataProductId: params.get("data_product_id")?.trim() || undefined });
    return Response.json({ anomalies: result }, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) { return failure(error, context); }
}

/** Module 8 Phase A GetConfig: FeatureFlag/PlatformConfig/AccessPolicy, each with its current value/version. */
export async function handlePlatformConfig(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "platform:read");
    const result = await getPlatformConfig();
    return Response.json(result, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) { return failure(error, context); }
}

/** Module 8 Phase A: list change requests, filterable by status. */
export async function handlePlatformChangeRequestList(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "platform:read");
    const status = new URL(request.url).searchParams.get("status")?.trim().toUpperCase() || undefined;
    const result = await listPlatformChangeRequests({ status });
    return Response.json({ change_requests: result }, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) { return failure(error, context); }
}

/** Module 8 Phase A RequestPlatformChange (ChangeFeature/ChangePolicy/ChangeConfig unified). */
export async function handlePlatformChangeRequestCreate(request: Request) {
  const context = await requestContext(request);
  const startedAt = Date.now();
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    requirePermission(user, "platform:manage");
    await enforceRateLimits([{ key: `platform-change:actor:${user.userId}`, limit: 30, windowSeconds: 300 }, { key: "platform-change:global", limit: 500, windowSeconds: 300 }]);
    const idempotencyKey = request.headers.get("idempotency-key") ?? "";
    const payload = await readBoundedJson<never>(request, 8_192);
    const result = await requestPlatformChange(user, payload, idempotencyKey, context.correlationId);
    emitStructuredSecurityLog({ level: "INFO", event: "REQUEST_PLATFORM_CHANGE", correlationId: context.correlationId, actorId, outcome: "SUCCESS", durationMs: Date.now() - startedAt });
    return Response.json({ change_request: result }, { status: 201, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    return failure(error, context);
  }
}

/** Module 8 Phase A DecidePlatformChange: maker-checker approval/rejection of a pending change request. */
export async function handlePlatformChangeDecision(request: Request, changeRequestId: string) {
  const context = await requestContext(request);
  const startedAt = Date.now();
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    requirePermission(user, "platform:manage");
    await enforceRateLimits([{ key: `platform-change-decide:actor:${user.userId}`, limit: 30, windowSeconds: 300 }, { key: "platform-change-decide:global", limit: 500, windowSeconds: 300 }]);
    const idempotencyKey = request.headers.get("idempotency-key") ?? "";
    const payload = await readBoundedJson<never>(request, 4_096);
    const result = await decidePlatformChange(changeRequestId, user, payload, idempotencyKey, context.correlationId);
    emitStructuredSecurityLog({ level: "INFO", event: "DECIDE_PLATFORM_CHANGE", correlationId: context.correlationId, actorId, outcome: "SUCCESS", durationMs: Date.now() - startedAt });
    return Response.json({ change_request: result }, { status: 200, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    return failure(error, context);
  }
}

/**
 * Module 8 Phase A ProvisionStaff: creates a platform/NamRA technical staff
 * account. Always step-up gated (unconditionally, not the conditional
 * hasFreshStepUp pattern Phase B's exports use) — provisioning a new
 * national-scope account is always sensitive, the same posture
 * CancelInvoice already established for a comparably privileged action.
 */
export async function handleProvisionPlatformStaff(request: Request) {
  const context = await requestContext(request);
  const startedAt = Date.now();
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    requirePermission(user, "platform:manage");
    await requireStepUp(request, user);
    await enforceRateLimits([{ key: `platform-staff:actor:${user.userId}`, limit: 10, windowSeconds: 300 }, { key: "platform-staff:global", limit: 100, windowSeconds: 300 }]);
    const idempotencyKey = request.headers.get("idempotency-key") ?? "";
    const payload = await readBoundedJson<never>(request, 4_096);
    const result = await provisionPlatformStaff(user, payload, idempotencyKey, context.correlationId);
    emitStructuredSecurityLog({ level: "INFO", event: "PROVISION_PLATFORM_STAFF", correlationId: context.correlationId, actorId, outcome: "SUCCESS", durationMs: Date.now() - startedAt });
    return Response.json({ staff: result }, { status: 201, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    return failure(error, context);
  }
}
