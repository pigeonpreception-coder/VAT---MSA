import { AccessDeniedError, getCurrentUser, requirePermission } from "@/lib/auth";
import {
  createVatAdjustment,
  decideVatApproval,
  generateVatReturn,
  getVatLifecycleSnapshot,
  getVatReturnDetail,
  requestReturnApproval,
  submitVatReturn,
  VatLifecycleResourceError,
} from "@/lib/data/vat-lifecycle-repository";
import { RepositoryConflictError } from "@/lib/data/repository";
import { VatLifecycleValidationError } from "@/lib/domain/vat-lifecycle";
import { emitStructuredSecurityLog, enforceRateLimits, readBoundedJson, recordSecurityEvent, requestContext, RequestGuardError } from "@/lib/security/request";

export type VatCommand = "CREATE_ADJUSTMENT" | "GENERATE_RETURN" | "REQUEST_RETURN_APPROVAL" | "DECIDE_APPROVAL" | "SUBMIT_RETURN";

function problem(status: number, code: string, title: string, detail: string, correlationId: string, errors?: unknown, retryAfter?: number | null) {
  return Response.json({ type: `https://vat-msa.local/problems/${code.toLowerCase().replaceAll("_", "-")}`, title, status, code, detail, correlationId, ...(errors ? { errors } : {}) }, {
    status,
    headers: { "content-type": "application/problem+json", "x-correlation-id": correlationId, "cache-control": "no-store", ...(retryAfter ? { "retry-after": String(retryAfter) } : {}) },
  });
}

export async function handleVatLifecycleList(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "returns:read");
    return Response.json(await getVatLifecycleSnapshot(user), { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    return problem(500, "INTERNAL_ERROR", "Internal error", "The VAT lifecycle is temporarily unavailable.", context.correlationId);
  }
}

export async function handleVatReturnDetail(request: Request, versionId: string) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "returns:read");
    return Response.json(await getVatReturnDetail(versionId, user), { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof VatLifecycleResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, context.correlationId);
    if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    return problem(500, "INTERNAL_ERROR", "Internal error", "The VAT return detail is temporarily unavailable.", context.correlationId);
  }
}

export async function handleVatCommand(request: Request, permission: string, command: VatCommand, resourceId: string) {
  const context = await requestContext(request);
  const startedAt = Date.now();
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    requirePermission(user, permission);
    await enforceRateLimits([
      { key: `vat:${command}:actor:${user.userId}`, limit: 30, windowSeconds: 60 },
      { key: `vat:${command}:tenant:${user.taxpayerId ?? user.role}`, limit: 120, windowSeconds: 60 },
      { key: `vat:${command}:global`, limit: 1_000, windowSeconds: 60 },
    ]);
    const key = request.headers.get("idempotency-key") ?? "";
    let result: Record<string, unknown> | null;
    if (command === "CREATE_ADJUSTMENT") result = await createVatAdjustment(resourceId, await readBoundedJson<never>(request, 65_536), user, key, context.correlationId) as Record<string, unknown> | null;
    else if (command === "GENERATE_RETURN") result = await generateVatReturn(resourceId, user, key, context.correlationId) as Record<string, unknown> | null;
    else if (command === "REQUEST_RETURN_APPROVAL") result = await requestReturnApproval(resourceId, user, key, context.correlationId) as Record<string, unknown> | null;
    else if (command === "DECIDE_APPROVAL") result = await decideVatApproval(resourceId, await readBoundedJson<never>(request, 16_384), user, key, context.correlationId) as Record<string, unknown> | null;
    else result = await submitVatReturn(resourceId, user, key, context.correlationId) as Record<string, unknown> | null;
    if (!result) throw new RepositoryConflictError("The idempotent VAT lifecycle resource is no longer available.");
    emitStructuredSecurityLog({ level: "INFO", event: command, correlationId: context.correlationId, actorId, outcome: "SUCCESS", durationMs: Date.now() - startedAt });
    const status = command === "DECIDE_APPROVAL" ? 200 : command === "REQUEST_RETURN_APPROVAL" || command === "SUBMIT_RETURN" ? 202 : 201;
    return Response.json({ resource: result }, { status, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    emitStructuredSecurityLog({ level: error instanceof AccessDeniedError || error instanceof RequestGuardError ? "WARN" : "ERROR", event: command, correlationId: context.correlationId, actorId, outcome: error instanceof Error ? error.name : "FAILED", durationMs: Date.now() - startedAt });
    if (error instanceof RequestGuardError) {
      // Security fix 2026-08-27 (SECURITY_GAP_ASSESSMENT.md item #4): this branch returned without ever recording RATE_LIMIT_ABUSE's input event, even though actorId was already known here.
      if ([413, 429].includes(error.status)) {
        await recordSecurityEvent({ eventType: error.code, severity: error.status === 429 ? "MEDIUM" : "LOW", actorId, context, action: command, outcome: "REJECTED", details: { status: error.status } }).catch(() => undefined);
      }
      return problem(error.status, error.code, error.status === 429 ? "Rate limited" : "Bad request", error.message, context.correlationId, undefined, error.retryAfter);
    }
    if (error instanceof VatLifecycleValidationError) return problem(422, "VALIDATION_FAILED", "Validation failed", error.message, context.correlationId, error.messages.map((item) => ({ ...item, severity: "ERROR" })));
    if (error instanceof VatLifecycleResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, context.correlationId);
    if (error instanceof RepositoryConflictError) return problem(409, "VAT_LIFECYCLE_CONFLICT", "Conflict", error.message, context.correlationId);
    if (error instanceof AccessDeniedError) {
      await recordSecurityEvent({ eventType: "AUTHORISATION_DENIED", severity: "HIGH", actorId, context, action: command, outcome: "DENIED", details: { status: error.status } }).catch(() => undefined);
      return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    }
    return problem(500, "INTERNAL_ERROR", "Internal error", "The VAT lifecycle command could not be completed.", context.correlationId);
  }
}
