import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser } from "@/lib/auth";
import { decideWorkflowTask } from "@/lib/data/control-plane-repository";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

export async function POST(request: Request, contextValue: { params: Promise<{ id: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "workflows:decide", { requestedOrganisationId: organisationIdFrom(request) });
    await requireStepUp(request, actor);
    return controlPlaneJson({ decision: await decideWorkflowTask(actor, (await contextValue.params).id, await readBoundedJson(request, 16_384), organisationIdFrom(request)) }, context);
  } catch (error) { return controlPlaneProblem(error, context); }
}
