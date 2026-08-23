import { describe, expect, it } from "vitest";
import { createSignedStepUpEvidence, StepUpEvidenceError, verifySignedStepUpEvidence } from "@/lib/security/step-up-evidence";

const SECRET = "synthetic-step-up-secret-for-tests-only-0001";
const NOW = Date.parse("2026-08-23T12:00:00.000Z");
const NONCE = "synthetic_nonce_00000001";

describe("signed step-up evidence", () => {
  it("accepts fresh actor-bound evidence", async () => {
    const token = await createSignedStepUpEvidence("user-1", SECRET, NOW - 60_000, NONCE);
    await expect(verifySignedStepUpEvidence(token, "user-1", SECRET, NOW)).resolves.toEqual({
      issuedAtMs: NOW - 60_000,
      expiresAtMs: NOW + 240_000,
    });
  });

  it("rejects tampering and cross-user replay", async () => {
    const token = await createSignedStepUpEvidence("user-1", SECRET, NOW, NONCE);
    await expect(verifySignedStepUpEvidence(token, "user-2", SECRET, NOW)).rejects.toBeInstanceOf(StepUpEvidenceError);
    await expect(verifySignedStepUpEvidence(`${token.slice(0, -1)}A`, "user-1", SECRET, NOW)).rejects.toBeInstanceOf(StepUpEvidenceError);
  });

  it("rejects expired and materially future-dated evidence", async () => {
    const expired = await createSignedStepUpEvidence("user-1", SECRET, NOW - 300_001, NONCE);
    const future = await createSignedStepUpEvidence("user-1", SECRET, NOW + 30_001, NONCE);
    await expect(verifySignedStepUpEvidence(expired, "user-1", SECRET, NOW)).rejects.toBeInstanceOf(StepUpEvidenceError);
    await expect(verifySignedStepUpEvidence(future, "user-1", SECRET, NOW)).rejects.toBeInstanceOf(StepUpEvidenceError);
  });

  it("rejects weak verifier secrets", async () => {
    const token = await createSignedStepUpEvidence("user-1", SECRET, NOW, NONCE);
    await expect(verifySignedStepUpEvidence(token, "user-1", "short", NOW)).rejects.toBeInstanceOf(StepUpEvidenceError);
  });
});
