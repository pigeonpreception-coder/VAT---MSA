import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { appointAdministrator, getAdministrationSnapshot } from "@/lib/data/control-plane-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "administration:read", { operationClass: "READ" });
    const snapshot = await getAdministrationSnapshot(actor, organisationIdFrom(request));
    return controlPlaneJson({ organisation: snapshot.organisation, administrators: snapshot.administrators }, context);
  } catch (error) {
    return controlPlaneProblem(error, context);
  }
}

/** Organisation Administration AppointAdministrator: { user_id, administrator_role_code, is_primary?, approval_reference }. */
export async function POST(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "administration:manage", { operationClass: "ADMIN_WRITE" });
    await requireStepUp(request, actor);
    const administrator = await appointAdministrator(actor, await readBoundedJson(request, 4_096), organisationIdFrom(request));
    return controlPlaneJson({ administrator }, context, 201);
  } catch (error) {
    return controlPlaneProblem(error, context);
  }
}
