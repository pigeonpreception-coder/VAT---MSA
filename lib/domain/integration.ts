/**
 * Module 10 Phase A: pure validation and the connection lifecycle state
 * machine for the generic, provider-agnostic connector model (Integration
 * aggregate / integration_connections). No DB access — mirrors
 * lib/domain/compliance.ts's own local object/text/bounded helpers rather
 * than importing them, matching this codebase's convention of each domain
 * file owning its own tiny validation primitives.
 */

export type IntegrationValidationMessage = { code: string; path: string; message: string };

export class IntegrationValidationError extends Error {
  readonly messages: IntegrationValidationMessage[];

  constructor(messages: IntegrationValidationMessage[]) {
    super("Integration command failed validation.");
    this.name = "IntegrationValidationError";
    this.messages = messages;
  }
}

function object(payload: unknown) {
  if (!payload || typeof payload !== "object" || Array.isArray(payload)) throw new IntegrationValidationError([{ code: "DOCUMENT_INVALID", path: "/", message: "The request body must be an object." }]);
  return payload as Record<string, unknown>;
}

function text(value: unknown) {
  return typeof value === "string" ? value.trim().replaceAll(/\s+/g, " ") : "";
}

function bounded(value: unknown, path: string, label: string, min: number, max: number, messages: IntegrationValidationMessage[]) {
  const normalized = text(value);
  if (normalized.length < min || normalized.length > max) messages.push({ code: "FIELD_LENGTH_INVALID", path, message: `${label} must contain ${min} to ${max} characters.` });
  return normalized;
}

/** Like bounded(), but absent entirely is fine — only a present-and-too-short/too-long value is rejected. */
function optionalBounded(value: unknown, path: string, label: string, min: number, max: number, messages: IntegrationValidationMessage[]) {
  const normalized = text(value);
  if (!normalized) return undefined;
  if (normalized.length < min || normalized.length > max) messages.push({ code: "FIELD_LENGTH_INVALID", path, message: `${label} must contain ${min} to ${max} characters.` });
  return normalized;
}

function schema(input: Record<string, unknown>, messages: IntegrationValidationMessage[]) {
  if (input.schema_version !== "1.0.0") messages.push({ code: "SCHEMA_VERSION_UNSUPPORTED", path: "/schema_version", message: "schema_version must be 1.0.0." });
}

const PROVIDER_KEY_PATTERN = /^[A-Z][A-Z0-9_]{1,49}$/;
const CAPABILITY_PATTERN = /^[A-Z][A-Z0-9_]{1,49}$/;
const CATEGORY_VALUES = new Set(["GOVERNMENT", "BANKING", "PAYMENT", "ACCOUNTING", "ERP", "LOGISTICS", "OTHER"]);
const DATA_CLASSIFICATION_VALUES = new Set(["PUBLIC", "INTERNAL", "CONFIDENTIAL", "TAX_CONFIDENTIAL", "RESTRICTED"]);

export type IntegrationRegistration = {
  schema_version: "1.0.0";
  provider_key: string;
  category: string;
  display_name: string;
  capabilities: string[];
  data_classification: string;
  endpoint_reference?: string;
  credential_reference?: string;
};

/** RegisterIntegration. Deliberately provider-agnostic — no ITAS/BIPA/bank/treasury-specific fields — so any SaaS/ERP provider registers through this exact same shape, per the playbook's own instruction. */
export function validateIntegrationRegistration(payload: unknown): IntegrationRegistration {
  const input = object(payload);
  const messages: IntegrationValidationMessage[] = [];
  schema(input, messages);
  const providerKey = text(input.provider_key).toUpperCase();
  if (!PROVIDER_KEY_PATTERN.test(providerKey)) messages.push({ code: "PROVIDER_KEY_INVALID", path: "/provider_key", message: "provider_key must be 2 to 50 uppercase letters, numbers or underscores, starting with a letter." });
  const category = text(input.category).toUpperCase();
  if (!CATEGORY_VALUES.has(category)) messages.push({ code: "CATEGORY_INVALID", path: "/category", message: `category must be one of: ${[...CATEGORY_VALUES].join(", ")}.` });
  const displayName = bounded(input.display_name, "/display_name", "Display name", 3, 150, messages);
  const capabilitiesInput = Array.isArray(input.capabilities) ? input.capabilities : [];
  if (capabilitiesInput.length === 0) messages.push({ code: "CAPABILITIES_REQUIRED", path: "/capabilities", message: "At least one capability is required." });
  const capabilities = capabilitiesInput.map((value) => text(value).toUpperCase()).filter(Boolean);
  for (const capability of capabilities) {
    if (!CAPABILITY_PATTERN.test(capability)) messages.push({ code: "CAPABILITY_INVALID", path: "/capabilities", message: `Capability "${capability}" must be 2 to 50 uppercase letters, numbers or underscores.` });
  }
  const dataClassification = text(input.data_classification).toUpperCase();
  if (!DATA_CLASSIFICATION_VALUES.has(dataClassification)) messages.push({ code: "DATA_CLASSIFICATION_INVALID", path: "/data_classification", message: `data_classification must be one of: ${[...DATA_CLASSIFICATION_VALUES].join(", ")}.` });
  const endpointReference = optionalBounded(input.endpoint_reference, "/endpoint_reference", "Endpoint reference", 3, 300, messages);
  const credentialReference = optionalBounded(input.credential_reference, "/credential_reference", "Credential reference", 3, 300, messages);
  if (messages.length) throw new IntegrationValidationError(messages);
  return { schema_version: "1.0.0", provider_key: providerKey, category, display_name: displayName, capabilities, data_classification: dataClassification, ...(endpointReference ? { endpoint_reference: endpointReference } : {}), ...(credentialReference ? { credential_reference: credentialReference } : {}) };
}

