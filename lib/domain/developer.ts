/**
 * Module 10 Phase D: the Developer platform (DeveloperAccount/APIClient/
 * CredentialRef/TestRun). Pure validation plus RunConformance's own
 * check harness — no DB access. Mirrors lib/domain/saas.ts/integration.ts's
 * own local object/text/bounded helpers rather than importing them.
 */

export type DeveloperValidationMessage = { code: string; path: string; message: string };

export class DeveloperValidationError extends Error {
  readonly messages: DeveloperValidationMessage[];

  constructor(messages: DeveloperValidationMessage[]) {
    super("Developer platform command failed validation.");
    this.name = "DeveloperValidationError";
    this.messages = messages;
  }
}

function object(payload: unknown) {
  if (!payload || typeof payload !== "object" || Array.isArray(payload)) throw new DeveloperValidationError([{ code: "DOCUMENT_INVALID", path: "/", message: "The request body must be an object." }]);
  return payload as Record<string, unknown>;
}

function text(value: unknown) {
  return typeof value === "string" ? value.trim().replaceAll(/\s+/g, " ") : "";
}

function bounded(value: unknown, path: string, label: string, min: number, max: number, messages: DeveloperValidationMessage[]) {
  const normalized = text(value);
  if (normalized.length < min || normalized.length > max) messages.push({ code: "FIELD_LENGTH_INVALID", path, message: `${label} must contain ${min} to ${max} characters.` });
  return normalized;
}

function schema(input: Record<string, unknown>, messages: DeveloperValidationMessage[]) {
  if (input.schema_version !== "1.0.0") messages.push({ code: "SCHEMA_VERSION_UNSUPPORTED", path: "/schema_version", message: "schema_version must be 1.0.0." });
}

const SCOPE_PATTERN = /^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)+$/;
export const RATE_LIMIT_PROFILES = new Set(["SANDBOX", "PILOT_STANDARD", "PILOT_ELEVATED"]);

export type ClientCreation = { schema_version: "1.0.0"; name: string; scopes: string[]; rate_limit_profile: string };

export function validateClientCreation(payload: unknown): ClientCreation {
  const input = object(payload);
  const messages: DeveloperValidationMessage[] = [];
  schema(input, messages);
  const name = bounded(input.name, "/name", "Name", 3, 150, messages);
  const scopesInput = Array.isArray(input.scopes) ? input.scopes : [];
  if (scopesInput.length === 0) messages.push({ code: "SCOPES_REQUIRED", path: "/scopes", message: "At least one scope is required." });
  const scopes = scopesInput.map((value) => text(value).toLowerCase()).filter(Boolean);
  for (const scope of scopes) {
    if (!SCOPE_PATTERN.test(scope)) messages.push({ code: "SCOPE_INVALID", path: "/scopes", message: `Scope "${scope}" must use the form resource.action (lowercase letters/numbers, dot-separated).` });
  }
  const rateLimitProfile = text(input.rate_limit_profile).toUpperCase();
  if (!RATE_LIMIT_PROFILES.has(rateLimitProfile)) messages.push({ code: "RATE_LIMIT_PROFILE_INVALID", path: "/rate_limit_profile", message: `rate_limit_profile must be one of: ${[...RATE_LIMIT_PROFILES].join(", ")}.` });
  if (messages.length) throw new DeveloperValidationError(messages);
  return { schema_version: "1.0.0", name, scopes, rate_limit_profile: rateLimitProfile };
}

export type CredentialRevocation = { schema_version: "1.0.0"; reason: string };

export function validateCredentialRevocation(payload: unknown): CredentialRevocation {
  const input = object(payload);
  const messages: DeveloperValidationMessage[] = [];
  schema(input, messages);
  const reason = bounded(input.reason, "/reason", "Reason", 10, 500, messages);
  if (messages.length) throw new DeveloperValidationError(messages);
  return { schema_version: "1.0.0", reason };
}

export type DeveloperConformanceCheck = { code: string; status: "PASS" | "FAIL" | "NOT_CONFIGURED"; rationale: string };

export const TEST_SUITE_VERSION = "1.0";

type ConformanceInput = {
  scopes: string[];
  rateLimitProfile: string;
  clientStatus: string;
  currentCredentialStatus: string | null;
};

/**
 * RunConformance's harness — a fixed, code-versioned catalogue of
 * explainable checks, the same shape Module 9 Phase B / Module 10 Phase C
 * already established (named, deterministic, PASS/FAIL with a rationale).
 * CREDENTIAL_ISSUED checks this platform's own internal credential_refs
 * bookkeeping (genuinely PASSable today); EXTERNAL_CREDENTIAL_PROVISIONED
 * is deliberately NOT_CONFIGURED and non-blocking — this codebase has no
 * real secret manager integration to mint a live production credential
 * (api_clients.status stays PENDING_CREDENTIAL_PROVISIONING forever in
 * this phase, matching that column's own pre-existing seeded value), and
 * persisting that honestly rather than silently passing or skipping it
 * matches Module 9 Phase B's IDENTITY_VERIFICATION/BANK_ACCOUNT_OWNERSHIP/
 * SANCTIONS_SCREENING precedent exactly.
 */
export function evaluateClientConformance(input: ConformanceInput): DeveloperConformanceCheck[] {
  const checks: DeveloperConformanceCheck[] = [];

  checks.push(input.scopes.length > 0 && input.scopes.every((scope) => SCOPE_PATTERN.test(scope))
    ? { code: "SCOPES_DECLARED", status: "PASS", rationale: `${input.scopes.length} valid scope(s) declared.` }
    : { code: "SCOPES_DECLARED", status: "FAIL", rationale: "No valid scopes are declared for this client." });

  checks.push(RATE_LIMIT_PROFILES.has(input.rateLimitProfile)
    ? { code: "RATE_LIMIT_PROFILE_KNOWN", status: "PASS", rationale: `${input.rateLimitProfile} is a recognised rate-limit profile.` }
    : { code: "RATE_LIMIT_PROFILE_KNOWN", status: "FAIL", rationale: `${input.rateLimitProfile} is not a recognised rate-limit profile.` });

  checks.push(input.clientStatus !== "REVOKED"
    ? { code: "CLIENT_OPERATIONAL", status: "PASS", rationale: "The client has not been revoked." }
    : { code: "CLIENT_OPERATIONAL", status: "FAIL", rationale: "This client's credential has been revoked." });

  checks.push(input.currentCredentialStatus === "ACTIVE"
    ? { code: "CREDENTIAL_ISSUED", status: "PASS", rationale: "A credential reference is on record and marked ACTIVE." }
    : { code: "CREDENTIAL_ISSUED", status: "FAIL", rationale: `No ACTIVE credential reference is on record (current: ${input.currentCredentialStatus ?? "none"}).` });

  checks.push({ code: "EXTERNAL_CREDENTIAL_PROVISIONED", status: "NOT_CONFIGURED", rationale: "No external secret manager is integrated in this environment — the credential reference is a pointer, never a live secret. Advisory only; does not block conformance." });

  return checks;
}

export function conformanceOutcome(checks: DeveloperConformanceCheck[]): "PASSED" | "FAILED" {
  return checks.some((check) => check.status === "FAIL") ? "FAILED" : "PASSED";
}
