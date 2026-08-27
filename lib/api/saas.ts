import { AccessDeniedError, getCurrentUser, requirePermission } from "@/lib/auth";
import { getUsage, registerProvider, SaasResourceError, submitConformance } from "@/lib/data/saas-repository";
import { RepositoryConflictError } from "@/lib/data/repository";
import { SaasValidationError } from "@/lib/domain/saas";
import { emitStructuredSecurityLog, enforceRateLimits, readBoundedJson, recordAuthorizationDenial, recordSecurityEvent, requestContext, RequestGuardError } from "@/lib/security/request";

export type SaasCommand = "REGISTER_PROVIDER" | "SUBMIT_CONFORMANCE";

function problem(status: number, code: string, title: string, detail: string, correlationId: string, errors?: unknown, retryAfter?: number | null) {
  return Response.json({ type: `https://vat-msa.local/problems/${code.toLowerCase().replaceAll("_", "-")}`, title, status, code, detail, correlationId, ...(errors ? { errors } : {}) }, { status, headers: { "content-type": "application/problem+json", "x-correlation-id": correlationId, "cache-control": "no-store", ...(retryAfter ? { "retry-after": String(retryAfter) } : {}) } });
}

/** Module 10 Phase C: RegisterProvider/SubmitConformance, gated on `developer:manage` — same dispatch shape as handleIntegrationCommand/handlePaymentCommand. */
export async function handleSaasCommand(request: Request, command: SaasCommand, resourceId?: string) {
  const context = await requestContext(request);
  const startedAt = Date.now();
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    requirePermission(user, "developer:manage");
    await enforceRateLimits([
      { key: `saas:${command}:actor:${user.userId}`, limit: 30, windowSeconds: 60 },
      { key: `saas:${command}:global`, limit: 1_000, windowSeconds: 60 },
    ]);
    const payload = await readBoundedJson<never>(request, 65_536);
    const key = request.headers.get("idempotency-key") ?? "";
    let result: Record<string, unknown> | null;
    if (command === "REGISTER_PROVIDER") {
      result = await registerProvider(payload, user, key, context.correlationId) as Record<string, unknown> | null;
    } else {
      if (!resourceId) throw new SaasResourceError("SaaS application id is required.", 400);
      result = await submitConformance(resourceId, payload, user, key, context.correlationId) as Record<string, unknown> | null;
    }
    if (!result) throw new RepositoryConflictError("The idempotent SaaS resource is no longer available.");
    emitStructuredSecurityLog({ level: "INFO", event: command, correlationId: context.correlationId, actorId, outcome: "SUCCESS", durationMs: Date.now() - startedAt });
    return Response.json({ resource: result }, { status: 201, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    emitStructuredSecurityLog({ level: error instanceof AccessDeniedError || error instanceof RequestGuardError ? "WARN" : "ERROR", event: command, correlationId: context.correlationId, actorId, outcome: error instanceof Error ? error.name : "FAILED", durationMs: Date.now() - startedAt });
    if (error instanceof RequestGuardError) {
      if ([413, 429].includes(error.status)) {
        await recordSecurityEvent({ eventType: error.code, severity: error.status === 429 ? "MEDIUM" : "LOW", actorId, context, action: command, outcome: "REJECTED", details: { status: error.status } }).catch(() => undefined);
      }
      return problem(error.status, error.code, error.status === 429 ? "Rate limited" : "Bad request", error.message, context.correlationId, undefined, error.retryAfter);
    }
    if (error instanceof SaasValidationError) return problem(422, "VALIDATION_FAILED", "Validation failed", error.message, context.correlationId, error.messages.map((item) => ({ ...item, severity: "ERROR" })));
    if (error instanceof SaasResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, context.correlationId);
    if (error instanceof RepositoryConflictError) return problem(409, "SAAS_CONFLICT", "Conflict", error.message, context.correlationId);
    if (error instanceof AccessDeniedError) {
      await recordSecurityEvent({ eventType: "AUTHORISATION_DENIED", severity: "HIGH", actorId, context, action: command, outcome: "DENIED", details: { status: error.status } }).catch(() => undefined);
      return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    }
    return problem(500, "INTERNAL_ERROR", "Internal error", "The SaaS command could not be completed.", context.correlationId);
  }
}

/** GetUsage: read-only, gated on `developer:read` — a lighter permission than the mutating commands above. */
export async function handleSaasUsage(request: Request, resourceId: string) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "developer:read");
    const result = await getUsage(resourceId, user);
    return Response.json(result, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof SaasResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, context.correlationId);
    if (error instanceof AccessDeniedError) { await recordAuthorizationDenial(context, error.message, error.status); return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId); }
    return problem(500, "INTERNAL_ERROR", "Internal error", "SaaS provider usage is temporarily unavailable.", context.correlationId);
  }
}
