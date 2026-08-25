import { reconciliationJson, reconciliationProblem } from "@/lib/api/reconciliation";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { runMatch } from "@/lib/data/reconciliation-repository";
import { requestContext } from "@/lib/security/request";

/** Module 3 Phase A RunMatch: an independent ledger-consistency verification pass for one invoice. Idempotent. See runMatch in lib/data/reconciliation-repository.ts. */
export async function POST(request: Request, { params }: { params: Promise<{ id: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "reconciliation:manage");
    const { id } = await params;
    const match = await runMatch(actor, id, context.correlationId);
    return reconciliationJson({ match }, context, 201);
  } catch (error) {
    return reconciliationProblem(error, context);
  }
}
