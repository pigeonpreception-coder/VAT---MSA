import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { saveNavigationPreference } from "@/lib/data/control-plane-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";

/** Workspace & Navigation SavePreference: { preference_type, value }. Always writes as the caller's own preference. */
export async function POST(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "workspace:read", { operationClass: "READ" });
    const preference = await saveNavigationPreference(actor, await readBoundedJson(request, 8_192), organisationIdFrom(request));
    return controlPlaneJson({ preference }, context);
  } catch (error) {
    return controlPlaneProblem(error, context);
  }
}
