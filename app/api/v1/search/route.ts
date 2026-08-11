import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { searchWorkspace } from "@/lib/data/control-plane-repository";
import { requestContext } from "@/lib/security/request";

export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "search:read");
    const query = new URL(request.url).searchParams.get("q") ?? "";
    return controlPlaneJson({ query, results: await searchWorkspace(actor, query, organisationIdFrom(request)) }, context);
  } catch (error) {
    return controlPlaneProblem(error, context);
  }
}
