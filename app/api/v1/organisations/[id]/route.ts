import { AccessDeniedError, getCurrentUser } from "@/lib/auth";
import { getOrganisation } from "@/lib/data/identity-repository";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { requestContext } from "@/lib/security/request";

function problem(status: number, code: string, detail: string, correlationId: string) {
  return Response.json({
    type: `https://vat-msa.local/problems/${code.toLowerCase().replaceAll("_", "-")}`,
    title: status === 404 ? "Not found" : status === 401 ? "Unauthorized" : status === 403 ? "Forbidden" : "Internal error",
    status,
    code,
    detail,
    correlationId,
  }, { status, headers: { "content-type": "application/problem+json", "x-correlation-id": correlationId, "cache-control": "no-store" } });
}
export async function GET(request: Request, { params }: { params: Promise<{ id: string }> }) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    const { id } = await params;
    await requireLicensedPermission(user, "identity:read", { operationClass: "READ", requestedOrganisationId: id });
    const organisation = await getOrganisation(user, id);
    if (!organisation) return problem(404, "RESOURCE_NOT_FOUND", "The organisation was not found.", context.correlationId);
    return Response.json(organisation, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.message, context.correlationId);
    return problem(500, "INTERNAL_ERROR", "The organisation record is temporarily unavailable.", context.correlationId);
  }
}
