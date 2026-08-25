import { identityJson, identityProblem } from "@/lib/api/identity";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { revokeIdentityLink } from "@/lib/data/identity-repository";
import { requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

/**
 * Module 1 Identity RevokeSession (see MODULE_DEVELOPMENT_PLAYBOOK.md's
 * Identity Phase A decision: "session" means the identity_link here, since
 * there is no separate session record this system controls). Admin-only.
 */
export async function POST(request: Request, contextValue: { params: Promise<{ id: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "administration:manage");
    requireStepUp(request, actor);
    const { id } = await contextValue.params;
    const revocation = await revokeIdentityLink(actor, id, context.correlationId);
    return identityJson({ revocation }, context);
  } catch (error) {
    return identityProblem(error, context);
  }
}
