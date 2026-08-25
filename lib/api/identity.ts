import { AccessDeniedError } from "@/lib/auth";
import { RepositoryConflictError } from "@/lib/data/repository";
import { IdentityValidationError } from "@/lib/domain/identity";
import { RequestGuardError, type RequestContext } from "@/lib/security/request";

/**
 * Shared problem+json/response helpers for the identity domain (Module 1:
 * registrations, organisations, memberships). Extracted from the inline
 * `problem()` that previously lived only in the registration-applications
 * route so new identity routes (registration decisions, membership
 * assignment) don't re-duplicate it.
 */
export function identityProblem(error: unknown, context: RequestContext): Response {
  let status = 500;
  let code = "INTERNAL_ERROR";
  let detail = "The identity operation could not be completed.";
  let errors: unknown;
  if (error instanceof AccessDeniedError) {
    status = error.status;
    code = status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED";
    detail = error.message;
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
