import { reconciliationJson, reconciliationProblem } from "@/lib/api/reconciliation";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { resolveException } from "@/lib/data/reconciliation-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";

/** Module 3 Phase A ResolveException: { notes }. Idempotent on an already-resolved exception. */
export async function POST(request: Request, { params }: { params: Promise<{ id: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "reconciliation:manage");
    const { id } = await params;
    const resolution = await resolveException(actor, id, await readBoundedJson(request, 4_096), context.correlationId);
    return reconciliationJson({ resolution }, context);
  } catch (error) {
    return reconciliationProblem(error, context);
  }
}
