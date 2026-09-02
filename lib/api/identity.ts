import { AccessDeniedError } from "@/lib/auth";
import { RepositoryConflictError } from "@/lib/data/repository";
import { IdentityValidationError } from "@/lib/domain/identity";
import { recordAuthorizationDenial, recordRateLimitBreach, RequestGuardError, type RequestContext } from "@/lib/security/request";

/**
 * Shared problem+json/response helpers for the identity domain (Module 1:
 * registrations, organisations, memberships). Extracted from the inline
 * `problem()` that previously lived only in the registration-applications
 * route so new identity routes (registration decisions, membership
 * assignment) don't re-duplicate it.
 *
 * Security fix 2026-08-27 (SECURITY_GAP_ASSESSMENT.md item #4): this whole
 * route family emitted no `AUTHORISATION_DENIED`/`RATE_LIMIT_EXCEEDED`
 * security events at all, so Module 8's detection rules were structurally
 * blind to it. Now async so it can record both (best-effort — see
 * lib/security/request.ts's recordAuthorizationDenial/
 * recordRateLimitBreach) without every one of this family's ~15 routes
 * needing to thread an actor id or event-recording call through
 * themselves; every existing `return identityProblem(error, context);`
 * call site keeps working unchanged, since returning a promise from an
 * async route handler already awaits it.
 */
export async function identityProblem(error: unknown, context: RequestContext): Promise<Response> {
  let status = 500;
  let code = "INTERNAL_ERROR";
  let detail = "The identity operation could not be completed.";
  let errors: unknown;
  if (error instanceof AccessDeniedError) {
    status = error.status;
    code = status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED";
    detail = error.message;
    await recordAuthorizationDenial(context, error.message, status);
  } else if (error instanceof IdentityValidationError) {
    status = 422;
    code = "VALIDATION_FAILED";
    detail = error.message;
    errors = error.messages.map((item) => ({ ...item, severity: "ERROR" }));
  } else if (error instanceof RepositoryConflictError) {
    status = 409;
    code = "CONFLICT";
    detail = error.message;
  } else if (error instanceof RequestGuardError) {
    status = error.status;
    code = error.code;
    detail = error.message;
    await recordRateLimitBreach(context, error);
  }
  return Response.json({
    type: `https://vat-msa.local/problems/${code.toLowerCase().replaceAll("_", "-")}`,
    title: status === 401 ? "Unauthorized" : status === 403 ? "Forbidden" : status === 409 ? "Conflict" : status === 422 ? "Validation failed" : "Request failed",
    status,
    code,
    detail,
    correlationId: context.correlationId,
    ...(errors ? { errors } : {}),
  }, { status, headers: { "content-type": "application/problem+json", "cache-control": "no-store", "x-correlation-id": context.correlationId } });
}

export function identityJson(body: unknown, context: RequestContext, status = 200): Response {
  return Response.json(body, { status, headers: { "cache-control": "no-store", "x-correlation-id": context.correlationId } });
}
