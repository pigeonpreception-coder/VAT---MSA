import { reconciliationJson, reconciliationProblem } from "@/lib/api/reconciliation";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { assignException } from "@/lib/data/reconciliation-repository";
import { enforceReconciliationRateLimits, readBoundedJson, requestContext } from "@/lib/security/request";

/** Module 3 Phase A Assign: { officer_id }. Hands a reconciliation exception to an officer. */
export async function POST(request: Request, { params }: { params: Promise<{ id: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "reconciliation:manage");
    await enforceReconciliationRateLimits("ASSIGN_EXCEPTION", actor);
    const { id } = await params;
    const idempotencyKey = request.headers.get("idempotency-key") ?? "";
    const assignment = await assignException(actor, id, await readBoundedJson(request, 4_096), idempotencyKey, context.correlationId);
    return reconciliationJson({ assignment }, context);
  } catch (error) {
    return reconciliationProblem(error, context);
  }
}
