import { identityJson, identityProblem } from "@/lib/api/identity";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { confirmStepUp } from "@/lib/data/mfa-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";

/**
 * Security fix 2026-08-27 ConfirmStepUp: { code }. The real replacement
 * for the previous client-asserted x-vat-msa-auth-assurance/
 * x-vat-msa-reauthenticated-at headers — writes a genuine, server-verified
 * step_up_events row that lib/security/step-up.ts's requireStepUp now
 * checks. Every "step-up gated" command in this codebase requires a fresh
 * call here first.
 */
export async function POST(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "identity:read");
    const payload = await readBoundedJson(request, 256);
    const stepUp = await confirmStepUp(actor, payload, context.correlationId);
    return identityJson({ step_up: stepUp }, context, 201);
  } catch (error) {
    return identityProblem(error, context);
  }
}
