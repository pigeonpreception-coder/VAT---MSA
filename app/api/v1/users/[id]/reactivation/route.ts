import { identityJson, identityProblem } from "@/lib/api/identity";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { reactivateUser } from "@/lib/data/identity-repository";
import { requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

/** Module 1 Identity SuspendUser's reverse — restores a suspended account to ACTIVE. */
export async function POST(request: Request, contextValue: { params: Promise<{ id: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "administration:manage", { operationClass: "ADMIN_WRITE" });
    await requireStepUp(request, actor);
    const { id } = await contextValue.params;
    const reactivation = await reactivateUser(actor, id, context.correlationId);
    return identityJson({ reactivation }, context);
  } catch (error) {
    return identityProblem(error, context);
  }
}
