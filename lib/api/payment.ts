import { AccessDeniedError, getCurrentUser, requirePermission } from "@/lib/auth";
import { allocateRefundPayment, getOutstandingRefunds, PaymentResourceError, recordRefundPayment } from "@/lib/data/payment-repository";
import { RepositoryConflictError } from "@/lib/data/repository";
import { PaymentValidationError } from "@/lib/domain/payment";
import { emitStructuredSecurityLog, enforceRateLimits, readBoundedJson, recordAuthorizationDenial, recordSecurityEvent, requestContext, RequestGuardError } from "@/lib/security/request";

export type PaymentCommand = "RECORD_PAYMENT" | "ALLOCATE_PAYMENT";

function problem(status: number, code: string, title: string, detail: string, correlationId: string, errors?: unknown, retryAfter?: number | null) {
  return Response.json({ type: `https://vat-msa.local/problems/${code.toLowerCase().replaceAll("_", "-")}`, title, status, code, detail, correlationId, ...(errors ? { errors } : {}) }, { status, headers: { "content-type": "application/problem+json", "x-correlation-id": correlationId, "cache-control": "no-store", ...(retryAfter ? { "retry-after": String(retryAfter) } : {}) } });
}

/**
 * Module 9 Phase D: RecordPayment/AllocatePayment, both officer-only and
 * gated on `payments:record` — same dispatch shape as
 * handleComplianceCommand (lib/api/compliance.ts), kept as a separate file
 * since Payment is its own catalogue domain (see the playbook's Module 9
 * "Domains" line), not a sub-resource of Compliance.
 */
export async function handlePaymentCommand(request: Request, command: PaymentCommand, resourceId: string) {
  const context = await requestContext(request);
  const startedAt = Date.now();
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    requirePermission(user, "payments:record");
    await enforceRateLimits([
      { key: `payments:${command}:actor:${user.userId}`, limit: 30, windowSeconds: 60 },
      { key: `payments:${command}:global`, limit: 1_000, windowSeconds: 60 },
    ]);
    const payload = await readBoundedJson<never>(request, 131_072);
    const key = request.headers.get("idempotency-key") ?? "";
    const result = command === "RECORD_PAYMENT"
      ? await recordRefundPayment(resourceId, payload, user, key, context.correlationId)
      : await allocateRefundPayment(resourceId, payload, user, key, context.correlationId);
    if (!result) throw new RepositoryConflictError("The idempotent payment resource is no longer available.");
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
    if (error instanceof PaymentValidationError) return problem(422, "VALIDATION_FAILED", "Validation failed", error.message, context.correlationId, error.messages.map((item) => ({ ...item, severity: "ERROR" })));
    if (error instanceof PaymentResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, context.correlationId);
    if (error instanceof RepositoryConflictError) return problem(409, "PAYMENT_CONFLICT", "Conflict", error.message, context.correlationId);
    if (error instanceof AccessDeniedError) {
      await recordSecurityEvent({ eventType: "AUTHORISATION_DENIED", severity: "HIGH", actorId, context, action: command, outcome: "DENIED", details: { status: error.status } }).catch(() => undefined);
      return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    }
    return problem(500, "INTERNAL_ERROR", "Internal error", "The payment command could not be completed.", context.correlationId);
  }
}

/** GetOutstanding: read-only, gated on `payments:read` (already held by NAMRA_COMPLIANCE_OFFICER/NAMRA_SUPERVISOR; extended to NAMRA_REFUND_OFFICER in this phase). National scope sees every outstanding claim; a taxpayer-scoped actor sees only their own. */
export async function handleOutstandingRefunds(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "payments:read");
    const result = await getOutstandingRefunds(user);
    return Response.json(result, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) { await recordAuthorizationDenial(context, error.message, error.status); return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId); }
    return problem(500, "INTERNAL_ERROR", "Internal error", "The refund payment queue is temporarily unavailable.", context.correlationId);
  }
}
