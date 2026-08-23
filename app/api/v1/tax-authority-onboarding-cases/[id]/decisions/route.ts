import { controlPlaneJson, controlPlaneProblem } from "@/lib/api/control-plane";
import { getCurrentUser } from "@/lib/auth";
import { decideAuthorityOnboardingCase } from "@/lib/data/authority-governance-repository";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import type { AuthorityOnboardingDecisionSubmission } from "@/lib/domain/authority-governance";
import { readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

export async function POST(request: Request, contextValue: { params: Promise<{ id: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "authority-governance:manage", { operationClass: "ADMIN_WRITE" });
    await requireStepUp(request, actor);
    const onboardingCase = await decideAuthorityOnboardingCase(
      actor,
      (await contextValue.params).id,
      await readBoundedJson<AuthorityOnboardingDecisionSubmission>(request, 16_384),
      request.headers.get("idempotency-key") ?? "",
      context.correlationId,
      `verified-step-up:${context.correlationId}`,
    );
    return controlPlaneJson({ onboarding_case: onboardingCase, production_activation_effect: false }, context);
  } catch (error) {
    return controlPlaneProblem(error, context);
  }
}
