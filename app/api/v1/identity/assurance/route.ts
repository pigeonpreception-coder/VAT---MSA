import { identityJson, identityProblem } from "@/lib/api/identity";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { listIdentityLinks } from "@/lib/data/identity-repository";
import { hasRecentStepUp } from "@/lib/domain/control-plane";
import { requestContext } from "@/lib/security/request";

const STEP_UP_WINDOW_SECONDS = 300;

/**
 * Module 1 Identity GetAssurance: the caller's own current assurance
 * posture. Combines two distinct things this codebase calls "assurance":
 * the baseline assurance_level recorded on each active identity_link (how
 * that identity was established), and whether *this request* currently
 * carries a fresh step-up assertion (lib/security/step-up.ts's
 * requireStepUp check, exposed here as a read instead of only a gate).
 */
export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "identity:read");
    const links = (await listIdentityLinks(actor.userId)).filter((link) => link.status === "ACTIVE");
    const assurance = request.headers.get("x-vat-msa-auth-assurance");
    const reauthenticatedAt = request.headers.get("x-vat-msa-reauthenticated-at");
    return identityJson({
      userId: actor.userId,
      isDevelopmentIdentity: actor.isDevelopmentIdentity,
      identityLinks: links.map((link) => ({ providerKey: link.providerKey, assuranceLevel: link.assuranceLevel, lastAuthenticatedAt: link.lastAuthenticatedAt })),
      hasRecentStepUp: hasRecentStepUp({ assurance, reauthenticatedAt, maxAgeMs: STEP_UP_WINDOW_SECONDS * 1_000 }),
      stepUpWindowSeconds: STEP_UP_WINDOW_SECONDS,
    }, context);
  } catch (error) {
    return identityProblem(error, context);
  }
}
