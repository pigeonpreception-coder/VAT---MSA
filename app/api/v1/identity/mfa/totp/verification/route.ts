import { identityJson, identityProblem } from "@/lib/api/identity";
import { getCurrentUser, requirePermission } from "@/lib/auth";
import { verifyTotpEnrollment } from "@/lib/data/mfa-repository";
import { readBoundedJson, requestContext } from "@/lib/security/request";

/** Security fix 2026-08-27 VerifyTotpEnrollment: { code }. Proves the authenticator app genuinely holds the enrolled secret before it can be used for step-up. */
export async function POST(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    requirePermission(actor, "identity:read");
    const payload = await readBoundedJson(request, 256);
    const credential = await verifyTotpEnrollment(actor, payload, context.correlationId);
    return identityJson({ credential }, context);
  } catch (error) {
    return identityProblem(error, context);
  }
}
