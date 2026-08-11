import { AccessDeniedError } from "@/lib/auth";
import { RepositoryConflictError } from "@/lib/data/repository";
import { ControlPlaneValidationError } from "@/lib/domain/control-plane";
import { RequestGuardError, type RequestContext } from "@/lib/security/request";

export function organisationIdFrom(request: Request): string | null {
  return new URL(request.url).searchParams.get("organisation_id");
}

export function controlPlaneProblem(error: unknown, context: RequestContext): Response {
  let status = 500;
  let code = "INTERNAL_ERROR";
  let detail = "The control-plane operation could not be completed.";
  if (error instanceof AccessDeniedError) {
    status = error.status;
    code = status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED";
    detail = error.message;
  } else if (error instanceof ControlPlaneValidationError) {
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
