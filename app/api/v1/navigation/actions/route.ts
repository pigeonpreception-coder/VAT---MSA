import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { getNavigationItemActions } from "@/lib/data/control-plane-repository";
import { requestContext } from "@/lib/security/request";

/** Workspace & Navigation GetActions: ?item_key=... — whether the actor can act on one navigation item right now, and why not if not. */
export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "workspace:read", { operationClass: "READ" });
    const itemKey = new URL(request.url).searchParams.get("item_key");
    const actions = await getNavigationItemActions(actor, itemKey, organisationIdFrom(request));
    return controlPlaneJson(actions, context);
  } catch (error) {
    return controlPlaneProblem(error, context);
  }
}
