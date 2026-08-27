import { describe, expect, it } from "vitest";
import { IdentityValidationError } from "@/lib/domain/identity";
import { generateTotpCode, generateTotpSecret, totpAuthUri, validateTotpCode, verifyTotpCode } from "@/lib/domain/mfa";

/**
 * Security fix 2026-08-27 (SECURITY_GAP_ASSESSMENT.md item #2): the pure
 * RFC 6238 TOTP algorithm lib/security/step-up.ts's real, server-verified
 * step-up now relies on, in place of the previous client-asserted
 * x-vat-msa-auth-assurance/x-vat-msa-reauthenticated-at headers. See
 * tests/routes/security-mfa-step-up.test.ts for the enrolment/step-up
 * command surface built on top of this.
 */
describe("MFA/TOTP domain (RFC 6238)", () => {
  it("generates a base32 secret using only the standard alphabet", () => {
    const secret = generateTotpSecret();
    expect(secret).toMatch(/^[A-Z2-7]+$/);
    expect(secret.length).toBeGreaterThanOrEqual(32);
  });

  it("round-trips: a code generated for a secret verifies against that same secret", async () => {
    const secret = generateTotpSecret();
    const now = Date.parse("2026-08-27T10:00:00Z");
    const code = await generateTotpCode(secret, now);
    expect(code).toMatch(/^\d{6}$/);
    const matchedCounter = await verifyTotpCode(secret, code, now);
    expect(matchedCounter).not.toBeNull();
  });

  it("rejects a code generated from a different secret", async () => {
    const secretA = generateTotpSecret();
    const secretB = generateTotpSecret();
    const now = Date.parse("2026-08-27T10:00:00Z");
    const codeForB = await generateTotpCode(secretB, now);
    expect(await verifyTotpCode(secretA, codeForB, now)).toBeNull();
  });

  it("tolerates one 30-second step of clock drift either side", async () => {
    const secret = generateTotpSecret();
    const now = Date.parse("2026-08-27T10:00:00Z");
    const codeOneStepEarlier = await generateTotpCode(secret, now - 30_000);
    const codeOneStepLater = await generateTotpCode(secret, now + 30_000);
    expect(await verifyTotpCode(secret, codeOneStepEarlier, now)).not.toBeNull();
    expect(await verifyTotpCode(secret, codeOneStepLater, now)).not.toBeNull();
  });

  it("rejects a code more than one step outside the current window", async () => {
    const secret = generateTotpSecret();
    const now = Date.parse("2026-08-27T10:00:00Z");
    const codeTwoStepsEarlier = await generateTotpCode(secret, now - 90_000);
    expect(await verifyTotpCode(secret, codeTwoStepsEarlier, now)).toBeNull();
  });

  it("returns increasing counters for later time steps, the anti-replay ordering confirmStepUp relies on", async () => {
    const secret = generateTotpSecret();
    const now = Date.parse("2026-08-27T10:00:00Z");
    const earlierCode = await generateTotpCode(secret, now);
    const laterCode = await generateTotpCode(secret, now + 30_000);
    const earlierCounter = await verifyTotpCode(secret, earlierCode, now);
    const laterCounter = await verifyTotpCode(secret, laterCode, now + 30_000);
    expect(laterCounter).toBeGreaterThan(earlierCounter!);
  });

  it("validates a well-formed 6-digit code and rejects malformed ones", () => {
    expect(validateTotpCode({ code: "123456" })).toBe("123456");
    expect(() => validateTotpCode({ code: "12345" })).toThrowError(IdentityValidationError);
    expect(() => validateTotpCode({ code: "abcdef" })).toThrowError(IdentityValidationError);
    expect(() => validateTotpCode({})).toThrowError(IdentityValidationError);
    expect(() => validateTotpCode(null)).toThrowError(IdentityValidationError);
  });

  it("produces a standard otpauth:// URI carrying the secret and algorithm parameters", () => {
    const uri = totpAuthUri("JBSWY3DPEHPK3PXP", "owner@example.test");
    expect(uri).toMatch(/^otpauth:\/\/totp\//);
    expect(uri).toContain("secret=JBSWY3DPEHPK3PXP");
    expect(uri).toContain("algorithm=SHA1");
    expect(uri).toContain("digits=6");
    expect(uri).toContain("period=30");
  });
});
