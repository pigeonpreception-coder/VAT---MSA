import { identityJson, identityProblem } from "@/lib/api/identity";
import { getCurrentUser } from "@/lib/auth";
import { requireLicensedPermission } from "@/lib/data/licensing-repository";
import { enrollTotp } from "@/lib/data/mfa-repository";
import { requestContext } from "@/lib/security/request";

/**
 * Security fix 2026-08-27 EnrollTotp: self-service — every actor manages
 * their own MFA credential, so identity:read (the same near-universal
 * gate GetAssurance already uses) is the only permission required. Returns
 * the raw base32 secret and an otpauth:// URI once, for the caller's
 * authenticator app to enrol; it is never returned again after this call.
 */
export async function POST(request: Request) {
  const context = await requestContext(request);
  try {
    const actor = await getCurrentUser();
    await requireLicensedPermission(actor, "identity:read", { operationClass: "READ" });
    const enrollment = await enrollTotp(actor, context.correlationId);
    return identityJson({ enrollment }, context, 201);
  } catch (error) {
    return identityProblem(error, context);
  }
}
