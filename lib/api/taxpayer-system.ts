import { AccessDeniedError, getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { RepositoryConflictError } from "@/lib/data/repository";
import {
  approveTaxpayerSystem,
  getTaxpayerSystem,
  listTaxpayerSystems,
  recordTaxpayerSystemSync,
  registerTaxpayerSystem,
  suspendTaxpayerSystem,
  TaxpayerSystemResourceError,
} from "@/lib/data/taxpayer-system-repository";
import { TaxpayerSystemValidationError } from "@/lib/domain/taxpayer-system";
import { emitStructuredSecurityLog, enforceRateLimits, readBoundedJson, recordAuthorizationDenial, recordSecurityEvent, requestContext, RequestGuardError } from "@/lib/security/request";

export type TaxpayerSystemCommand = "REGISTER_TAXPAYER_SYSTEM" | "APPROVE_TAXPAYER_SYSTEM" | "SUSPEND_TAXPAYER_SYSTEM" | "RECORD_TAXPAYER_SYSTEM_SYNC";

function problem(status: number, code: string, title: string, detail: string, correlationId: string, errors?: unknown, retryAfter?: number | null) {
  return Response.json({ type: `https://vat-msa.local/problems/${code.toLowerCase().replaceAll("_", "-")}`, title, status, code, detail, correlationId, ...(errors ? { errors } : {}) }, { status, headers: { "content-type": "application/problem+json", "x-correlation-id": correlationId, "cache-control": "no-store", ...(retryAfter ? { "retry-after": String(retryAfter) } : {}) } });
}

const PERMISSION_BY_COMMAND: Record<TaxpayerSystemCommand, string> = {
  REGISTER_TAXPAYER_SYSTEM: "taxpayer-systems:manage",
  APPROVE_TAXPAYER_SYSTEM: "taxpayer-systems:approve",
  SUSPEND_TAXPAYER_SYSTEM: "taxpayer-systems:manage",
  RECORD_TAXPAYER_SYSTEM_SYNC: "taxpayer-systems:manage",
};

/** NamRA e-VAT MS Registered Taxpayer Systems Framework: RegisterTaxpayerSystem/ApproveTaxpayerSystem/SuspendTaxpayerSystem/RecordTaxpayerSystemSync — same dispatch shape as handleIntegrationCommand. */
export async function handleTaxpayerSystemCommand(request: Request, command: TaxpayerSystemCommand, resourceId?: string) {
  const context = await requestContext(request);
  const startedAt = Date.now();
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    await requireLicensedPermission(user, PERMISSION_BY_COMMAND[command], { operationClass: command === "APPROVE_TAXPAYER_SYSTEM" ? "COMPLIANCE_WRITE" : "BUSINESS_WRITE" });
    await enforceRateLimits([
      { key: `taxpayer-systems:${command}:actor:${user.userId}`, limit: 30, windowSeconds: 60 },
      { key: `taxpayer-systems:${command}:global`, limit: 1_000, windowSeconds: 60 },
    ]);
    const payload = await readBoundedJson<never>(request, 131_072);
    const key = request.headers.get("idempotency-key") ?? "";
    let result: Record<string, unknown> | null;
    if (command === "REGISTER_TAXPAYER_SYSTEM") {
      result = await registerTaxpayerSystem(payload, user, key, context.correlationId) as Record<string, unknown> | null;
    } else if (command === "APPROVE_TAXPAYER_SYSTEM") {
      if (!resourceId) throw new TaxpayerSystemResourceError("Taxpayer system registration id is required.", 400);
      result = await approveTaxpayerSystem(resourceId, user, key, context.correlationId) as Record<string, unknown> | null;
    } else if (command === "SUSPEND_TAXPAYER_SYSTEM") {
      if (!resourceId) throw new TaxpayerSystemResourceError("Taxpayer system registration id is required.", 400);
      result = await suspendTaxpayerSystem(resourceId, payload, user, key, context.correlationId) as Record<string, unknown> | null;
    } else {
      if (!resourceId) throw new TaxpayerSystemResourceError("Taxpayer system registration id is required.", 400);
      result = await recordTaxpayerSystemSync(resourceId, payload, user, key, context.correlationId) as Record<string, unknown> | null;
    }
    if (!result) throw new RepositoryConflictError("The idempotent taxpayer system resource is no longer available.");
    emitStructuredSecurityLog({ level: "INFO", event: command, correlationId: context.correlationId, actorId, outcome: "SUCCESS", durationMs: Date.now() - startedAt });
    const status = command === "REGISTER_TAXPAYER_SYSTEM" ? 201 : 200;
    return Response.json({ resource: result }, { status, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    emitStructuredSecurityLog({ level: error instanceof AccessDeniedError || error instanceof RequestGuardError ? "WARN" : "ERROR", event: command, correlationId: context.correlationId, actorId, outcome: error instanceof Error ? error.name : "FAILED", durationMs: Date.now() - startedAt });
    if (error instanceof RequestGuardError) {
      if ([413, 429].includes(error.status)) {
        await recordSecurityEvent({ eventType: error.code, severity: error.status === 429 ? "MEDIUM" : "LOW", actorId, context, action: command, outcome: "REJECTED", details: { status: error.status } }).catch(() => undefined);
      }
      return problem(error.status, error.code, error.status === 429 ? "Rate limited" : "Bad request", error.message, context.correlationId, undefined, error.retryAfter);
    }
    if (error instanceof TaxpayerSystemValidationError) return problem(422, "VALIDATION_FAILED", "Validation failed", error.message, context.correlationId, error.messages.map((item) => ({ ...item, severity: "ERROR" })));
    if (error instanceof TaxpayerSystemResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, context.correlationId);
    if (error instanceof RepositoryConflictError) return problem(409, "TAXPAYER_SYSTEM_CONFLICT", "Conflict", error.message, context.correlationId);
    if (error instanceof AccessDeniedError) {
      await recordSecurityEvent({ eventType: "AUTHORISATION_DENIED", severity: "HIGH", actorId, context, action: command, outcome: "DENIED", details: { status: error.status } }).catch(() => undefined);
      return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    }
    return problem(500, "INTERNAL_ERROR", "Internal error", "The taxpayer system command could not be completed.", context.correlationId);
  }
}

/** ListTaxpayerSystems: read-only, gated on `taxpayer-systems:read`. */
export async function handleTaxpayerSystemList(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    await requireLicensedPermission(user, "taxpayer-systems:read", { operationClass: "READ" });
    const result = await listTaxpayerSystems(user);
    return Response.json({ resources: result }, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) { await recordAuthorizationDenial(context, error.message, error.status); return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId); }
    return problem(500, "INTERNAL_ERROR", "Internal error", "Taxpayer systems are temporarily unavailable.", context.correlationId);
  }
}

/** GetTaxpayerSystem: read-only, gated on `taxpayer-systems:read`. */
export async function handleTaxpayerSystemGet(request: Request, resourceId: string) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    await requireLicensedPermission(user, "taxpayer-systems:read", { operationClass: "READ" });
    const result = await getTaxpayerSystem(resourceId, user);
    return Response.json({ resource: result }, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof TaxpayerSystemResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, context.correlationId);
    if (error instanceof AccessDeniedError) { await recordAuthorizationDenial(context, error.message, error.status); return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId); }
    return problem(500, "INTERNAL_ERROR", "Internal error", "Taxpayer system registration is temporarily unavailable.", context.correlationId);
  }
}
