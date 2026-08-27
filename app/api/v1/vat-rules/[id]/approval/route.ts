import { vatRuleJson, vatRuleProblem } from "@/lib/api/vat-rules";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { approveVatRule } from "@/lib/data/vat-rule-repository";
import { enforceVatRuleRateLimits, readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

/** Module 2 Phase A ApproveVatRule: { reason }. Denies self-approval of the proposing officer's own draft (checked in the repository layer). */
export async function POST(request: Request, contextValue: { params: Promise<{ id: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "vat-rules:manage");
    await enforceVatRuleRateLimits("APPROVE_VAT_RULE", actor);
    await requireStepUp(request, actor);
    const { id } = await contextValue.params;
    const idempotencyKey = request.headers.get("idempotency-key") ?? "";
    const rule = await approveVatRule(actor, id, await readBoundedJson(request, 4_096), idempotencyKey, context.correlationId);
    return vatRuleJson({ rule }, context);
  } catch (error) {
    return vatRuleProblem(error, context);
  }
}
