import { AccessDeniedError } from "@/lib/auth";
import { hasRecentStepUp } from "@/lib/domain/control-plane";
import type { UserContext } from "@/lib/domain/types";

const STEP_UP_WINDOW_MS = 5 * 60_000;

export function requireStepUp(request: Request, actor: UserContext): void {
  const assurance = request.headers.get("x-vat-msa-auth-assurance");
  const reauthenticatedAt = request.headers.get("x-vat-msa-reauthenticated-at");
  const localConfirmation = actor.isDevelopmentIdentity
    && process.env.NODE_ENV !== "production"
    && request.headers.get("x-vat-msa-local-step-up") === "confirmed";

  if (!localConfirmation && !hasRecentStepUp({ assurance, reauthenticatedAt, maxAgeMs: STEP_UP_WINDOW_MS })) {
    throw new AccessDeniedError("A fresh multi-factor step-up authentication is required for this privileged change.");
  }
}
