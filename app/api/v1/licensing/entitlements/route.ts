import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { getEntitlementsSnapshot } from "@/lib/data/control-plane-repository";
import { requestContext } from "@/lib/security/request";

/** Licensing & Entitlements standalone GetEntitlements — previously only readable bundled inside GET /api/v1/licensing/license. */
export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "licensing:read");
    const snapshot = await getEntitlementsSnapshot(actor, organisationIdFrom(request));
    return controlPlaneJson(snapshot, context);
  } catch (error) {
    return controlPlaneProblem(error, context);
  }
}
