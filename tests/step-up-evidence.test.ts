import { describe, expect, it } from "vitest";
import { createSignedStepUpEvidence, StepUpEvidenceError, verifySignedStepUpEvidence } from "@/lib/security/step-up-evidence";

const SECRET = "synthetic-step-up-secret-for-tests-only-0001";
const NOW = Date.parse("2026-08-23T12:00:00.000Z");
const NONCE = "synthetic_nonce_00000001";
const SESSION_ID = "synthetic_session_000001";
const BASE_INPUT = {
  actorId: "user-1",
  authenticationMethods: ["pwd", "mfa"],
  audience: "POST /api/v1/organisations/employees",
  issuer: "https://identity.test.example",
  origin: "https://vat.test.example",
  sessionId: SESSION_ID,
  secret: SECRET,
};

function verification(overrides: Partial<Parameters<typeof verifySignedStepUpEvidence>[1]> = {}): Parameters<typeof verifySignedStepUpEvidence>[1] {
  return { ...BASE_INPUT, nowMs: NOW, ...overrides };
}

describe("signed step-up evidence", () => {
  it("accepts fresh MFA evidence bound to actor, session, origin and action", async () => {
    const token = await createSignedStepUpEvidence({ ...BASE_INPUT, issuedAtMs: NOW - 60_000, nonce: NONCE });
    await expect(verifySignedStepUpEvidence(token, verification())).resolves.toEqual({
      issuedAtMs: NOW - 60_000,
      expiresAtMs: NOW + 240_000,
    });
  });

  it("rejects tampering and cross-user replay", async () => {
    const token = await createSignedStepUpEvidence({ ...BASE_INPUT, issuedAtMs: NOW, nonce: NONCE });
    await expect(verifySignedStepUpEvidence(token, verification({ actorId: "user-2" }))).rejects.toBeInstanceOf(StepUpEvidenceError);
    await expect(verifySignedStepUpEvidence(`${token.slice(0, -1)}A`, verification())).rejects.toBeInstanceOf(StepUpEvidenceError);
  });

  it("rejects expired and materially future-dated evidence", async () => {
    const expired = await createSignedStepUpEvidence({ ...BASE_INPUT, issuedAtMs: NOW - 300_001, nonce: NONCE });
    const future = await createSignedStepUpEvidence({ ...BASE_INPUT, issuedAtMs: NOW + 30_001, nonce: NONCE });
    await expect(verifySignedStepUpEvidence(expired, verification())).rejects.toBeInstanceOf(StepUpEvidenceError);
    await expect(verifySignedStepUpEvidence(future, verification())).rejects.toBeInstanceOf(StepUpEvidenceError);
  });

  it("rejects weak verifier secrets", async () => {
    const token = await createSignedStepUpEvidence({ ...BASE_INPUT, issuedAtMs: NOW, nonce: NONCE });
    await expect(verifySignedStepUpEvidence(token, verification({ secret: "short" }))).rejects.toBeInstanceOf(StepUpEvidenceError);
  });

  it("rejects evidence replayed to another session, origin, action or issuer", async () => {
    const token = await createSignedStepUpEvidence({ ...BASE_INPUT, issuedAtMs: NOW, nonce: NONCE });
    for (const changed of [
      { sessionId: "different_session_000001" },
      { origin: "https://other.test.example" },
      { audience: "POST /api/v1/organisations/roles" },
      { issuer: "https://forged.test.example" },
    ]) {
      await expect(verifySignedStepUpEvidence(token, verification(changed))).rejects.toBeInstanceOf(StepUpEvidenceError);
    }
  });

  it("rejects stale authentication even when the assertion was issued recently", async () => {
    const token = await createSignedStepUpEvidence({
      ...BASE_INPUT,
      authenticatedAtMs: NOW - 300_001,
      issuedAtMs: NOW,
      nonce: NONCE,
    });
    await expect(verifySignedStepUpEvidence(token, verification())).rejects.toBeInstanceOf(StepUpEvidenceError);
  });

  it("refuses to sign evidence that does not explicitly assert MFA", async () => {
    await expect(createSignedStepUpEvidence({
      ...BASE_INPUT,
      authenticationMethods: ["pwd"],
      issuedAtMs: NOW,
      nonce: NONCE,
    })).rejects.toThrow(/assert MFA/u);
  });
});
