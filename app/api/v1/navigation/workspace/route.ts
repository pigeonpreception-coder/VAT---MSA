import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser } from "@/lib/auth";
import { getEffectiveNavigation } from "@/lib/data/control-plane-repository";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { requestContext } from "@/lib/security/request";

export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "workspace:read", { operationClass: "READ", requestedOrganisationId: organisationIdFrom(request) });
    return controlPlaneJson(await getEffectiveNavigation(actor, organisationIdFrom(request)), context);
  } catch (error) {
    return controlPlaneProblem(error, context);
  }
}
