import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { revokeDelegation } from "@/lib/data/control-plane-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

export async function POST(request: Request, contextValue: { params: Promise<{ id: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "workflows:manage");
    await requireStepUp(request, actor);
    return controlPlaneJson({ delegation: await revokeDelegation(actor, (await contextValue.params).id, await readBoundedJson(request, 4_096), organisationIdFrom(request)) }, context);
  } catch (error) { return controlPlaneProblem(error, context); }
}
