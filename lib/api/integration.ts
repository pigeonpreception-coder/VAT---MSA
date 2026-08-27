import { AccessDeniedError, getCurrentUser, requirePermission } from "@/lib/auth";
import { approveIntegration, getIntegrationHealth, IntegrationResourceError, registerIntegration, startSync, suspendIntegration } from "@/lib/data/integration-repository";
import { RepositoryConflictError } from "@/lib/data/repository";
import { IntegrationValidationError } from "@/lib/domain/integration";
import { emitStructuredSecurityLog, enforceRateLimits, readBoundedJson, recordAuthorizationDenial, recordSecurityEvent, requestContext, RequestGuardError } from "@/lib/security/request";

export type IntegrationCommand = "REGISTER_INTEGRATION" | "APPROVE_INTEGRATION" | "SUSPEND_INTEGRATION" | "START_SYNC";

function problem(status: number, code: string, title: string, detail: string, correlationId: string, errors?: unknown, retryAfter?: number | null) {
  return Response.json({ type: `https://vat-msa.local/problems/${code.toLowerCase().replaceAll("_", "-")}`, title, status, code, detail, correlationId, ...(errors ? { errors } : {}) }, { status, headers: { "content-type": "application/problem+json", "x-correlation-id": correlationId, "cache-control": "no-store", ...(retryAfter ? { "retry-after": String(retryAfter) } : {}) } });
}

/** Module 10 Phase A: RegisterIntegration/ApproveIntegration/SuspendIntegration/StartSync, all gated on `integrations:manage` — same dispatch shape as handleComplianceCommand/handlePaymentCommand. */
export async function handleIntegrationCommand(request: Request, command: IntegrationCommand, resourceId?: string) {
  const context = await requestContext(request);
  const startedAt = Date.now();
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    requirePermission(user, "integrations:manage");
    await enforceRateLimits([
      { key: `integrations:${command}:actor:${user.userId}`, limit: 30, windowSeconds: 60 },
      { key: `integrations:${command}:global`, limit: 1_000, windowSeconds: 60 },
    ]);
    const payload = await readBoundedJson<never>(request, 131_072);
    const key = request.headers.get("idempotency-key") ?? "";
    let result: Record<string, unknown> | null;
    if (command === "REGISTER_INTEGRATION") {
      result = await registerIntegration(payload, user, key, context.correlationId) as Record<string, unknown> | null;
    } else if (command === "APPROVE_INTEGRATION") {
      if (!resourceId) throw new IntegrationResourceError("Integration connection id is required.", 400);
      result = await approveIntegration(resourceId, user, key, context.correlationId) as Record<string, unknown> | null;
    } else if (command === "SUSPEND_INTEGRATION") {
      if (!resourceId) throw new IntegrationResourceError("Integration connection id is required.", 400);
      result = await suspendIntegration(resourceId, payload, user, key, context.correlationId) as Record<string, unknown> | null;
    } else {
      if (!resourceId) throw new IntegrationResourceError("Integration connection id is required.", 400);
      result = await startSync(resourceId, payload, user, key, context.correlationId) as Record<string, unknown> | null;
    }
    if (!result) throw new RepositoryConflictError("The idempotent integration resource is no longer available.");
    emitStructuredSecurityLog({ level: "INFO", event: command, correlationId: context.correlationId, actorId, outcome: "SUCCESS", durationMs: Date.now() - startedAt });
    const status = command === "REGISTER_INTEGRATION" || command === "START_SYNC" ? 201 : 200;
    return Response.json({ resource: result }, { status, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    emitStructuredSecurityLog({ level: error instanceof AccessDeniedError || error instanceof RequestGuardError ? "WARN" : "ERROR", event: command, correlationId: context.correlationId, actorId, outcome: error instanceof Error ? error.name : "FAILED", durationMs: Date.now() - startedAt });
    if (error instanceof RequestGuardError) {
      if ([413, 429].includes(error.status)) {
        await recordSecurityEvent({ eventType: error.code, severity: error.status === 429 ? "MEDIUM" : "LOW", actorId, context, action: command, outcome: "REJECTED", details: { status: error.status } }).catch(() => undefined);
      }
      return problem(error.status, error.code, error.status === 429 ? "Rate limited" : "Bad request", error.message, context.correlationId, undefined, error.retryAfter);
    }
    if (error instanceof IntegrationValidationError) return problem(422, "VALIDATION_FAILED", "Validation failed", error.message, context.correlationId, error.messages.map((item) => ({ ...item, severity: "ERROR" })));
    if (error instanceof IntegrationResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, context.correlationId);
    if (error instanceof RepositoryConflictError) return problem(409, "INTEGRATION_CONFLICT", "Conflict", error.message, context.correlationId);
    if (error instanceof AccessDeniedError) {
      await recordSecurityEvent({ eventType: "AUTHORISATION_DENIED", severity: "HIGH", actorId, context, action: command, outcome: "DENIED", details: { status: error.status } }).catch(() => undefined);
      return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    }
    return problem(500, "INTERNAL_ERROR", "Internal error", "The integration command could not be completed.", context.correlationId);
  }
}

/** GetHealth: read-only, gated on `integrations:read` — a lighter permission than the mutating commands above, held by every role that already sees the platform integration list. */
export async function handleIntegrationHealth(request: Request, resourceId: string) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "integrations:read");
    const result = await getIntegrationHealth(resourceId, user);
    return Response.json(result, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof IntegrationResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, context.correlationId);
    if (error instanceof AccessDeniedError) { await recordAuthorizationDenial(context, error.message, error.status); return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId); }
    return problem(500, "INTERNAL_ERROR", "Internal error", "Integration health is temporarily unavailable.", context.correlationId);
  }
}
