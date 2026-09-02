import { identityJson, identityProblem } from "@/lib/api/identity";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { listIdentityLinks } from "@/lib/data/identity-repository";
import { getMfaStatus } from "@/lib/data/mfa-repository";
import { requestContext } from "@/lib/security/request";

const STEP_UP_WINDOW_SECONDS = 300;

/**
 * Module 1 Identity GetAssurance: the caller's own current assurance
 * posture. Combines two distinct things this codebase calls "assurance":
 * the baseline assurance_level recorded on each active identity_link (how
 * that identity was established), and whether the caller currently holds
 * a fresh, server-verified step-up (lib/security/step-up.ts's
 * requireStepUp check, exposed here as a read instead of only a gate).
 *
 * Security fix 2026-08-27: previously read the caller-supplied
 * x-vat-msa-auth-assurance/x-vat-msa-reauthenticated-at request headers
 * directly — a self-reported claim with no server-side backing. Now reads
 * the real mfa_totp_credentials/step_up_events tables via
 * lib/data/mfa-repository.ts's getMfaStatus.
 */
export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "identity:read");
    const [links, mfaStatus] = await Promise.all([
      listIdentityLinks(actor.userId).then((all) => all.filter((link) => link.status === "ACTIVE")),
      getMfaStatus(actor.userId),
    ]);
    return identityJson({
      userId: actor.userId,
      isDevelopmentIdentity: actor.isDevelopmentIdentity,
      identityLinks: links.map((link) => ({ providerKey: link.providerKey, assuranceLevel: link.assuranceLevel, lastAuthenticatedAt: link.lastAuthenticatedAt })),
      mfaEnrolled: mfaStatus.enrolled,
      hasRecentStepUp: mfaStatus.hasRecentStepUp,
      stepUpWindowSeconds: STEP_UP_WINDOW_SECONDS,
    }, context);
  } catch (error) {
    return identityProblem(error, context);
  }
}
