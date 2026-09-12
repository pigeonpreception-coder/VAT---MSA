import { AccessDeniedError, getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { RepositoryConflictError } from "@/lib/data/repository";
import {
  disposeFixedAsset,
  FixedAssetResourceError,
  flagFixedAssetMaintenance,
  getFixedAsset,
  listFixedAssets,
  recordFixedAssetValuation,
  registerFixedAsset,
  restoreFixedAsset,
} from "@/lib/data/fixed-asset-repository";
import { FixedAssetValidationError } from "@/lib/domain/fixed-asset";
import { emitStructuredSecurityLog, enforceRateLimits, readBoundedJson, recordAuthorizationDenial, recordSecurityEvent, requestContext, RequestGuardError } from "@/lib/security/request";

export type FixedAssetCommand = "REGISTER_FIXED_ASSET" | "RECORD_FIXED_ASSET_VALUATION" | "FLAG_MAINTENANCE_FIXED_ASSET" | "RESTORE_FIXED_ASSET" | "DISPOSE_FIXED_ASSET";

function problem(status: number, code: string, title: string, detail: string, correlationId: string, errors?: unknown, retryAfter?: number | null) {
  return Response.json({ type: `https://vat-msa.local/problems/${code.toLowerCase().replaceAll("_", "-")}`, title, status, code, detail, correlationId, ...(errors ? { errors } : {}) }, { status, headers: { "content-type": "application/problem+json", "x-correlation-id": correlationId, "cache-control": "no-store", ...(retryAfter ? { "retry-after": String(retryAfter) } : {}) } });
}

function organisationIdFrom(request: Request) {
  return new URL(request.url).searchParams.get("organisation_id");
}

/** RegisterFixedAsset/RecordFixedAssetValuation/FlagMaintenanceFixedAsset/RestoreFixedAsset/DisposeFixedAsset — same dispatch shape as handleTaxpayerSystemCommand. */
export async function handleFixedAssetCommand(request: Request, command: FixedAssetCommand, resourceId?: string) {
  const context = await requestContext(request);
  const startedAt = Date.now();
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    await requireLicensedPermission(user, "fixed-assets:manage", { operationClass: "BUSINESS_WRITE", requestedOrganisationId: organisationIdFrom(request) });
    await enforceRateLimits([
      { key: `fixed-assets:${command}:actor:${user.userId}`, limit: 30, windowSeconds: 60 },
      { key: `fixed-assets:${command}:global`, limit: 1_000, windowSeconds: 60 },
    ]);
    const payload = await readBoundedJson<never>(request, 65_536);
    const key = request.headers.get("idempotency-key") ?? "";
    let result: Record<string, unknown> | null;
    if (command === "REGISTER_FIXED_ASSET") {
      result = await registerFixedAsset(payload, user, key, context.correlationId, organisationIdFrom(request)) as Record<string, unknown> | null;
    } else {
      if (!resourceId) throw new FixedAssetResourceError("Fixed asset id is required.", 400);
      if (command === "RECORD_FIXED_ASSET_VALUATION") result = await recordFixedAssetValuation(resourceId, payload, user, key, context.correlationId) as Record<string, unknown> | null;
      else if (command === "FLAG_MAINTENANCE_FIXED_ASSET") result = await flagFixedAssetMaintenance(resourceId, user, key, context.correlationId) as Record<string, unknown> | null;
      else if (command === "RESTORE_FIXED_ASSET") result = await restoreFixedAsset(resourceId, user, key, context.correlationId) as Record<string, unknown> | null;
      else result = await disposeFixedAsset(resourceId, payload, user, key, context.correlationId) as Record<string, unknown> | null;
    }
    if (!result) throw new RepositoryConflictError("The idempotent fixed asset resource is no longer available.");
    emitStructuredSecurityLog({ level: "INFO", event: command, correlationId: context.correlationId, actorId, outcome: "SUCCESS", durationMs: Date.now() - startedAt });
    return Response.json({ resource: result }, { status: command === "REGISTER_FIXED_ASSET" ? 201 : 200, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    emitStructuredSecurityLog({ level: error instanceof AccessDeniedError || error instanceof RequestGuardError ? "WARN" : "ERROR", event: command, correlationId: context.correlationId, actorId, outcome: error instanceof Error ? error.name : "FAILED", durationMs: Date.now() - startedAt });
    if (error instanceof RequestGuardError) {
      if ([413, 429].includes(error.status)) {
        await recordSecurityEvent({ eventType: error.code, severity: error.status === 429 ? "MEDIUM" : "LOW", actorId, context, action: command, outcome: "REJECTED", details: { status: error.status } }).catch(() => undefined);
      }
      return problem(error.status, error.code, error.status === 429 ? "Rate limited" : "Bad request", error.message, context.correlationId, undefined, error.retryAfter);
    }
    if (error instanceof FixedAssetValidationError) return problem(422, "VALIDATION_FAILED", "Validation failed", error.message, context.correlationId, error.messages.map((item) => ({ ...item, severity: "ERROR" })));
    if (error instanceof FixedAssetResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, context.correlationId);
    if (error instanceof RepositoryConflictError) return problem(409, "FIXED_ASSET_CONFLICT", "Conflict", error.message, context.correlationId);
    if (error instanceof AccessDeniedError) {
      await recordSecurityEvent({ eventType: "AUTHORISATION_DENIED", severity: "HIGH", actorId, context, action: command, outcome: "DENIED", details: { status: error.status } }).catch(() => undefined);
      return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    }
    return problem(500, "INTERNAL_ERROR", "Internal error", "The fixed asset command could not be completed.", context.correlationId);
  }
}

/** ListFixedAssets: read-only, gated on `fixed-assets:read`. Optional ?asset_class=IMMOVABLE|MOVABLE filter serves the two separate Operations pages. */
export async function handleFixedAssetList(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    await requireLicensedPermission(user, "fixed-assets:read", { operationClass: "READ", requestedOrganisationId: organisationIdFrom(request) });
    const assetClass = new URL(request.url).searchParams.get("asset_class");
    const result = await listFixedAssets(user, assetClass, organisationIdFrom(request));
    return Response.json({ resources: result }, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) { await recordAuthorizationDenial(context, error.message, error.status); return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId); }
    return problem(500, "INTERNAL_ERROR", "Internal error", "Fixed assets are temporarily unavailable.", context.correlationId);
  }
}

/** GetFixedAsset: read-only, gated on `fixed-assets:read`. */
export async function handleFixedAssetGet(request: Request, resourceId: string) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    await requireLicensedPermission(user, "fixed-assets:read", { operationClass: "READ" });
    const result = await getFixedAsset(resourceId, user);
    return Response.json({ resource: result }, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof FixedAssetResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, context.correlationId);
    if (error instanceof AccessDeniedError) { await recordAuthorizationDenial(context, error.message, error.status); return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId); }
    return problem(500, "INTERNAL_ERROR", "Internal error", "Fixed asset is temporarily unavailable.", context.correlationId);
  }
}
