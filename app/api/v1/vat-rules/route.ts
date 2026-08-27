import { vatRuleJson, vatRuleProblem } from "@/lib/api/vat-rules";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { listVatRules, proposeVatRule } from "@/lib/data/vat-rule-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "vat-rules:read");
    return vatRuleJson({ rules: await listVatRules() }, context);
  } catch (error) {
    return vatRuleProblem(error, context);
  }
}

/** Module 2 Phase A ProposeVatRule: { tax_category, rate_bps, effective_from, reason }. Creates a DRAFT; see .../:id/approval for activation. */
export async function POST(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "vat-rules:manage");
    await requireStepUp(request, actor);
    const rule = await proposeVatRule(actor, await readBoundedJson(request, 4_096), context.correlationId);
    return vatRuleJson({ rule }, context, 201);
  } catch (error) {
    return vatRuleProblem(error, context);
  }
}
