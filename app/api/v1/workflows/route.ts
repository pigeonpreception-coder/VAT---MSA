import { controlPlaneJson, controlPlaneProblem, organisationIdFrom } from "@/lib/api/control-plane";
import { getCurrentUser } from "@/lib/auth";
import { createWorkflowDraft, getAdministrationSnapshot } from "@/lib/data/control-plane-repository";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "workflows:read", { operationClass: "READ", requestedOrganisationId: organisationIdFrom(request) });
    const snapshot = await getAdministrationSnapshot(actor, organisationIdFrom(request));
    return controlPlaneJson({ organisation: snapshot.organisation, workflows: snapshot.workflows, tasks: snapshot.tasks }, context);
  } catch (error) { return controlPlaneProblem(error, context); }
}

export async function POST(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "workflows:manage", { requestedOrganisationId: organisationIdFrom(request) });
    requireStepUp(request, actor);
    return controlPlaneJson({ workflow: await createWorkflowDraft(actor, await readBoundedJson(request, 65_536), organisationIdFrom(request)) }, context, 201);
  } catch (error) { return controlPlaneProblem(error, context); }
}
