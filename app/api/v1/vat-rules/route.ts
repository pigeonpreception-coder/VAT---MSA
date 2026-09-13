import { vatRuleJson, vatRuleProblem } from "@/lib/api/vat-rules";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { listVatRules, proposeVatRule } from "@/lib/data/vat-rule-repository";
import { enforceVatRuleRateLimits, readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "vat-rules:read", { operationClass: "READ" });
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
    await requireLicensedPermission(actor, "vat-rules:manage", { operationClass: "COMPLIANCE_WRITE" });
    await enforceVatRuleRateLimits("PROPOSE_VAT_RULE", actor);
    await requireStepUp(request, actor);
    const idempotencyKey = request.headers.get("idempotency-key") ?? "";
    const rule = await proposeVatRule(actor, await readBoundedJson(request, 4_096), idempotencyKey, context.correlationId);
    return vatRuleJson({ rule }, context, 201);
  } catch (error) {
    return vatRuleProblem(error, context);
  }
}
