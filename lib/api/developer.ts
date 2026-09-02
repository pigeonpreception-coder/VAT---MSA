import { AccessDeniedError, getCurrentUser, requirePermission } from "@/lib/auth";
import { createClient, DeveloperResourceError, revokeCredential, rotateCredential, runConformance } from "@/lib/data/developer-repository";
import { RepositoryConflictError } from "@/lib/data/repository";
import { DeveloperValidationError } from "@/lib/domain/developer";
import { emitStructuredSecurityLog, enforceRateLimits, readBoundedJson, recordSecurityEvent, requestContext, RequestGuardError } from "@/lib/security/request";

export type DeveloperCommand = "CREATE_CLIENT" | "ROTATE_CREDENTIAL" | "REVOKE_CREDENTIAL" | "RUN_CONFORMANCE";

function problem(status: number, code: string, title: string, detail: string, correlationId: string, errors?: unknown, retryAfter?: number | null) {
  return Response.json({ type: `https://vat-msa.local/problems/${code.toLowerCase().replaceAll("_", "-")}`, title, status, code, detail, correlationId, ...(errors ? { errors } : {}) }, { status, headers: { "content-type": "application/problem+json", "x-correlation-id": correlationId, "cache-control": "no-store", ...(retryAfter ? { "retry-after": String(retryAfter) } : {}) } });
}

/** Module 10 Phase D: CreateClient/RotateCredential/RevokeCredential/RunConformance, all gated on `developer:manage` — same dispatch shape as handleIntegrationCommand/handleSaasCommand. */
export async function handleDeveloperCommand(request: Request, command: DeveloperCommand, resourceId?: string) {
  const context = await requestContext(request);
  const startedAt = Date.now();
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    requirePermission(user, "developer:manage");
    await enforceRateLimits([
      { key: `developer:${command}:actor:${user.userId}`, limit: 30, windowSeconds: 60 },
      { key: `developer:${command}:global`, limit: 1_000, windowSeconds: 60 },
    ]);
    const payload = await readBoundedJson<never>(request, 65_536);
    const key = request.headers.get("idempotency-key") ?? "";
    let result: Record<string, unknown> | null;
    if (command === "CREATE_CLIENT") {
      result = await createClient(payload, user, key, context.correlationId) as Record<string, unknown> | null;
    } else if (command === "ROTATE_CREDENTIAL") {
      if (!resourceId) throw new DeveloperResourceError("API client id is required.", 400);
      result = await rotateCredential(resourceId, user, key, context.correlationId) as Record<string, unknown> | null;
    } else if (command === "REVOKE_CREDENTIAL") {
      if (!resourceId) throw new DeveloperResourceError("API client id is required.", 400);
      result = await revokeCredential(resourceId, payload, user, key, context.correlationId) as Record<string, unknown> | null;
    } else {
      if (!resourceId) throw new DeveloperResourceError("API client id is required.", 400);
      result = await runConformance(resourceId, user, key, context.correlationId) as Record<string, unknown> | null;
    }
    if (!result) throw new RepositoryConflictError("The idempotent developer platform resource is no longer available.");
    emitStructuredSecurityLog({ level: "INFO", event: command, correlationId: context.correlationId, actorId, outcome: "SUCCESS", durationMs: Date.now() - startedAt });
    const status = command === "CREATE_CLIENT" || command === "RUN_CONFORMANCE" ? 201 : 200;
    return Response.json({ resource: result }, { status, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    emitStructuredSecurityLog({ level: error instanceof AccessDeniedError || error instanceof RequestGuardError ? "WARN" : "ERROR", event: command, correlationId: context.correlationId, actorId, outcome: error instanceof Error ? error.name : "FAILED", durationMs: Date.now() - startedAt });
    if (error instanceof RequestGuardError) {
      if ([413, 429].includes(error.status)) {
        await recordSecurityEvent({ eventType: error.code, severity: error.status === 429 ? "MEDIUM" : "LOW", actorId, context, action: command, outcome: "REJECTED", details: { status: error.status } }).catch(() => undefined);
      }
      return problem(error.status, error.code, error.status === 429 ? "Rate limited" : "Bad request", error.message, context.correlationId, undefined, error.retryAfter);
    }
    if (error instanceof DeveloperValidationError) return problem(422, "VALIDATION_FAILED", "Validation failed", error.message, context.correlationId, error.messages.map((item) => ({ ...item, severity: "ERROR" })));
    if (error instanceof DeveloperResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, context.correlationId);
    if (error instanceof RepositoryConflictError) return problem(409, "DEVELOPER_CONFLICT", "Conflict", error.message, context.correlationId);
    if (error instanceof AccessDeniedError) {
      await recordSecurityEvent({ eventType: "AUTHORISATION_DENIED", severity: "HIGH", actorId, context, action: command, outcome: "DENIED", details: { status: error.status } }).catch(() => undefined);
      return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    }
    return problem(500, "INTERNAL_ERROR", "Internal error", "The developer platform command could not be completed.", context.correlationId);
  }
}
