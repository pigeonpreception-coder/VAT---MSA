import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { grantCapability, listCapabilityGrants } from "@/lib/data/control-plane-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "roles:read", { operationClass: "READ" });
    const snapshot = await listCapabilityGrants(actor, organisationIdFrom(request));
    return controlPlaneJson(snapshot, context);
  } catch (error) {
    return controlPlaneProblem(error, context);
  }
}

/** Organisation Authorization GrantCapability: { user_id, capability: "BUYER"|"SELLER" }. */
export async function POST(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "roles:manage", { operationClass: "ADMIN_WRITE" });
    await requireStepUp(request, actor);
    const capability = await grantCapability(actor, await readBoundedJson(request, 4_096), organisationIdFrom(request));
    return controlPlaneJson({ capability }, context, 201);
  } catch (error) {
    return controlPlaneProblem(error, context);
  }
}
