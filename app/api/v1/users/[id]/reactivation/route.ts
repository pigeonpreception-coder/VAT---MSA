import { identityJson, identityProblem } from "@/lib/api/identity";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { reactivateUser } from "@/lib/data/identity-repository";
import { requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

/** Module 1 Identity SuspendUser's reverse — restores a suspended account to ACTIVE. */
export async function POST(request: Request, contextValue: { params: Promise<{ id: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "administration:manage");
    requireStepUp(request, actor);
    const { id } = await contextValue.params;
    const reactivation = await reactivateUser(actor, id, context.correlationId);
    return identityJson({ reactivation }, context);
  } catch (error) {
    return identityProblem(error, context);
  }
}
