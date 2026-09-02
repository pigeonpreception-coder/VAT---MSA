import { IdentityValidationError } from "@/lib/domain/identity";

/**
 * Security fix 2026-08-27 (SECURITY_GAP_ASSESSMENT.md item #2): a real,
 * standards-compliant TOTP (RFC 6238) implementation, built entirely from
 * Web Crypto primitives already available in this Workers runtime — no
 * external MFA provider or vendor integration required. This is what
 * closes the gap: previously "step-up" was a header
 * (x-vat-msa-auth-assurance/x-vat-msa-reauthenticated-at) the *caller*
 * supplied and lib/security/step-up.ts trusted verbatim, with no
 * server-side verification of any kind — every "step-up gated" command
 * was therefore effectively ungated. This module never persists anything
 * itself (see lib/data/mfa-repository.ts for that); it is pure algorithm
 * and validation, matching this codebase's lib/domain layering.
 */

const BASE32_ALPHABET = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
const TOTP_STEP_SECONDS = 30;
const TOTP_DIGITS = 6;

function base32Encode(bytes: Uint8Array): string {
  let bits = 0;
  let value = 0;
  let output = "";
  for (const byte of bytes) {
    value = (value << 8) | byte;
    bits += 8;
    while (bits >= 5) {
      output += BASE32_ALPHABET[(value >>> (bits - 5)) & 31];
      bits -= 5;
    }
  }
  if (bits > 0) output += BASE32_ALPHABET[(value << (5 - bits)) & 31];
  return output;
}

function base32Decode(input: string): Uint8Array {
  const clean = input.toUpperCase().replace(/[^A-Z2-7]/g, "");
  let bits = 0;
  let value = 0;
  const bytes: number[] = [];
  for (const char of clean) {
    const index = BASE32_ALPHABET.indexOf(char);
    if (index === -1) continue;
    value = (value << 5) | index;
    bits += 5;
    if (bits >= 8) {
      bytes.push((value >>> (bits - 8)) & 0xff);
      bits -= 8;
    }
  }
  return new Uint8Array(bytes);
}

/** Generates a fresh random TOTP secret (20 bytes = 160 bits, the RFC 4226-recommended minimum), base32-encoded for QR/manual entry. */
export function generateTotpSecret(): string {
  const bytes = crypto.getRandomValues(new Uint8Array(20));
  return base32Encode(bytes);
}

async function hotp(secretBytes: Uint8Array, counter: number): Promise<string> {
  const counterBytes = new ArrayBuffer(8);
  new DataView(counterBytes).setUint32(4, counter, false);
  const key = await crypto.subtle.importKey("raw", secretBytes.buffer as ArrayBuffer, { name: "HMAC", hash: "SHA-1" }, false, ["sign"]);
  const signature = new Uint8Array(await crypto.subtle.sign("HMAC", key, counterBytes));
  const offset = signature[signature.length - 1] & 0x0f;
  const binary = ((signature[offset] & 0x7f) << 24) | ((signature[offset + 1] & 0xff) << 16) | ((signature[offset + 2] & 0xff) << 8) | (signature[offset + 3] & 0xff);
  return (binary % 10 ** TOTP_DIGITS).toString().padStart(TOTP_DIGITS, "0");
}

function counterForTime(nowMs: number): number {
  return Math.floor(nowMs / 1000 / TOTP_STEP_SECONDS);
}

/**
 * Computes the current 6-digit code for a secret — the exact operation an
 * authenticator app performs client-side. No production code path in this
 * repository calls this (only the user's own authenticator app ever
 * generates a code); it exists so this algorithm has one shared, tested
 * implementation rather than being duplicated ad hoc anywhere a test needs
 * to produce a valid code (verification and generation are the same HOTP
 * primitive evaluated at the same counter).
 */
export async function generateTotpCode(secretBase32: string, nowMs = Date.now()): Promise<string> {
  return hotp(base32Decode(secretBase32), counterForTime(nowMs));
}

/**
 * Verifies a 6-digit code against a base32 secret, tolerating one 30-second
 * step of clock drift either side (the standard TOTP allowance). Returns
 * the matched HOTP counter on success — the caller persists this as
 * `last_used_counter` so the same code (or an earlier one) can never be
 * replayed, or `null` if no candidate step matched.
 */
export async function verifyTotpCode(secretBase32: string, code: string, nowMs = Date.now()): Promise<number | null> {
  const secretBytes = base32Decode(secretBase32);
  const currentCounter = counterForTime(nowMs);
  for (let drift = -1; drift <= 1; drift++) {
    const counter = currentCounter + drift;
    if (counter < 0) continue;
    if ((await hotp(secretBytes, counter)) === code) return counter;
  }
  return null;
}

export function validateTotpCode(payload: unknown): string {
  const input = payload && typeof payload === "object" && !Array.isArray(payload) ? (payload as Record<string, unknown>) : {};
  const code = typeof input.code === "string" ? input.code.trim() : "";
  if (!/^\d{6}$/.test(code)) {
    throw new IdentityValidationError([{ code: "CODE_INVALID", path: "/code", message: "code must be exactly 6 digits." }]);
  }
  return code;
}

/** An otpauth:// URI an authenticator app (Google Authenticator, Authy, 1Password, etc.) can enrol directly — a standard client the user already owns, not a vendor integration this platform must contract for. */
export function totpAuthUri(secretBase32: string, accountLabel: string, issuer = "VAT-MSA"): string {
  const params = new URLSearchParams({ secret: secretBase32, issuer, algorithm: "SHA1", digits: String(TOTP_DIGITS), period: String(TOTP_STEP_SECONDS) });
  return `otpauth://totp/${encodeURIComponent(issuer)}:${encodeURIComponent(accountLabel)}?${params.toString()}`;
}
