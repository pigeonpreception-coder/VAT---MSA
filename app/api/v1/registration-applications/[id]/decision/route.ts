import { identityJson, identityProblem } from "@/lib/api/identity";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { decideRegistrationApplication } from "@/lib/data/identity-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

export async function POST(request: Request, contextValue: { params: Promise<{ id: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "registrations:approve", { operationClass: "COMPLIANCE_WRITE" });
    await requireStepUp(request, actor);
    const { id } = await contextValue.params;
    const payload = await readBoundedJson(request, 4_096);
    const decision = await decideRegistrationApplication(actor, id, payload, context.correlationId);
    return identityJson({ decision }, context);
  } catch (error) {
    return identityProblem(error, context);
  }
}
