import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { getNavigationItemActions } from "@/lib/data/control-plane-repository";
import { requestContext } from "@/lib/security/request";

/** Workspace & Navigation GetActions: ?item_key=... — whether the actor can act on one navigation item right now, and why not if not. */
export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "workspace:read");
    const itemKey = new URL(request.url).searchParams.get("item_key");
    const actions = await getNavigationItemActions(actor, itemKey, organisationIdFrom(request));
    return controlPlaneJson(actions, context);
  } catch (error) {
    return controlPlaneProblem(error, context);
  }
}