export type IntegrationSuspension = { schema_version: "1.0.0"; reason: string };

export function validateIntegrationSuspension(payload: unknown): IntegrationSuspension {
  const input = object(payload);
  const messages: IntegrationValidationMessage[] = [];
  schema(input, messages);
  const reason = bounded(input.reason, "/reason", "Reason", 10, 500, messages);
  if (messages.length) throw new IntegrationValidationError(messages);
  return { schema_version: "1.0.0", reason };
}

export type IntegrationConfigurationStatus = "DRAFT" | "CONFIGURED" | "SUSPENDED";
export type IntegrationAction = "APPROVE" | "SUSPEND";

const INTEGRATION_TRANSITIONS: Record<IntegrationConfigurationStatus, Partial<Record<IntegrationAction, IntegrationConfigurationStatus>>> = {
  DRAFT: { APPROVE: "CONFIGURED" },
  CONFIGURED: { SUSPEND: "SUSPENDED" },
  SUSPENDED: { APPROVE: "CONFIGURED" },
};

/**
 * Only covers connections registered through RegisterIntegration — this
 * phase's own closed DRAFT/CONFIGURED/SUSPENDED enum. The four pre-seeded
 * platform connections (ITAS/BIPA/bank-org1/treasury, db/runtime.ts) carry
 * free-text "REQUIRES_*_CONTRACT" configuration_status values that
 * deliberately fall outside this enum. assertIntegrationTransition looks
 * up the current status in INTEGRATION_TRANSITIONS and finds nothing for
 * an unrecognised value, so it refuses — meaning ApproveIntegration can
 * never be the command that flips one of those genuinely external-gated
 * connections live. That is a structural property of this state machine's
 * closed vocabulary, not a special case bolted on for those four rows —
 * the same "fail closed on an unrecognised state" posture Module 9 Phase D
 * applied to its own payment sandbox guard.
 */
export function assertIntegrationTransition(action: IntegrationAction, current: string): IntegrationConfigurationStatus {
  const rule = INTEGRATION_TRANSITIONS[current as IntegrationConfigurationStatus];
  const target = rule?.[action];
  if (!target) throw new IntegrationValidationError([{ code: "INTEGRATION_TRANSITION_INVALID", path: "/action", message: `Cannot ${action.toLowerCase()} a connection currently ${current}.` }]);
  return target;
}

export type SyncStartSubmission = { schema_version: "1.0.0"; job_type: string; direction: "INBOUND" | "OUTBOUND" };

const JOB_TYPE_PATTERN = /^[A-Z][A-Z0-9_]{1,50}$/;
const DIRECTION_VALUES = new Set(["INBOUND", "OUTBOUND"]);

export function validateSyncStart(payload: unknown): SyncStartSubmission {
  const input = object(payload);
  const messages: IntegrationValidationMessage[] = [];
  schema(input, messages);
  const jobType = text(input.job_type).toUpperCase();
  if (!JOB_TYPE_PATTERN.test(jobType)) messages.push({ code: "JOB_TYPE_INVALID", path: "/job_type", message: "job_type must be 2 to 51 uppercase letters, numbers or underscores." });
  const direction = text(input.direction).toUpperCase();
  if (!DIRECTION_VALUES.has(direction)) messages.push({ code: "DIRECTION_INVALID", path: "/direction", message: "direction must be INBOUND or OUTBOUND." });
  if (messages.length) throw new IntegrationValidationError(messages);
  return { schema_version: "1.0.0", job_type: jobType, direction: direction as "INBOUND" | "OUTBOUND" };
}
