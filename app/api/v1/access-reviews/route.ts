import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser } from "@/lib/auth";
import { getAdministrationSnapshot, openQuarterlyAccessReview } from "@/lib/data/control-plane-repository";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "access-governance:read", { operationClass: "READ", requestedOrganisationId: organisationIdFrom(request) });
    const snapshot = await getAdministrationSnapshot(actor, organisationIdFrom(request));
    return controlPlaneJson({ organisation: snapshot.organisation, reviews: snapshot.accessReviews }, context);
  } catch (error) { return controlPlaneProblem(error, context); }
}

export async function POST(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "access-governance:manage", { operationClass: "COMPLIANCE_WRITE", requestedOrganisationId: organisationIdFrom(request) });
    requireStepUp(request, actor);
    return controlPlaneJson({ review: await openQuarterlyAccessReview(actor, organisationIdFrom(request)) }, context, 201);
  } catch (error) { return controlPlaneProblem(error, context); }
}
