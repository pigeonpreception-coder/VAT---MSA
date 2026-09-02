import { AccessDeniedError } from "@/lib/auth";
import { RepositoryConflictError } from "@/lib/data/repository";
import { ControlPlaneValidationError } from "@/lib/domain/control-plane";
import { recordAuthorizationDenial, recordRateLimitBreach, RequestGuardError, type RequestContext } from "@/lib/security/request";

export function organisationIdFrom(request: Request): string | null {
  return new URL(request.url).searchParams.get("organisation_id");
}

/** Security fix 2026-08-27 (SECURITY_GAP_ASSESSMENT.md item #4): see lib/api/identity.ts's identityProblem for why this is now async and what it records — this entire control-plane route family (workflows, roles, memberships, access requests/reviews, licensing, employees, offboarding, capabilities, navigation) previously emitted no security events at all. */
export async function controlPlaneProblem(error: unknown, context: RequestContext): Promise<Response> {
  let status = 500;
  let code = "INTERNAL_ERROR";
  let detail = "The control-plane operation could not be completed.";
  if (error instanceof AccessDeniedError) {
    status = error.status;
    code = status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED";
    detail = error.message;
    await recordAuthorizationDenial(context, error.message, status);
  } else if (error instanceof ControlPlaneValidationError) {
    status = 422;
    code = error.code;
    detail = error.message;
  } else if (error instanceof AuthorityGovernanceValidationError) {
    status = 422;
    code = error.code;
    detail = error.message;
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
  }, { status, headers: { "content-type": "application/problem+json", "cache-control": "no-store", "x-correlation-id": context.correlationId } });
}

export function controlPlaneJson(body: unknown, context: RequestContext, status = 200): Response {
  return Response.json(body, { status, headers: { "cache-control": "no-store", "x-correlation-id": context.correlationId } });
}
