import { reconciliationJson, reconciliationProblem } from "@/lib/api/reconciliation";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { assignException } from "@/lib/data/reconciliation-repository";
import { enforceReconciliationRateLimits, readBoundedJson, requestContext } from "@/lib/security/request";

/** Module 3 Phase A Assign: { officer_id }. Hands a reconciliation exception to an officer. */
export async function POST(request: Request, { params }: { params: Promise<{ id: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "reconciliation:manage", { operationClass: "COMPLIANCE_WRITE" });
    await enforceReconciliationRateLimits("ASSIGN_EXCEPTION", actor);
    const { id } = await params;
    const idempotencyKey = request.headers.get("idempotency-key") ?? "";
    const assignment = await assignException(actor, id, await readBoundedJson(request, 4_096), idempotencyKey, context.correlationId);
    return reconciliationJson({ assignment }, context);
  } catch (error) {
    return reconciliationProblem(error, context);
  }
}
