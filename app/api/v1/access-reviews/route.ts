import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { getAdministrationSnapshot, openQuarterlyAccessReview } from "@/lib/data/control-plane-repository";
import { requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "access-governance:read");
    const snapshot = await getAdministrationSnapshot(actor, organisationIdFrom(request));
    return controlPlaneJson({ organisation: snapshot.organisation, reviews: snapshot.accessReviews }, context);
  } catch (error) { return controlPlaneProblem(error, context); }
}

export async function POST(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "access-governance:manage");
    await requireStepUp(request, actor);
    return controlPlaneJson({ review: await openQuarterlyAccessReview(actor, organisationIdFrom(request)) }, context, 201);
  } catch (error) { return controlPlaneProblem(error, context); }
}
