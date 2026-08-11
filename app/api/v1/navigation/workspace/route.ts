import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { getEffectiveNavigation } from "@/lib/data/control-plane-repository";
import { requestContext } from "@/lib/security/request";

export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "workspace:read");
    return controlPlaneJson(await getEffectiveNavigation(actor, organisationIdFrom(request)), context);
  } catch (error) {
    return controlPlaneProblem(error, context);
  }
}
