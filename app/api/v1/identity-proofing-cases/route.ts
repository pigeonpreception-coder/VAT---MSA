import { AccessDeniedError, getCurrentUser } from "@/lib/auth";
import { listIdentityProofingCases } from "@/lib/data/identity-repository";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { requestContext } from "@/lib/security/request";

function problem(status: number, code: string, detail: string, correlationId: string) {
  return Response.json({
    type: `https://vat-msa.local/problems/${code.toLowerCase().replaceAll("_", "-")}`,
    title: status === 401 ? "Unauthorized" : status === 403 ? "Forbidden" : "Internal error",
    status,
    code,
    detail,
    correlationId,
  }, { status, headers: { "content-type": "application/problem+json", "x-correlation-id": correlationId, "cache-control": "no-store" } });
}

export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    await requireLicensedPermission(user, "registrations:read", { operationClass: "READ" });
    return Response.json({ identity_proofing_cases: await listIdentityProofingCases(user) }, {
      headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" },
    });
  } catch (error) {
    if (error instanceof AccessDeniedError) {
      return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.message, context.correlationId);
    }
    return problem(500, "INTERNAL_ERROR", "The identity-proofing queue is temporarily unavailable.", context.correlationId);
  }
}
