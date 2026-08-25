import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { offboardUser } from "@/lib/data/control-plane-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

/**
 * Access Governance Offboard: { user_id, reason }. Revokes every active
 * role/capability grant and the organisation membership itself, immediately
 * — the access-only counterpart to terminateEmployee (Organisation
 * Administration; also ends the employment record and a licence seat). See
 * offboardUser in lib/data/control-plane-repository.ts.
 */
export async function POST(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "access-governance:manage");
    requireStepUp(request, actor);
    const offboarding = await offboardUser(actor, await readBoundedJson(request, 4_096), organisationIdFrom(request));
    return controlPlaneJson({ offboarding }, context);
  } catch (error) { return controlPlaneProblem(error, context); }
}
