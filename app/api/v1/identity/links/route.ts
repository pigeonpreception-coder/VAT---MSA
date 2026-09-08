import { identityJson, identityProblem } from "@/lib/api/identity";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { linkIdentity, listIdentityLinks } from "@/lib/data/identity-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";
import { requireStepUp } from "@/lib/security/step-up";

/** Module 1 Identity ResolveIdentity: ?user_id=... (defaults to self; a different user_id requires administration:manage). */
export async function GET(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "identity:read", { operationClass: "READ" });
    const requestedUserId = new URL(request.url).searchParams.get("user_id");
    const userId = requestedUserId && requestedUserId !== actor.userId ? requestedUserId : actor.userId;
    if (userId !== actor.userId) await requireLicensedPermission(actor, "administration:manage", { operationClass: "ADMIN_WRITE" });
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
    await requireLicensedPermission(actor, "administration:manage", { operationClass: "ADMIN_WRITE" });
    await requireStepUp(request, actor);
    const payload = await readBoundedJson(request, 4_096);
    const link = await linkIdentity(actor, payload, context.correlationId);
    return identityJson({ link }, context, 201);
  } catch (error) {
    return identityProblem(error, context);
  }
}
