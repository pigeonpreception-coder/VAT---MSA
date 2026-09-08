import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { activateEmployee } from "@/lib/data/control-plane-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

/** Organisation Administration employee INVITED -> ACTIVE: { user_id }. */
export async function POST(request: Request, contextValue: { params: Promise<{ id: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "employees:manage", { operationClass: "ADMIN_WRITE" });
    await requireStepUp(request, actor);
    const { id } = await contextValue.params;
    const payload = await readBoundedJson(request, 4_096);
    const result = await activateEmployee(actor, id, payload, organisationIdFrom(request));
    return controlPlaneJson({ employee: result }, context);
  } catch (error) {
    return controlPlaneProblem(error, context);
  }
}
