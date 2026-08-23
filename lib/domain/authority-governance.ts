export type AuthorityOnboardingSubmission = {
  schema_version: "1.0.0";
  tax_authority_id: string;
  target_environment: "LOCAL_STAGING" | "PRODUCTION";
  purpose: string;
  evidence_bundle_hash?: string;
  readiness_reference?: string;
};

export type AuthorityOnboardingDecisionSubmission = {
  schema_version: "1.0.0";
  decision: "APPROVE_LOCAL_STAGING" | "REJECT";
  reason: string;
};

export class AuthorityGovernanceValidationError extends Error {
  readonly code: string;

  constructor(code: string, message: string) {
    super(message);
    this.name = "AuthorityGovernanceValidationError";
    this.code = code;
  }
}

function objectPayload(payload: unknown): Record<string, unknown> {
  if (!payload || typeof payload !== "object" || Array.isArray(payload)) {
    throw new AuthorityGovernanceValidationError("AUTHORITY_GOVERNANCE_BODY_INVALID", "The authority-governance request must be an object.");
  }
  return payload as Record<string, unknown>;
}

function strictKeys(input: Record<string, unknown>, permitted: ReadonlySet<string>): void {
  const unknown = Object.keys(input).find((key) => !permitted.has(key));
  if (unknown) throw new AuthorityGovernanceValidationError("AUTHORITY_GOVERNANCE_FIELD_UNKNOWN", `Unsupported authority-governance field: ${unknown}.`);
}

function text(value: unknown, label: string, minimum: number, maximum: number): string {
  if (typeof value !== "string") throw new AuthorityGovernanceValidationError("AUTHORITY_GOVERNANCE_FIELD_INVALID", `${label} is required.`);
  const normalized = value.normalize("NFKC").trim().replaceAll(/\s+/gu, " ");
  if (normalized.length < minimum || normalized.length > maximum) {
    throw new AuthorityGovernanceValidationError("AUTHORITY_GOVERNANCE_FIELD_INVALID", `${label} must contain ${minimum} to ${maximum} characters.`);
  }
  return normalized;
}

function optionalReference(value: unknown, label: string): string | undefined {
  if (value === undefined || value === null || value === "") return undefined;
  const normalized = text(value, label, 8, 200);
  if (!/^[A-Za-z0-9][A-Za-z0-9 ._:/+-]*$/u.test(normalized)) {
    throw new AuthorityGovernanceValidationError("AUTHORITY_GOVERNANCE_REFERENCE_INVALID", `${label} contains unsupported characters.`);
  }
  return normalized;
}

export function normalizeAuthorityOnboardingSubmission(payload: unknown): AuthorityOnboardingSubmission {
  const input = objectPayload(payload);
  strictKeys(input, new Set(["schema_version", "tax_authority_id", "target_environment", "purpose", "evidence_bundle_hash", "readiness_reference"]));
  if (input.schema_version !== "1.0.0") throw new AuthorityGovernanceValidationError("AUTHORITY_GOVERNANCE_SCHEMA_UNSUPPORTED", "schema_version must be 1.0.0.");
  const authorityId = text(input.tax_authority_id, "Tax Authority", 3, 100);
  if (!/^[A-Za-z0-9][A-Za-z0-9._:-]*$/u.test(authorityId)) {
    throw new AuthorityGovernanceValidationError("TAX_AUTHORITY_ID_INVALID", "Tax Authority contains unsupported characters.");
  }
  const environment = typeof input.target_environment === "string" ? input.target_environment.trim().toUpperCase() : "";
  if (environment !== "LOCAL_STAGING" && environment !== "PRODUCTION") {
    throw new AuthorityGovernanceValidationError("AUTHORITY_ENVIRONMENT_INVALID", "target_environment must be LOCAL_STAGING or PRODUCTION.");
  }
  const evidenceBundleHash = optionalReference(input.evidence_bundle_hash, "Evidence bundle hash");
  if (evidenceBundleHash && evidenceBundleHash.length < 32) {
    throw new AuthorityGovernanceValidationError("AUTHORITY_EVIDENCE_HASH_INVALID", "Evidence bundle hash must contain at least 32 characters.");
  }
  const readinessReference = optionalReference(input.readiness_reference, "Readiness reference");
  return {
    schema_version: "1.0.0",
    tax_authority_id: authorityId,
    target_environment: environment,
    purpose: text(input.purpose, "Purpose", 10, 500),
    ...(evidenceBundleHash ? { evidence_bundle_hash: evidenceBundleHash } : {}),
    ...(readinessReference ? { readiness_reference: readinessReference } : {}),
  };
}

export function normalizeAuthorityOnboardingDecision(payload: unknown): AuthorityOnboardingDecisionSubmission {
  const input = objectPayload(payload);
  strictKeys(input, new Set(["schema_version", "decision", "reason"]));
  if (input.schema_version !== "1.0.0") throw new AuthorityGovernanceValidationError("AUTHORITY_GOVERNANCE_SCHEMA_UNSUPPORTED", "schema_version must be 1.0.0.");
  const decision = typeof input.decision === "string" ? input.decision.trim().toUpperCase() : "";
  if (decision !== "APPROVE_LOCAL_STAGING" && decision !== "REJECT") {
    throw new AuthorityGovernanceValidationError("AUTHORITY_DECISION_INVALID", "decision must be APPROVE_LOCAL_STAGING or REJECT.");
  }
  return { schema_version: "1.0.0", decision, reason: text(input.reason, "Decision reason", 10, 500) };
}

export function authorityGovernanceLocalWritesEnabled(): boolean {
  const environment = (process.env.VAT_MSA_ENVIRONMENT ?? "local").trim().toLowerCase();
  if (environment === "local") return process.env.NODE_ENV !== "production";
  return environment === "staging" && process.env.VAT_MSA_ENABLE_SYNTHETIC_AUTHORITY_GOVERNANCE === "true";
}
