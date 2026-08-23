export const STEP_UP_WINDOW_MS = 5 * 60_000;
const FUTURE_SKEW_MS = 30_000;
const TOKEN_VERSION = "v2";

export type StepUpEvidenceInput = {
  actorId: string;
  authenticationMethods: string[];
  audience: string;
  issuer: string;
  origin: string;
  sessionId: string;
  secret: string;
  authenticatedAtMs?: number;
  issuedAtMs?: number;
  nonce?: string;
};

export type StepUpEvidenceVerification = {
  actorId: string;
  audience: string;
  issuer: string;
  origin: string;
  sessionId: string;
  secret: string;
  nowMs?: number;
};

type StepUpClaims = {
  amr: string[];
  aud: string;
  auth_time: number;
  exp: number;
  iat: number;
  iss: string;
  jti: string;
  origin: string;
  sid: string;
  sub: string;
};

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

function decodeJson(value: string): unknown {
  const bytes = decodeBase64Url(value);
  return JSON.parse(new TextDecoder().decode(bytes));
}

async function hmacKey(secret: string): Promise<CryptoKey> {
  return crypto.subtle.importKey("raw", new TextEncoder().encode(secret), { name: "HMAC", hash: "SHA-256" }, false, ["sign", "verify"]);
}

export async function createSignedStepUpEvidence(input: StepUpEvidenceInput): Promise<string> {
  if (input.secret.length < 32) throw new Error("Step-up evidence secret must contain at least 32 characters.");
  const issuedAtMs = input.issuedAtMs ?? Date.now();
  const claims: StepUpClaims = {
    amr: input.authenticationMethods,
    aud: input.audience,
    auth_time: input.authenticatedAtMs ?? issuedAtMs,
    exp: issuedAtMs + STEP_UP_WINDOW_MS,
    iat: issuedAtMs,
    iss: input.issuer,
    jti: input.nonce ?? crypto.randomUUID(),
    origin: input.origin,
    sid: input.sessionId,
    sub: input.actorId,
  };
  if (!isStepUpClaims(claims)) throw new Error("Step-up evidence claims are invalid or do not assert MFA.");
  const encodedClaims = encodeBase64Url(new TextEncoder().encode(JSON.stringify(claims)));
  const message = `${TOKEN_VERSION}.${encodedClaims}`;
  const signature = new Uint8Array(await crypto.subtle.sign("HMAC", await hmacKey(input.secret), new TextEncoder().encode(message)));
  return `${message}.${encodeBase64Url(signature)}`;
}

export async function verifySignedStepUpEvidence(token: string, expected: StepUpEvidenceVerification): Promise<{ issuedAtMs: number; expiresAtMs: number }> {
  if (expected.secret.length < 32 || !expected.issuer) throw new StepUpEvidenceError("Privileged changes are unavailable because step-up verification is not configured.");
  const [version, claimsRaw, signatureRaw, ...unexpected] = token.split(".");
  if (version !== TOKEN_VERSION || unexpected.length || !/^[A-Za-z0-9_-]{32,2048}$/u.test(claimsRaw ?? "") || !/^[A-Za-z0-9_-]{32,128}$/u.test(signatureRaw ?? "")) {
    throw new StepUpEvidenceError("The step-up authentication evidence is malformed.");
  }

  let signature: Uint8Array;
  let claims: unknown;
  try {
    signature = decodeBase64Url(signatureRaw);
    claims = decodeJson(claimsRaw);
  } catch {
    throw new StepUpEvidenceError("The step-up authentication evidence is malformed.");
  }
  const message = `${TOKEN_VERSION}.${claimsRaw}`;
  const valid = await crypto.subtle.verify("HMAC", await hmacKey(expected.secret), Uint8Array.from(signature), new TextEncoder().encode(message));
  if (!valid) throw new StepUpEvidenceError("The step-up authentication evidence is invalid.");

  if (!isStepUpClaims(claims)) throw new StepUpEvidenceError("The step-up authentication evidence is malformed.");
  if (claims.iss !== expected.issuer || claims.sub !== expected.actorId || claims.aud !== expected.audience || claims.origin !== expected.origin || claims.sid !== expected.sessionId) {
    throw new StepUpEvidenceError("The step-up authentication evidence is not valid for this actor, session, origin, or action.");
  }
  const nowMs = expected.nowMs ?? Date.now();
  if (
    claims.iat > nowMs + FUTURE_SKEW_MS
    || claims.auth_time > nowMs + FUTURE_SKEW_MS
    || claims.auth_time > claims.iat + FUTURE_SKEW_MS
    || claims.exp <= nowMs
    || claims.exp - claims.iat !== STEP_UP_WINDOW_MS
    || nowMs - claims.auth_time > STEP_UP_WINDOW_MS
  ) {
    throw new StepUpEvidenceError("The step-up authentication evidence is expired or has an invalid timestamp.");
  }
  return { issuedAtMs: claims.iat, expiresAtMs: claims.exp };
}

function isStepUpClaims(value: unknown): value is StepUpClaims {
  if (!value || typeof value !== "object" || Array.isArray(value)) return false;
  const claims = value as Partial<StepUpClaims>;
  return (
    Array.isArray(claims.amr)
    && claims.amr.length > 0
    && claims.amr.every((method) => typeof method === "string")
    && claims.amr.includes("mfa")
    && typeof claims.aud === "string"
    && claims.aud.length > 0
    && Number.isSafeInteger(claims.auth_time)
    && Number.isSafeInteger(claims.exp)
    && Number.isSafeInteger(claims.iat)
    && typeof claims.iss === "string"
    && claims.iss.length > 0
    && typeof claims.jti === "string"
    && /^[A-Za-z0-9_-]{16,128}$/u.test(claims.jti)
    && typeof claims.origin === "string"
    && /^https?:\/\/[^/]+$/u.test(claims.origin)
    && typeof claims.sid === "string"
    && /^[A-Za-z0-9_-]{16,128}$/u.test(claims.sid)
    && typeof claims.sub === "string"
    && claims.sub.length > 0
  );
}
