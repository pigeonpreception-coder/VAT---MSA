import { ensureDatabase } from "@/db/runtime";
import { AccessDeniedError } from "@/lib/auth";
import { hasRecentStepUp } from "@/lib/domain/control-plane";
import { sha256Hex } from "@/lib/domain/invoice";
import type { UserContext } from "@/lib/domain/types";
import { STEP_UP_WINDOW_MS, verifySignedStepUpEvidence } from "@/lib/security/step-up-evidence";

const EVIDENCE_HEADER = "x-vat-msa-step-up-evidence";

export async function requireStepUp(request: Request, actor: UserContext): Promise<void> {
  const signedEvidence = request.headers.get(EVIDENCE_HEADER);
  if (signedEvidence) {
    const secret = process.env.VAT_MSA_STEP_UP_HMAC_SECRET ?? "";
    let verified: Awaited<ReturnType<typeof verifySignedStepUpEvidence>>;
    try {
      verified = await verifySignedStepUpEvidence(signedEvidence, actor.userId, secret);
    } catch (error) {
      throw new AccessDeniedError(error instanceof Error ? error.message : "The step-up authentication evidence is invalid.");
    }
    const digest = await sha256Hex(signedEvidence);
    try {
      const db = await ensureDatabase();
      await db.prepare(`INSERT INTO step_up_evidence_uses
        (evidence_digest,actor_id,issued_at,expires_at,used_at) VALUES (?,?,?,?,?)`)
        .bind(digest, actor.userId, new Date(verified.issuedAtMs).toISOString(), new Date(verified.expiresAtMs).toISOString(), new Date().toISOString()).run();
      return;
    } catch {
      throw new AccessDeniedError("The step-up authentication evidence has already been used or could not be recorded.");
    }
  }

  if (process.env.NODE_ENV === "production") {
    throw new AccessDeniedError("Signed, single-use step-up authentication evidence is required for this privileged change.");
  }

  const assurance = request.headers.get("x-vat-msa-auth-assurance");
  const reauthenticatedAt = request.headers.get("x-vat-msa-reauthenticated-at");
  const localConfirmation = actor.isDevelopmentIdentity && request.headers.get("x-vat-msa-local-step-up") === "confirmed";
  if (!localConfirmation && !hasRecentStepUp({ assurance, reauthenticatedAt, maxAgeMs: STEP_UP_WINDOW_MS })) {
    throw new AccessDeniedError("A fresh multi-factor step-up authentication is required for this privileged change.");
  }
}
