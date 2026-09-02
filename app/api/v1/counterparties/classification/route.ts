import { identityJson, identityProblem } from "@/lib/api/identity";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { classifyTransaction } from "@/lib/data/identity-repository";
import { requestContext } from "@/lib/security/request";

/**
 * Module 1 Buyer/Seller ClassifyTransaction: a pre-flight counterparty
 * check ahead of invoice submission. See classifyTransaction in
 * lib/data/identity-repository.ts for why this is deliberately a public-
 * posture, cross-tenant lookup rather than scoped to the actor's own
 * taxpayer.
 */
export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "invoices:submit");
    const vatNumber = new URL(request.url).searchParams.get("vat_number");
    const classification = await classifyTransaction(vatNumber);
    return identityJson({ classification }, context);
  } catch (error) {
    return identityProblem(error, context);
  }
}
