import { AccessDeniedError, getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { RepositoryConflictError } from "@/lib/data/repository";
import {
  cancelLogisticsDelivery,
  createLogisticsDelivery,
  deliverLogisticsDelivery,
  dispatchLogisticsDelivery,
  getLogisticsDelivery,
  listLogisticsDeliveries,
  LogisticsResourceError,
} from "@/lib/data/logistics-repository";
import { LogisticsValidationError } from "@/lib/domain/logistics";
import { emitStructuredSecurityLog, enforceRateLimits, readBoundedJson, recordAuthorizationDenial, recordSecurityEvent, requestContext, RequestGuardError } from "@/lib/security/request";

export type LogisticsCommand = "CREATE_LOGISTICS_DELIVERY" | "DISPATCH_LOGISTICS_DELIVERY" | "DELIVER_LOGISTICS_DELIVERY" | "CANCEL_LOGISTICS_DELIVERY";

function problem(status: number, code: string, title: string, detail: string, correlationId: string, errors?: unknown, retryAfter?: number | null) {
  return Response.json({ type: `https://vat-msa.local/problems/${code.toLowerCase().replaceAll("_", "-")}`, title, status, code, detail, correlationId, ...(errors ? { errors } : {}) }, { status, headers: { "content-type": "application/problem+json", "x-correlation-id": correlationId, "cache-control": "no-store", ...(retryAfter ? { "retry-after": String(retryAfter) } : {}) } });
}

function organisationIdFrom(request: Request) {
  return new URL(request.url).searchParams.get("organisation_id");
}

/** CreateDelivery/DispatchDelivery/DeliverDelivery/CancelDelivery — same dispatch shape as handleFixedAssetCommand. */
export async function handleLogisticsCommand(request: Request, command: LogisticsCommand, resourceId?: string) {
  const context = await requestContext(request);
  const startedAt = Date.now();
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    await requireLicensedPermission(user, "logistics:manage", { operationClass: "BUSINESS_WRITE", requestedOrganisationId: organisationIdFrom(request) });
    await enforceRateLimits([
      { key: `logistics:${command}:actor:${user.userId}`, limit: 30, windowSeconds: 60 },
      { key: `logistics:${command}:global`, limit: 1_000, windowSeconds: 60 },
    ]);
    const payload = await readBoundedJson<never>(request, 32_768);
    const key = request.headers.get("idempotency-key") ?? "";
    let result: Record<string, unknown> | null;
    if (command === "CREATE_LOGISTICS_DELIVERY") {
      result = await createLogisticsDelivery(payload, user, key, context.correlationId, organisationIdFrom(request)) as Record<string, unknown> | null;
    } else {
      if (!resourceId) throw new LogisticsResourceError("Logistics delivery id is required.", 400);
      if (command === "DISPATCH_LOGISTICS_DELIVERY") result = await dispatchLogisticsDelivery(resourceId, user, key, context.correlationId) as Record<string, unknown> | null;
      else if (command === "DELIVER_LOGISTICS_DELIVERY") result = await deliverLogisticsDelivery(resourceId, user, key, context.correlationId) as Record<string, unknown> | null;
      else result = await cancelLogisticsDelivery(resourceId, payload, user, key, context.correlationId) as Record<string, unknown> | null;
    }
    if (!result) throw new RepositoryConflictError("The idempotent logistics resource is no longer available.");
    emitStructuredSecurityLog({ level: "INFO", event: command, correlationId: context.correlationId, actorId, outcome: "SUCCESS", durationMs: Date.now() - startedAt });
    return Response.json({ resource: result }, { status: command === "CREATE_LOGISTICS_DELIVERY" ? 201 : 200, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    emitStructuredSecurityLog({ level: error instanceof AccessDeniedError || error instanceof RequestGuardError ? "WARN" : "ERROR", event: command, correlationId: context.correlationId, actorId, outcome: error instanceof Error ? error.name : "FAILED", durationMs: Date.now() - startedAt });
    if (error instanceof RequestGuardError) {
      if ([413, 429].includes(error.status)) {
        await recordSecurityEvent({ eventType: error.code, severity: error.status === 429 ? "MEDIUM" : "LOW", actorId, context, action: command, outcome: "REJECTED", details: { status: error.status } }).catch(() => undefined);
      }
      return problem(error.status, error.code, error.status === 429 ? "Rate limited" : "Bad request", error.message, context.correlationId, undefined, error.retryAfter);
    }
    if (error instanceof LogisticsValidationError) return problem(422, "VALIDATION_FAILED", "Validation failed", error.message, context.correlationId, error.messages.map((item) => ({ ...item, severity: "ERROR" })));
    if (error instanceof LogisticsResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, context.correlationId);
    if (error instanceof RepositoryConflictError) return problem(409, "LOGISTICS_CONFLICT", "Conflict", error.message, context.correlationId);
    if (error instanceof AccessDeniedError) {
      await recordSecurityEvent({ eventType: "AUTHORISATION_DENIED", severity: "HIGH", actorId, context, action: command, outcome: "DENIED", details: { status: error.status } }).catch(() => undefined);
      return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    }
    return problem(500, "INTERNAL_ERROR", "Internal error", "The logistics command could not be completed.", context.correlationId);
  }
}

/** ListLogisticsDeliveries: read-only, gated on `logistics:read`. */
export async function handleLogisticsList(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    await requireLicensedPermission(user, "logistics:read", { operationClass: "READ", requestedOrganisationId: organisationIdFrom(request) });
    const result = await listLogisticsDeliveries(user, organisationIdFrom(request));
    return Response.json({ resources: result }, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) { await recordAuthorizationDenial(context, error.message, error.status); return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId); }
    return problem(500, "INTERNAL_ERROR", "Internal error", "Logistics deliveries are temporarily unavailable.", context.correlationId);
  }
}

/** GetLogisticsDelivery: read-only, gated on `logistics:read`. */
export async function handleLogisticsGet(request: Request, resourceId: string) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    await requireLicensedPermission(user, "logistics:read", { operationClass: "READ" });
    const result = await getLogisticsDelivery(resourceId, user);
    return Response.json({ resource: result }, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof LogisticsResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, context.correlationId);
    if (error instanceof AccessDeniedError) { await recordAuthorizationDenial(context, error.message, error.status); return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId); }
    return problem(500, "INTERNAL_ERROR", "Internal error", "Logistics delivery is temporarily unavailable.", context.correlationId);
  }
}
