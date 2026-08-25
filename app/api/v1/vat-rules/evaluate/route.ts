import { vatRuleJson, vatRuleProblem } from "@/lib/api/vat-rules";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { evaluateVatRule } from "@/lib/data/vat-rule-repository";
import { requestContext } from "@/lib/security/request";

/**
 * Module 2 Phase A EvaluateVAT, standalone: ?tax_category=STANDARD&date=YYYY-MM-DD.
 * Lets an ERP integrator preview the applicable rate before building an
 * invoice; fails closed (422) if no approved rule is bound rather than
 * returning any default.
 */
export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "vat-rules:read");
    const url = new URL(request.url);
    const evaluation = await evaluateVatRule(url.searchParams.get("tax_category"), url.searchParams.get("date"));
    return vatRuleJson({ evaluation }, context);
  } catch (error) {
    return vatRuleProblem(error, context);
  }
}
