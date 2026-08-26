import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { testWorkflowVersion } from "@/lib/data/control-plane-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";

export async function POST(request: Request, contextValue: { params: Promise<{ id: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "workflows:read");
    const result = await testWorkflowVersion(actor, (await contextValue.params).id, await readBoundedJson(request, 4_096), organisationIdFrom(request));
    return controlPlaneJson({ test: result }, context);
  } catch (error) { return controlPlaneProblem(error, context); }
}
