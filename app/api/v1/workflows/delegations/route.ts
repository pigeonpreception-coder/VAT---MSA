import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { createDelegation, listDelegations } from "@/lib/data/control-plane-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "workflows:read", { operationClass: "READ" });
    return controlPlaneJson({ delegations: await listDelegations(actor, organisationIdFrom(request)) }, context);
  } catch (error) { return controlPlaneProblem(error, context); }
}

export async function POST(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "workflows:manage", { operationClass: "ADMIN_WRITE" });
    await requireStepUp(request, actor);
    return controlPlaneJson({ delegation: await createDelegation(actor, await readBoundedJson(request, 4_096), organisationIdFrom(request)) }, context, 201);
  } catch (error) { return controlPlaneProblem(error, context); }
}
