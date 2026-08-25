import { identityJson, identityProblem } from "@/lib/api/identity";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { linkIdentity, listIdentityLinks } from "@/lib/data/identity-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

/** Module 1 Identity ResolveIdentity: ?user_id=... (defaults to self; a different user_id requires administration:manage). */
export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "identity:read");
    const requestedUserId = new URL(request.url).searchParams.get("user_id");
    const userId = requestedUserId && requestedUserId !== actor.userId ? requestedUserId : actor.userId;
    if (userId !== actor.userId) requirePermission(actor, "administration:manage");
    return identityJson({ userId, links: await listIdentityLinks(userId) }, context);
  } catch (error) {
    return identityProblem(error, context);
  }
}

/** Module 1 Identity LinkIdentity: { user_id, provider_key, subject }. Admin-only. */
export async function POST(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "administration:manage");
    requireStepUp(request, actor);
    const payload = await readBoundedJson(request, 4_096);
    const link = await linkIdentity(actor, payload, context.correlationId);
    return identityJson({ link }, context, 201);
  } catch (error) {
    return identityProblem(error, context);
  }
}
