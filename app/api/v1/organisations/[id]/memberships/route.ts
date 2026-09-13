import { identityJson, identityProblem } from "@/lib/api/identity";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { assignMembership } from "@/lib/data/identity-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

export async function POST(request: Request, contextValue: { params: Promise<{ id: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "organisations:manage", { operationClass: "ADMIN_WRITE" });
    await requireStepUp(request, actor);
    const { id } = await contextValue.params;
    const payload = await readBoundedJson(request, 4_096);
    const membership = await assignMembership(actor, id, payload, context.correlationId);
    return identityJson({ membership }, context, 201);
  } catch (error) {
    return identityProblem(error, context);
  }
}
