import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { changeLicenseState } from "@/lib/data/control-plane-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

/** Licensing & Entitlements Activate/Suspend/Renew: { action: "ACTIVATE"|"SUSPEND"|"RENEW", reason }. */
export async function POST(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "licensing:manage", { operationClass: "ADMIN_WRITE" });
    await requireStepUp(request, actor);
    const payload = await readBoundedJson(request, 4_096);
    const result = await changeLicenseState(actor, payload, organisationIdFrom(request));
    return controlPlaneJson({ license: result }, context);
  } catch (error) {
    return controlPlaneProblem(error, context);
  }
}
