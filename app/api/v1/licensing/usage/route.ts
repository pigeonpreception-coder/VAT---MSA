import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { getUsageSnapshot } from "@/lib/data/control-plane-repository";
import { requestContext } from "@/lib/security/request";

/** Licensing & Entitlements standalone GetUsage — previously not queryable outside the bundled administration snapshot. */
export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "licensing:read", { operationClass: "READ" });
    const snapshot = await getUsageSnapshot(actor, organisationIdFrom(request));
    return controlPlaneJson(snapshot, context);
  } catch (error) {
    return controlPlaneProblem(error, context);
  }
}
