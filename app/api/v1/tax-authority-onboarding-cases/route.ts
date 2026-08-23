import { controlPlaneJson, controlPlaneProblem } from "@/lib/api/control-plane";
import { getCurrentUser } from "@/lib/auth";
import { createAuthorityOnboardingCase, getAuthorityGovernanceSnapshot } from "@/lib/data/authority-governance-repository";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import type { AuthorityOnboardingSubmission } from "@/lib/domain/authority-governance";
import { readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "authority-governance:read", { operationClass: "READ" });
    return controlPlaneJson({ governance: await getAuthorityGovernanceSnapshot(actor) }, context);
  } catch (error) {
    return controlPlaneProblem(error, context);
  }
}

export async function POST(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "authority-governance:manage", { operationClass: "ADMIN_WRITE" });
    await requireStepUp(request, actor);
    const onboardingCase = await createAuthorityOnboardingCase(
      actor,
      await readBoundedJson<AuthorityOnboardingSubmission>(request, 16_384),
      request.headers.get("idempotency-key") ?? "",
      context.correlationId,
    );
    return controlPlaneJson({ onboarding_case: onboardingCase, production_activation_effect: false }, context, 201);
  } catch (error) {
    return controlPlaneProblem(error, context);
  }
}
