import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { assignWorkflow } from "@/lib/data/control-plane-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

export async function POST(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "workflows:manage");
    await requireStepUp(request, actor);
    return controlPlaneJson({ instance: await assignWorkflow(actor, await readBoundedJson(request, 16_384), organisationIdFrom(request)) }, context, 201);
  } catch (error) { return controlPlaneProblem(error, context); }
}
