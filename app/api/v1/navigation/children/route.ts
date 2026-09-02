import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { getNavigationChildren } from "@/lib/data/control-plane-repository";
import { requestContext } from "@/lib/security/request";

/** Workspace & Navigation GetChildren: ?parent_type=workspace|folder&parent_id=... */
export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "workspace:read");
    const params = new URL(request.url).searchParams;
    const children = await getNavigationChildren(actor, params.get("parent_type"), params.get("parent_id"), organisationIdFrom(request));
    return controlPlaneJson(children, context);
  } catch (error) {
    return controlPlaneProblem(error, context);
  }
}
