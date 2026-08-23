import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser } from "@/lib/auth";
import { createOrganisationRole, getAdministrationSnapshot } from "@/lib/data/control-plane-repository";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "roles:read", { operationClass: "READ", requestedOrganisationId: organisationIdFrom(request) });
    const snapshot = await getAdministrationSnapshot(actor, organisationIdFrom(request));
    return controlPlaneJson({ organisation: snapshot.organisation, roles: snapshot.roles }, context);
  } catch (error) {
    return controlPlaneProblem(error, context);
  }
}

export async function POST(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "roles:manage", { requestedOrganisationId: organisationIdFrom(request) });
    requireStepUp(request, actor);
    const role = await createOrganisationRole(actor, await readBoundedJson(request, 32_768), organisationIdFrom(request));
    return controlPlaneJson({ role }, context, 201);
  } catch (error) {
    return controlPlaneProblem(error, context);
  }
}
