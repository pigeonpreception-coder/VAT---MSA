import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { getAdministrationSnapshot } from "@/lib/data/control-plane-repository";
import { requestContext } from "@/lib/security/request";

export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "licensing:read");
    const snapshot = await getAdministrationSnapshot(actor, organisationIdFrom(request));
    return controlPlaneJson({ organisation: snapshot.organisation, license: snapshot.license, entitlements: snapshot.entitlements }, context);
  } catch (error) {
    return controlPlaneProblem(error, context);
  }
}
