import { AccessDeniedError } from "@/lib/auth";
import { hasFreshStepUp } from "@/lib/data/mfa-repository";
import type { UserContext } from "@/lib/domain/types";

/**
 * Security fix 2026-08-27 (SECURITY_GAP_ASSESSMENT.md item #2 —
 * CRITICAL): this previously read two request headers
 * (x-vat-msa-auth-assurance / x-vat-msa-reauthenticated-at) supplied
 * verbatim by the *caller* and trusted them outright — no application
 * code anywhere ever set those headers on a genuine step-up event, only
 * test fixtures did, so every one of the ~28 "step-up gated" commands
 * (taxpayer suspension, invoice cancellation, identity linking, platform
 * staff provisioning, VAT-rule approval, etc.) was effectively ungated.
 * requireStepUp is now async and checks a real, server-written
 * step_up_events row (see lib/data/mfa-repository.ts's confirmStepUp,
 * which requires a genuine RFC 6238 TOTP code) instead. The
 * development-only local-confirmation escape hatch is unchanged and
 * still fenced by both isDevelopmentIdentity and NODE_ENV!=="production".
 */
export async function requireStepUp(request: Request, actor: UserContext): Promise<void> {
  const localConfirmation = actor.isDevelopmentIdentity
    && process.env.NODE_ENV !== "production"
    && request.headers.get("x-vat-msa-local-step-up") === "confirmed";

  if (!localConfirmation && !(await hasFreshStepUp(actor.userId))) {
    throw new AccessDeniedError("A fresh multi-factor step-up authentication is required for this privileged change. Confirm step-up via POST /api/v1/identity/step-up first.");
  }
}
