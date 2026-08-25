import { AccessDeniedError, getCurrentUser, requirePermission } from "@/lib/auth";
import {
  ComplianceResourceError,
  createObligation,
  fileDispute,
  getComplianceSnapshot,
  markObligationSatisfied,
  openAuditCase,
  requestRefund,
  reviewRefund,
} from "@/lib/data/compliance-repository";
import { RepositoryConflictError } from "@/lib/data/repository";
import { ComplianceValidationError } from "@/lib/domain/compliance";
import { emitStructuredSecurityLog, enforceRateLimits, readBoundedJson, recordSecurityEvent, requestContext, RequestGuardError } from "@/lib/security/request";

export type ComplianceCommand = "OPEN_AUDIT_CASE" | "FILE_DISPUTE" | "REQUEST_REFUND" | "REVIEW_REFUND" | "CREATE_OBLIGATION" | "MARK_OBLIGATION_SATISFIED";

function problem(status: number, code: string, title: string, detail: string, correlationId: string, errors?: unknown, retryAfter?: number | null) {
  return Response.json({ type: `https://vat-msa.local/problems/${code.toLowerCase().replaceAll("_", "-")}`, title, status, code, detail, correlationId, ...(errors ? { errors } : {}) }, { status, headers: { "content-type": "application/problem+json", "x-correlation-id": correlationId, "cache-control": "no-store", ...(retryAfter ? { "retry-after": String(retryAfter) } : {}) } });
}

export async function handleComplianceList(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "compliance:read");
    return Response.json(await getComplianceSnapshot(user), { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    return problem(500, "INTERNAL_ERROR", "Internal error", "The compliance workspace is temporarily unavailable.", context.correlationId);
  }
}

export async function handleComplianceCommand(request: Request, permission: string, command: ComplianceCommand, resourceId?: string) {
  const context = await requestContext(request);
  const startedAt = Date.now();
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    requirePermission(user, permission);
    await enforceRateLimits([
      { key: `compliance:${command}:actor:${user.userId}`, limit: 30, windowSeconds: 60 },
      { key: `compliance:${command}:scope:${user.taxpayerId ?? user.role}`, limit: 120, windowSeconds: 60 },
      { key: `compliance:${command}:global`, limit: 1_000, windowSeconds: 60 },
    ]);
    const payload = await readBoundedJson<never>(request, 131_072);
    const key = request.headers.get("idempotency-key") ?? "";
    let result: Record<string, unknown> | null;
    if (command === "OPEN_AUDIT_CASE") result = await openAuditCase(payload, user, key, context.correlationId) as Record<string, unknown> | null;
    else if (command === "FILE_DISPUTE") result = await fileDispute(payload, user, key, context.correlationId) as Record<string, unknown> | null;
    else if (command === "REQUEST_REFUND") result = await requestRefund(payload, user, key, context.correlationId) as Record<string, unknown> | null;
    else if (command === "REVIEW_REFUND") {
      if (!resourceId) throw new ComplianceResourceError("Refund claim id is required.", 400);
      result = await reviewRefund(resourceId, payload, user, key, context.correlationId) as Record<string, unknown> | null;
    } else if (command === "CREATE_OBLIGATION") result = await createObligation(payload, user, key, context.correlationId) as Record<string, unknown> | null;
    else {
      if (!resourceId) throw new ComplianceResourceError("Tax obligation id is required.", 400);
      result = await markObligationSatisfied(resourceId, payload, user, key, context.correlationId) as Record<string, unknown> | null;
    }
    if (!result) throw new RepositoryConflictError("The idempotent compliance resource is no longer available.");
    emitStructuredSecurityLog({ level: "INFO", event: command, correlationId: context.correlationId, actorId, outcome: "SUCCESS", durationMs: Date.now() - startedAt });
    const status = command === "REVIEW_REFUND" || command === "MARK_OBLIGATION_SATISFIED" ? 200 : 201;
    return Response.json({ resource: result }, { status, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    emitStructuredSecurityLog({ level: error instanceof AccessDeniedError || error instanceof RequestGuardError ? "WARN" : "ERROR", event: command, correlationId: context.correlationId, actorId, outcome: error instanceof Error ? error.name : "FAILED", durationMs: Date.now() - startedAt });
    if (error instanceof RequestGuardError) return problem(error.status, error.code, error.status === 429 ? "Rate limited" : "Bad request", error.message, context.correlationId, undefined, error.retryAfter);
    if (error instanceof ComplianceValidationError) return problem(422, "VALIDATION_FAILED", "Validation failed", error.message, context.correlationId, error.messages.map((item) => ({ ...item, severity: "ERROR" })));
    if (error instanceof ComplianceResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, context.correlationId);
    if (error instanceof RepositoryConflictError) return problem(409, "COMPLIANCE_CONFLICT", "Conflict", error.message, context.correlationId);
    if (error instanceof AccessDeniedError) {
      await recordSecurityEvent({ eventType: "AUTHORISATION_DENIED", severity: "HIGH", actorId, context, action: command, outcome: "DENIED", details: { status: error.status } }).catch(() => undefined);
      return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    }
    return problem(500, "INTERNAL_ERROR", "Internal error", "The compliance command could not be completed.", context.correlationId);
  }
}
