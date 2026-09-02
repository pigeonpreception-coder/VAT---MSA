import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { revokeAccessGrant } from "@/lib/data/control-plane-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

/**
 * Access Governance RevokeAccess: { grant_type: "ROLE"|"CAPABILITY",
 * grant_id, reason }. See revokeAccessGrant in
 * lib/data/control-plane-repository.ts for how this differs from the bulk
 * revocation paths already folded into certifyQuarterlyAccess and
 * terminateEmployee.
 */
export async function POST(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "access-governance:manage");
    await requireStepUp(request, actor);
    const revocation = await revokeAccessGrant(actor, await readBoundedJson(request, 4_096), organisationIdFrom(request));
    return controlPlaneJson({ revocation }, context);
  } catch (error) { return controlPlaneProblem(error, context); }
}
