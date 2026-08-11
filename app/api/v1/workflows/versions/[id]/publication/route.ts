import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { publishWorkflowVersion } from "@/lib/data/control-plane-repository";
import { requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

export async function POST(request: Request, contextValue: { params: Promise<{ id: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "workflows:manage");
    requireStepUp(request, actor);
    return controlPlaneJson({ workflowVersion: await publishWorkflowVersion(actor, (await contextValue.params).id, organisationIdFrom(request)) }, context);
  } catch (error) { return controlPlaneProblem(error, context); }
}
