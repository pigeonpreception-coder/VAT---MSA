import { AccessDeniedError, getCurrentUser, requirePermission } from "@/lib/auth";
import { closeIncident, containIncident, createIncident, getIncidentDetail, getSOCQueue, revokeIncidentAccess, SecurityResourceError } from "@/lib/data/security-repository";
import { RepositoryConflictError } from "@/lib/data/repository";
import { SecurityValidationError } from "@/lib/domain/security";
import { emitStructuredSecurityLog, enforceRateLimits, readBoundedJson, recordSecurityEvent, requestContext, RequestGuardError } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

function problem(status: number, code: string, title: string, detail: string, correlationId: string, errors?: unknown) {
  return Response.json({ type: `https://vat-msa.local/problems/${code.toLowerCase().replaceAll("_", "-")}`, title, status, code, detail, correlationId, ...(errors ? { errors } : {}) }, { status, headers: { "content-type": "application/problem+json", "x-correlation-id": correlationId, "cache-control": "no-store" } });
}

function failure(error: unknown, correlationId: string) {
  if (error instanceof SecurityValidationError) return problem(422, "VALIDATION_FAILED", "Validation failed", error.message, correlationId, error.messages.map((item) => ({ ...item, severity: "ERROR" })));
  if (error instanceof SecurityResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, correlationId);
  if (error instanceof RepositoryConflictError) return problem(409, "SECURITY_CONFLICT", "Conflict", error.message, correlationId);
  if (error instanceof RequestGuardError) return problem(error.status, error.code, "Bad request", error.message, correlationId);
  if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, correlationId);
  return problem(500, "INTERNAL_ERROR", "Internal error", "The security operation could not be completed.", correlationId);
}

/** Module 8 Phase B GetSOCQueue: filterable by status/severity. */
export async function handleSOCQueue(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "security:read");
    const params = new URL(request.url).searchParams;
    const result = await getSOCQueue({ status: params.get("status")?.trim().toUpperCase() || undefined, severity: params.get("severity")?.trim().toUpperCase() || undefined });
    return Response.json({ incidents: result }, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) { return failure(error, context.correlationId); }
}

export async function handleIncidentDetail(request: Request, incidentId: string) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "security:read");
    const result = await getIncidentDetail(incidentId);
    return Response.json(result, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) { return failure(error, context.correlationId); }
}

/** Module 8 Phase B CreateIncident: the manual counterpart to a detection-rule-opened incident. */
export async function handleIncidentCreate(request: Request) {
  const context = await requestContext(request);
  const startedAt = Date.now();
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    requirePermission(user, "security:manage");
    await enforceRateLimits([{ key: `security-incident:actor:${user.userId}`, limit: 30, windowSeconds: 300 }, { key: "security-incident:global", limit: 500, windowSeconds: 300 }]);
    const idempotencyKey = request.headers.get("idempotency-key") ?? "";
    const payload = await readBoundedJson<never>(request, 4_096);
    const result = await createIncident(user, payload, idempotencyKey, context.correlationId);
    emitStructuredSecurityLog({ level: "INFO", event: "CREATE_SECURITY_INCIDENT", correlationId: context.correlationId, actorId, outcome: "SUCCESS", durationMs: Date.now() - startedAt });
    return Response.json(result, { status: 201, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) await recordSecurityEvent({ eventType: "AUTHORISATION_DENIED", severity: "HIGH", actorId, context, action: "CREATE_SECURITY_INCIDENT", outcome: "DENIED", details: { status: error.status } }).catch(() => undefined);
    return failure(error, context.correlationId);
  }
}

/** Module 8 Phase B Contain: triage bookkeeping, OPEN to CONTAINED. */
export async function handleIncidentContainment(request: Request, incidentId: string) {
  const context = await requestContext(request);
  const startedAt = Date.now();
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    requirePermission(user, "security:manage");
    await enforceRateLimits([{ key: `security-contain:actor:${user.userId}`, limit: 30, windowSeconds: 300 }, { key: "security-contain:global", limit: 500, windowSeconds: 300 }]);
    const idempotencyKey = request.headers.get("idempotency-key") ?? "";
    const payload = await readBoundedJson<never>(request, 4_096);
    const result = await containIncident(incidentId, user, payload, idempotencyKey, context.correlationId);
    emitStructuredSecurityLog({ level: "INFO", event: "CONTAIN_SECURITY_INCIDENT", correlationId: context.correlationId, actorId, outcome: "SUCCESS", durationMs: Date.now() - startedAt });
    return Response.json(result, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) await recordSecurityEvent({ eventType: "AUTHORISATION_DENIED", severity: "HIGH", actorId, context, action: "CONTAIN_SECURITY_INCIDENT", outcome: "DENIED", details: { status: error.status } }).catch(() => undefined);
    return failure(error, context.correlationId);
  }
}

/** Module 8 Phase B Revoke: the real technical action — revokes the subject user's active identity_links. Always step-up gated, the same posture Module 1's own identity-link revocation already established. */
export async function handleIncidentRevocation(request: Request, incidentId: string) {
  const context = await requestContext(request);
  const startedAt = Date.now();
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    requirePermission(user, "security:manage");
    await requireStepUp(request, user);
    await enforceRateLimits([{ key: `security-revoke:actor:${user.userId}`, limit: 30, windowSeconds: 300 }, { key: "security-revoke:global", limit: 500, windowSeconds: 300 }]);
    const idempotencyKey = request.headers.get("idempotency-key") ?? "";
    const payload = await readBoundedJson<never>(request, 4_096);
    const result = await revokeIncidentAccess(incidentId, user, payload, idempotencyKey, context.correlationId);
    emitStructuredSecurityLog({ level: "INFO", event: "REVOKE_SECURITY_INCIDENT_ACCESS", correlationId: context.correlationId, actorId, outcome: "SUCCESS", durationMs: Date.now() - startedAt });
    return Response.json(result, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) await recordSecurityEvent({ eventType: "AUTHORISATION_DENIED", severity: "HIGH", actorId, context, action: "REVOKE_SECURITY_INCIDENT_ACCESS", outcome: "DENIED", details: { status: error.status } }).catch(() => undefined);
    return failure(error, context.correlationId);
  }
}

/** Module 8 Phase B Close: terminal, reachable from OPEN or CONTAINED. */
export async function handleIncidentClosure(request: Request, incidentId: string) {
  const context = await requestContext(request);
  const startedAt = Date.now();
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    requirePermission(user, "security:manage");
    await enforceRateLimits([{ key: `security-close:actor:${user.userId}`, limit: 30, windowSeconds: 300 }, { key: "security-close:global", limit: 500, windowSeconds: 300 }]);
    const idempotencyKey = request.headers.get("idempotency-key") ?? "";
    const payload = await readBoundedJson<never>(request, 4_096);
    const result = await closeIncident(incidentId, user, payload, idempotencyKey, context.correlationId);
    emitStructuredSecurityLog({ level: "INFO", event: "CLOSE_SECURITY_INCIDENT", correlationId: context.correlationId, actorId, outcome: "SUCCESS", durationMs: Date.now() - startedAt });
    return Response.json(result, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) await recordSecurityEvent({ eventType: "AUTHORISATION_DENIED", severity: "HIGH", actorId, context, action: "CLOSE_SECURITY_INCIDENT", outcome: "DENIED", details: { status: error.status } }).catch(() => undefined);
    return failure(error, context.correlationId);
  }
}
