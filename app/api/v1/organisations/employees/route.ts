import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { getAdministrationSnapshot, inviteEmployee } from "@/lib/data/control-plane-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "employees:read");
    const snapshot = await getAdministrationSnapshot(actor, organisationIdFrom(request));
    return controlPlaneJson({ organisation: snapshot.organisation, employees: snapshot.employees }, context);
  } catch (error) {
    return controlPlaneProblem(error, context);
  }
}

export async function POST(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "employees:manage");
    requireStepUp(request, actor);
    const employee = await inviteEmployee(actor, await readBoundedJson(request, 32_768), organisationIdFrom(request));
    return controlPlaneJson({ employee }, context, 201);
  } catch (error) {
    return controlPlaneProblem(error, context);
  }
}
