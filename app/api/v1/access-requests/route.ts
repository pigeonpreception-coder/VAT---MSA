import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser } from "@/lib/auth";
import { getAdministrationSnapshot, requestRoleAccess } from "@/lib/data/control-plane-repository";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";

export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "access-governance:read", { operationClass: "READ", requestedOrganisationId: organisationIdFrom(request) });
    const snapshot = await getAdministrationSnapshot(actor, organisationIdFrom(request));
    return controlPlaneJson({ organisation: snapshot.organisation, requests: snapshot.accessRequests, reviews: snapshot.accessReviews }, context);
  } catch (error) { return controlPlaneProblem(error, context); }
}

export async function POST(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "access-governance:read", { operationClass: "ADMIN_WRITE", requestedOrganisationId: organisationIdFrom(request) });
    return controlPlaneJson({ request: await requestRoleAccess(actor, await readBoundedJson(request, 16_384), organisationIdFrom(request)) }, context, 201);
  } catch (error) { return controlPlaneProblem(error, context); }
}
