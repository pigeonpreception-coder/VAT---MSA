import { identityJson, identityProblem } from "@/lib/api/identity";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { verifyTaxpayerIdentifiers } from "@/lib/data/identity-repository";
import { requestContext } from "@/lib/security/request";

/**
 * Module 1 Taxpayer VerifyIdentifiers, standalone and re-triggerable at any
 * time after registration. No step-up: this only attempts an external
 * verification call and, at most, refreshes verified_at — it never changes
 * the identifier value itself (see .../correction for that).
 */
export async function POST(request: Request, contextValue: { params: Promise<{ id: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "taxpayers:read", { operationClass: "READ" });
    const { id } = await contextValue.params;
    const verification = await verifyTaxpayerIdentifiers(actor, id, context.correlationId);
    return identityJson({ verification }, context);
  } catch (error) {
    return identityProblem(error, context);
  }
}
