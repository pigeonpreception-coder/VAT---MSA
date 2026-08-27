import { identityJson, identityProblem } from "@/lib/api/identity";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { inviteUser } from "@/lib/data/identity-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

/**
 * Module 1 Identity ProvisionUser, invite half. See
 * lib/data/identity-repository.ts's claimInvitation for the other half —
 * the invitee has no app_users row yet, so they cannot call this or any
 * other permission-gated route themselves; they claim via
 * POST /api/v1/invitations/claim instead.
 */
export async function POST(request: Request, contextValue: { params: Promise<{ id: string }> }) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "organisations:manage");
    await requireStepUp(request, actor);
    const { id } = await contextValue.params;
    const payload = await readBoundedJson(request, 4_096);
    const invitation = await inviteUser(actor, id, payload, context.correlationId);
    return identityJson({ invitation }, context, 201);
  } catch (error) {
    return identityProblem(error, context);
  }
}
