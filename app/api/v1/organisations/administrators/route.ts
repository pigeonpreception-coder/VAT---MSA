import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { appointAdministrator, getAdministrationSnapshot } from "@/lib/data/control-plane-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "administration:read");
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
    requirePermission(actor, "administration:manage");
    requireStepUp(request, actor);
    const administrator = await appointAdministrator(actor, await readBoundedJson(request, 4_096), organisationIdFrom(request));
    return controlPlaneJson({ administrator }, context, 201);
  } catch (error) {
    return controlPlaneProblem(error, context);
  }
}
