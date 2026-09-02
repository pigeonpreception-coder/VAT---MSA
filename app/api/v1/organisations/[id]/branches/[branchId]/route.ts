import { identityJson, identityProblem } from "@/lib/api/identity";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { updateBranch } from "@/lib/data/identity-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";

export async function PATCH(request: Request, contextValue: { params: Promise<{ id: string; branchId: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "organisations:manage");
    const { id, branchId } = await contextValue.params;
    const payload = await readBoundedJson(request, 4_096);
    const branch = await updateBranch(actor, id, branchId, payload, context.correlationId);
    return identityJson({ branch }, context);
  } catch (error) {
    return identityProblem(error, context);
  }
}
