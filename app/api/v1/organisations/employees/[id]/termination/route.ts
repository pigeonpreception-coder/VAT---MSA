import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser } from "@/lib/auth";
import { terminateEmployee } from "@/lib/data/control-plane-repository";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

export async function POST(request: Request, contextValue: { params: Promise<{ id: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "employees:manage");
    await requireStepUp(request, actor);
    const payload = await readBoundedJson<{ reason?: string }>(request, 8_192);
    const result = await terminateEmployee(actor, (await contextValue.params).id, String(payload.reason ?? ""), organisationIdFrom(request));
    return controlPlaneJson({ employee: result }, context);
  } catch (error) { return controlPlaneProblem(error, context); }
}
