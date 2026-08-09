import { AccessDeniedError, getCurrentUser, requirePermission } from "@/lib/auth";
import { listRegistrationApplications, submitRegistrationApplication } from "@/lib/data/identity-repository";
import { RepositoryConflictError } from "@/lib/data/repository";
import { IdentityValidationError, type RegistrationSubmission } from "@/lib/domain/identity";
import { emitStructuredSecurityLog, enforceRegistrationRateLimits, readBoundedJson, recordSecurityEvent, requestContext, RequestGuardError } from "@/lib/security/request";

function problem(status: number, code: string, title: string, detail: string, correlationId: string, errors?: unknown, retryAfter?: number | null) {
  return Response.json({
    type: `https://vat-msa.local/problems/${code.toLowerCase().replaceAll("_", "-")}`,
    title,
    status,
    code,
    detail,
    correlationId,
    ...(errors ? { errors } : {}),
  }, { status, headers: { "content-type": "application/problem+json", "x-correlation-id": correlationId, "cache-control": "no-store", ...(retryAfter ? { "retry-after": String(retryAfter) } : {}) } });
}

export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "registrations:read");
    return Response.json({ registrations: await listRegistrationApplications(user) }, {
      headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" },
    });
  } catch (error) {
    if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    return problem(500, "INTERNAL_ERROR", "Internal error", "The registration list is temporarily unavailable.", context.correlationId);
  }
}

export async function POST(request: Request) {
  const startedAt = Date.now();
  const context = await requestContext(request);
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    requirePermission(user, "registrations:submit");
    await enforceRegistrationRateLimits(user, context);
    const payload = await readBoundedJson<RegistrationSubmission>(request, 32_768);
    const idempotencyKey = request.headers.get("idempotency-key") ?? "";
    const registration = await submitRegistrationApplication(payload, user, idempotencyKey, context.correlationId);
    emitStructuredSecurityLog({ level: "INFO", event: "TAXPAYER_REGISTRATION", correlationId: context.correlationId, actorId, outcome: "PENDING_VERIFICATION", durationMs: Date.now() - startedAt });
    return Response.json({
      registration_id: registration.id,
      status: registration.status,
      verification_source: registration.verification_source,
      verification_status: registration.verification_status,
      submitted_at: registration.submitted_at,
      next_action: "Await authoritative ITAS/NamRA verification. No taxpayer or organisation is created until verification and approval complete.",
    }, { status: 202, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store", location: `/api/v1/registration-applications` } });
  } catch (error) {
    emitStructuredSecurityLog({ level: error instanceof AccessDeniedError || error instanceof RequestGuardError ? "WARN" : "ERROR", event: "TAXPAYER_REGISTRATION", correlationId: context.correlationId, actorId, outcome: error instanceof Error ? error.name : "FAILED", durationMs: Date.now() - startedAt });
    if (error instanceof RequestGuardError) {
      if ([413, 429].includes(error.status)) {
        await recordSecurityEvent({ eventType: error.code, severity: error.status === 429 ? "MEDIUM" : "LOW", actorId, context, action: "TAXPAYER_REGISTRATION", outcome: "REJECTED", details: { status: error.status } }).catch(() => undefined);
      }
      return problem(error.status, error.code, error.status === 429 ? "Rate limited" : "Bad request", error.message, context.correlationId, undefined, error.retryAfter);
    }
    if (error instanceof IdentityValidationError) return problem(422, "VALIDATION_FAILED", "Validation failed", error.message, context.correlationId, error.messages.map((item) => ({ ...item, severity: "ERROR" })));
    if (error instanceof RepositoryConflictError) return problem(409, "REGISTRATION_CONFLICT", "Conflict", error.message, context.correlationId);
    if (error instanceof AccessDeniedError) {
      await recordSecurityEvent({ eventType: "AUTHORISATION_DENIED", severity: "HIGH", actorId, context, action: "TAXPAYER_REGISTRATION", outcome: "DENIED", details: { status: error.status } }).catch(() => undefined);
      return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    }
    return problem(500, "INTERNAL_ERROR", "Internal error", "The registration application could not be accepted.", context.correlationId);
  }
}
