import { AccessDeniedError, getCurrentUser, requirePermission } from "@/lib/auth";
import { AuditResourceError, listAuditChainVerifications, runAuditChainVerification, searchAuditTrail } from "@/lib/data/audit-repository";
import { RepositoryConflictError } from "@/lib/data/repository";
import { emitStructuredSecurityLog, enforceRateLimits, recordSecurityEvent, requestContext, RequestGuardError } from "@/lib/security/request";

function problem(status: number, code: string, title: string, detail: string, correlationId: string) {
  return Response.json({ type: `https://vat-msa.local/problems/${code.toLowerCase().replaceAll("_", "-")}`, title, status, code, detail, correlationId }, { status, headers: { "content-type": "application/problem+json", "x-correlation-id": correlationId, "cache-control": "no-store" } });
}

function failure(error: unknown, correlationId: string) {
  if (error instanceof AuditResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, correlationId);
  if (error instanceof RepositoryConflictError) return problem(409, "AUDIT_CONFLICT", "Conflict", error.message, correlationId);
  if (error instanceof RequestGuardError) return problem(error.status, error.code, "Bad request", error.message, correlationId);
  if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, correlationId);
  return problem(500, "INTERNAL_ERROR", "Internal error", "The audit operation could not be completed.", correlationId);
}

/** Module 8 Phase D GetAuditTrail: a filterable, paginated, restricted read for Internal Audit — the REST counterpart to app/audit/page.tsx. */
export async function handleAuditTrailSearch(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "audit:read");
    const params = new URL(request.url).searchParams;
    const result = await searchAuditTrail({
      resourceType: params.get("resource_type")?.trim().toUpperCase() || undefined,
      resourceId: params.get("resource_id")?.trim() || undefined,
      action: params.get("action")?.trim().toUpperCase() || undefined,
      actorId: params.get("actor_id")?.trim() || undefined,
      limit: params.get("limit") ? Number(params.get("limit")) : undefined,
      offset: params.get("offset") ? Number(params.get("offset")) : undefined,
    });
    return Response.json(result, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) { return failure(error, context.correlationId); }
}

/** Module 8 Phase D: past chain-verification runs. */
export async function handleAuditChainVerificationList(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "audit:read");
    const limitParam = new URL(request.url).searchParams.get("limit");
    const result = await listAuditChainVerifications(limitParam ? Number(limitParam) : undefined);
    return Response.json({ verifications: result }, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) { return failure(error, context.correlationId); }
}

/** Module 8 Phase D VerifyAuditChain: on-demand (no cron infrastructure exists), persists its own result, opens a CRITICAL incident on a detected break. */
export async function handleAuditChainVerificationTrigger(request: Request) {
  const context = await requestContext(request);
  const startedAt = Date.now();
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    requirePermission(user, "audit:read");
    await enforceRateLimits([{ key: `audit-chain-verify:actor:${user.userId}`, limit: 10, windowSeconds: 300 }, { key: "audit-chain-verify:global", limit: 100, windowSeconds: 300 }]);
    const result = await runAuditChainVerification(user, context.correlationId);
    emitStructuredSecurityLog({ level: result.status === "PASSED" ? "INFO" : "ERROR", event: "VERIFY_AUDIT_CHAIN", correlationId: context.correlationId, actorId, outcome: result.status, durationMs: Date.now() - startedAt });
    return Response.json({ verification: result }, { status: 201, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) await recordSecurityEvent({ eventType: "AUTHORISATION_DENIED", severity: "HIGH", actorId, context, action: "VERIFY_AUDIT_CHAIN", outcome: "DENIED", details: { status: error.status } }).catch(() => undefined);
    return failure(error, context.correlationId);
  }
}
