import { reconciliationJson, reconciliationProblem } from "@/lib/api/reconciliation";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { resolveException } from "@/lib/data/reconciliation-repository";
import { enforceReconciliationRateLimits, readBoundedJson, requestContext } from "@/lib/security/request";

/** Module 3 Phase A ResolveException: { notes }. Idempotent on an already-resolved exception. */
export async function POST(request: Request, { params }: { params: Promise<{ id: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "reconciliation:manage");
    await enforceReconciliationRateLimits("RESOLVE_EXCEPTION", actor);
    const { id } = await params;
    const idempotencyKey = request.headers.get("idempotency-key") ?? "";
    const resolution = await resolveException(actor, id, await readBoundedJson(request, 4_096), idempotencyKey, context.correlationId);
    return reconciliationJson({ resolution }, context);
  } catch (error) {
    return reconciliationProblem(error, context);
  }
}
