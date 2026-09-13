import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { upgradeLicense } from "@/lib/data/control-plane-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

/** Licensing & Entitlements Upgrade: { license_plan_code }. A distinct plan-change operation from Activate/Suspend/Renew — see upgradeLicense in lib/data/control-plane-repository.ts. */
export async function POST(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "licensing:manage", { operationClass: "ADMIN_WRITE" });
    await requireStepUp(request, actor);
    const payload = await readBoundedJson(request, 4_096);
    const result = await upgradeLicense(actor, payload, organisationIdFrom(request));
    return controlPlaneJson({ license: result }, context);
  } catch (error) {
    return controlPlaneProblem(error, context);
  }
}
