import { identityJson, identityProblem } from "@/lib/api/identity";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { correctTaxpayerIdentifier } from "@/lib/data/identity-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

/**
 * Module 1 Taxpayer IdentifierVersion / correction. Reuses taxpayers:suspend
 * as its permission ceiling — only PILOT_ADMIN and NAMRA_SYSTEM_ADMIN hold
 * it — since correcting a canonical VAT number or TIN is at least as
 * consequential as suspending the taxpayer outright.
 */
export async function POST(request: Request, contextValue: { params: Promise<{ id: string; identifierId: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "taxpayers:suspend");
    requireStepUp(request, actor);
    const { id, identifierId } = await contextValue.params;
    const payload = await readBoundedJson(request, 4_096);
    const correction = await correctTaxpayerIdentifier(actor, id, identifierId, payload, context.correlationId);
    return identityJson({ correction }, context);
  } catch (error) {
    return identityProblem(error, context);
  }
}
