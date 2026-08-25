import { reconciliationJson, reconciliationProblem } from "@/lib/api/reconciliation";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { assignException } from "@/lib/data/reconciliation-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";

/** Module 3 Phase A Assign: { officer_id }. Hands a reconciliation exception to an officer. */
export async function POST(request: Request, { params }: { params: Promise<{ id: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "reconciliation:manage");
    const { id } = await params;
    const assignment = await assignException(actor, id, await readBoundedJson(request, 4_096), context.correlationId);
    return reconciliationJson({ assignment }, context);
  } catch (error) {
    return reconciliationProblem(error, context);
  }
}
