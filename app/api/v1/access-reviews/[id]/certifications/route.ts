import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { certifyQuarterlyAccess } from "@/lib/data/control-plane-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

export async function POST(request: Request, contextValue: { params: Promise<{ id: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "access-governance:manage");
    requireStepUp(request, actor);
    return controlPlaneJson({ certification: await certifyQuarterlyAccess(actor, (await contextValue.params).id, await readBoundedJson(request, 16_384), organisationIdFrom(request)) }, context, 201);
  } catch (error) { return controlPlaneProblem(error, context); }
}
