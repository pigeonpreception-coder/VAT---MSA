import { getChatGPTUser } from "@/app/chatgpt-auth";
import { identityJson, identityProblem } from "@/lib/api/identity";
import { AccessDeniedError } from "@/lib/auth";
import { claimInvitation } from "@/lib/data/identity-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";

/**
 * Module 1 Identity ProvisionUser, claim half. Deliberately does not go
 * through getCurrentUser() — the whole point of this route is that the
 * caller has no app_users row yet, so getCurrentUser() would throw
 * "not been provisioned" before ever reaching here. Trusts the raw
 * platform-authenticated identity the same way getCurrentUser() does, then
 * hands it to claimInvitation to verify against the invitation record
 * itself (token, expiry, email match).
 */
export async function POST(request: Request) {
  const context = await requestContext(request);
  try {
    const platformUser = await getChatGPTUser();
    if (!platformUser) throw new AccessDeniedError("Platform authentication is required to claim an invitation.", 401);
    const payload = await readBoundedJson(request, 1_024);
    const result = await claimInvitation(
      { subject: platformUser.userId, email: platformUser.email, displayName: platformUser.displayName },
      payload,
      context.correlationId,
    );
    return identityJson({ claim: result }, context, 201);
  } catch (error) {
    return identityProblem(error, context);
  }
}
