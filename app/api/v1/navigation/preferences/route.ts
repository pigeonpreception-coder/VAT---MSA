import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { saveNavigationPreference } from "@/lib/data/control-plane-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";

/** Workspace & Navigation SavePreference: { preference_type, value }. Always writes as the caller's own preference. */
export async function POST(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "workspace:read");
    const preference = await saveNavigationPreference(actor, await readBoundedJson(request, 8_192), organisationIdFrom(request));
    return controlPlaneJson({ preference }, context);
  } catch (error) {
    return controlPlaneProblem(error, context);
  }
}
