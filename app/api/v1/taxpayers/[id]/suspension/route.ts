import { identityJson, identityProblem } from "@/lib/api/identity";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { suspendTaxpayer } from "@/lib/data/identity-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

export async function POST(request: Request, contextValue: { params: Promise<{ id: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "taxpayers:suspend");
    await requireStepUp(request, actor);
    const { id } = await contextValue.params;
    const payload = await readBoundedJson(request, 4_096);
    const suspension = await suspendTaxpayer(actor, id, payload, context.correlationId);
    return identityJson({ suspension }, context);
  } catch (error) {
    return identityProblem(error, context);
  }
}
