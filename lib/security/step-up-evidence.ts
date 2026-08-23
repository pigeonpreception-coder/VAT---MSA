export const STEP_UP_WINDOW_MS = 5 * 60_000;
const FUTURE_SKEW_MS = 30_000;

export class StepUpEvidenceError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "StepUpEvidenceError";
  }
}

function encodeBase64Url(bytes: Uint8Array): string {
  let binary = "";
  for (const byte of bytes) binary += String.fromCharCode(byte);
  return btoa(binary).replaceAll("+", "-").replaceAll("/", "_").replace(/=+$/u, "");
}

function decodeBase64Url(value: string): Uint8Array {
  const padded = value.replaceAll("-", "+").replaceAll("_", "/").padEnd(Math.ceil(value.length / 4) * 4, "=");
  const binary = atob(padded);
  return Uint8Array.from(binary, (character) => character.charCodeAt(0));
}

async function hmacKey(secret: string): Promise<CryptoKey> {
  return crypto.subtle.importKey("raw", new TextEncoder().encode(secret), { name: "HMAC", hash: "SHA-256" }, false, ["sign", "verify"]);
}

export async function createSignedStepUpEvidence(actorId: string, secret: string, issuedAtMs = Date.now(), nonce = crypto.randomUUID()): Promise<string> {
  if (secret.length < 32) throw new Error("Step-up evidence secret must contain at least 32 characters.");
  const message = `${actorId}|${issuedAtMs}|${nonce}`;
  const signature = new Uint8Array(await crypto.subtle.sign("HMAC", await hmacKey(secret), new TextEncoder().encode(message)));
  return `v1.${issuedAtMs}.${nonce}.${encodeBase64Url(signature)}`;
}

export async function verifySignedStepUpEvidence(token: string, actorId: string, secret: string, nowMs = Date.now()): Promise<{ issuedAtMs: number; expiresAtMs: number }> {
  if (secret.length < 32) throw new StepUpEvidenceError("Privileged changes are unavailable because step-up verification is not configured.");
  const [version, issuedAtRaw, nonce, signatureRaw, ...unexpected] = token.split(".");
  const issuedAtMs = Number(issuedAtRaw);
  if (version !== "v1" || unexpected.length || !Number.isSafeInteger(issuedAtMs) || !/^[A-Za-z0-9_-]{16,128}$/u.test(nonce ?? "") || !/^[A-Za-z0-9_-]{32,128}$/u.test(signatureRaw ?? "")) {
    throw new StepUpEvidenceError("The step-up authentication evidence is malformed.");
  }
  if (issuedAtMs > nowMs + FUTURE_SKEW_MS || nowMs - issuedAtMs > STEP_UP_WINDOW_MS) {
    throw new StepUpEvidenceError("The step-up authentication evidence is expired or has an invalid timestamp.");
  }

  let signature: Uint8Array;
  try {
    signature = decodeBase64Url(signatureRaw);
  } catch {
    throw new StepUpEvidenceError("The step-up authentication evidence is malformed.");
  }
  const message = `${actorId}|${issuedAtMs}|${nonce}`;
  const valid = await crypto.subtle.verify("HMAC", await hmacKey(secret), Uint8Array.from(signature), new TextEncoder().encode(message));
  if (!valid) throw new StepUpEvidenceError("The step-up authentication evidence is invalid.");
  return { issuedAtMs, expiresAtMs: issuedAtMs + STEP_UP_WINDOW_MS };
}
