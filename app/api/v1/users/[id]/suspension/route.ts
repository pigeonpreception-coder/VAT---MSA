import { identityJson, identityProblem } from "@/lib/api/identity";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { suspendUser } from "@/lib/data/identity-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

/**
 * Module 1 Identity SuspendUser — a standalone, reversible account lockout,
 * distinct from terminateEmployee's one-way offboarding. See
 * lib/data/identity-repository.ts's suspendUser docstring for why.
 */
export async function POST(request: Request, contextValue: { params: Promise<{ id: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "administration:manage");
    requireStepUp(request, actor);
    const { id } = await contextValue.params;
    const payload = await readBoundedJson(request, 4_096);
    const suspension = await suspendUser(actor, id, payload, context.correlationId);
    return identityJson({ suspension }, context);
  } catch (error) {
    return identityProblem(error, context);
  }
}
