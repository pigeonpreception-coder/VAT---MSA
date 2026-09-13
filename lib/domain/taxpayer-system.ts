/**
 * NamRA e-VAT MS Registered Taxpayer Systems Framework (master prompt section
 * 6): pure validation and the registration lifecycle state machine for a
 * taxpayer's own ERP/POS/accounting/invoicing system. No DB access — mirrors
 * lib/domain/integration.ts's own local object/text/bounded helpers rather
 * than importing them, matching this codebase's convention of each domain
 * file owning its own tiny validation primitives. Identifier format regexes
 * match lib/domain/business.ts's existing vat_number/tin/company_registration_number
 * pattern rather than inventing a stricter Namibian-specific format — the
 * Namibia country compliance pack (08-enterprise-architecture/namra-e-vat-ms's
 * companion, 32-namibia-country-compliance-pack.md) leaves exact identifier
 * format REGULATORY CONFIRMATION REQUIRED, so this stays deliberately loose.
 */

export type TaxpayerSystemValidationMessage = { code: string; path: string; message: string };

export class TaxpayerSystemValidationError extends Error {
  readonly messages: TaxpayerSystemValidationMessage[];

  constructor(messages: TaxpayerSystemValidationMessage[]) {
    super("Taxpayer system command failed validation.");
    this.name = "TaxpayerSystemValidationError";
    this.messages = messages;
  }
}

function object(payload: unknown) {
  if (!payload || typeof payload !== "object" || Array.isArray(payload)) throw new TaxpayerSystemValidationError([{ code: "DOCUMENT_INVALID", path: "/", message: "The request body must be an object." }]);
  return payload as Record<string, unknown>;
}

function text(value: unknown) {
  return typeof value === "string" ? value.trim().replaceAll(/\s+/g, " ") : "";
}

function bounded(value: unknown, path: string, label: string, min: number, max: number, messages: TaxpayerSystemValidationMessage[]) {
  const normalized = text(value);
  if (normalized.length < min || normalized.length > max) messages.push({ code: "FIELD_LENGTH_INVALID", path, message: `${label} must contain ${min} to ${max} characters.` });
  return normalized;
}

/** Like bounded(), but absent entirely is fine — only a present-and-too-short/too-long value is rejected. */
function optionalBounded(value: unknown, path: string, label: string, min: number, max: number, messages: TaxpayerSystemValidationMessage[]) {
  const normalized = text(value);
  if (!normalized) return undefined;
  if (normalized.length < min || normalized.length > max) messages.push({ code: "FIELD_LENGTH_INVALID", path, message: `${label} must contain ${min} to ${max} characters.` });
  return normalized;
}

function schema(input: Record<string, unknown>, messages: TaxpayerSystemValidationMessage[]) {
  if (input.schema_version !== "1.0.0") messages.push({ code: "SCHEMA_VERSION_UNSUPPORTED", path: "/schema_version", message: "schema_version must be 1.0.0." });
}

const IDENTIFIER_PATTERN = /^[A-Z0-9][A-Z0-9 ._/-]{1,39}$/;
const SYSTEM_CATEGORY_VALUES = new Set(["ERP", "POS", "ACCOUNTING", "INVOICING", "OTHER"]);

export type TaxpayerSystemRegistration = {
  schema_version: "1.0.0";
  vat_registration_number: string;
  tin?: string;
  company_registration_number?: string;
  system_name: string;
  system_vendor: string;
  system_category: string;
  credential_reference?: string;
};

/** RegisterTaxpayerSystem. Identity fields are cross-checked against the actor's own resolved organisation/taxpayer in the repository layer — this function only validates shape. */
export function validateTaxpayerSystemRegistration(payload: unknown): TaxpayerSystemRegistration {
  const input = object(payload);
  const messages: TaxpayerSystemValidationMessage[] = [];
  schema(input, messages);
  const vatRegistrationNumber = bounded(input.vat_registration_number, "/vat_registration_number", "VAT registration number", 1, 40, messages).toUpperCase();
  if (vatRegistrationNumber && !IDENTIFIER_PATTERN.test(vatRegistrationNumber)) {
    messages.push({ code: "VAT_REGISTRATION_NUMBER_INVALID", path: "/vat_registration_number", message: "VAT registration number contains unsupported characters." });
  }
  const tin = optionalBounded(input.tin, "/tin", "TIN", 1, 40, messages)?.toUpperCase();
  if (tin && !IDENTIFIER_PATTERN.test(tin)) messages.push({ code: "TIN_INVALID", path: "/tin", message: "TIN contains unsupported characters." });
  const companyRegistrationNumber = optionalBounded(input.company_registration_number, "/company_registration_number", "Company registration number", 1, 40, messages)?.toUpperCase();
  if (companyRegistrationNumber && !IDENTIFIER_PATTERN.test(companyRegistrationNumber)) {
    messages.push({ code: "COMPANY_REGISTRATION_NUMBER_INVALID", path: "/company_registration_number", message: "Company registration number contains unsupported characters." });
  }
  const systemName = bounded(input.system_name, "/system_name", "System name", 2, 150, messages);
  const systemVendor = bounded(input.system_vendor, "/system_vendor", "System vendor", 2, 150, messages);
  const systemCategory = text(input.system_category).toUpperCase();
  if (!SYSTEM_CATEGORY_VALUES.has(systemCategory)) messages.push({ code: "SYSTEM_CATEGORY_INVALID", path: "/system_category", message: `system_category must be one of: ${[...SYSTEM_CATEGORY_VALUES].join(", ")}.` });
  const credentialReference = optionalBounded(input.credential_reference, "/credential_reference", "Credential reference", 3, 300, messages);
  if (messages.length) throw new TaxpayerSystemValidationError(messages);
  return {
    schema_version: "1.0.0", vat_registration_number: vatRegistrationNumber, system_name: systemName, system_vendor: systemVendor, system_category: systemCategory,
    ...(tin ? { tin } : {}), ...(companyRegistrationNumber ? { company_registration_number: companyRegistrationNumber } : {}), ...(credentialReference ? { credential_reference: credentialReference } : {}),
  };
}

export type TaxpayerSystemSuspension = { schema_version: "1.0.0"; reason: string };

export function validateTaxpayerSystemSuspension(payload: unknown): TaxpayerSystemSuspension {
  const input = object(payload);
  const messages: TaxpayerSystemValidationMessage[] = [];
  schema(input, messages);
  const reason = bounded(input.reason, "/reason", "Reason", 10, 500, messages);
  if (messages.length) throw new TaxpayerSystemValidationError(messages);
  return { schema_version: "1.0.0", reason };
}

export type TaxpayerSystemSync = { schema_version: "1.0.0"; api_status: string };

const API_STATUS_VALUES = new Set(["CONNECTED", "DEGRADED", "DISCONNECTED"]);

/** RecordSynchronization: the taxpayer's own system reports its post-sync connectivity state (never CONNECTED-before-any-sync — that only happens through this command, not at registration). */
export function validateTaxpayerSystemSync(payload: unknown): TaxpayerSystemSync {
  const input = object(payload);
  const messages: TaxpayerSystemValidationMessage[] = [];
  schema(input, messages);
  const apiStatus = text(input.api_status).toUpperCase();
  if (!API_STATUS_VALUES.has(apiStatus)) messages.push({ code: "API_STATUS_INVALID", path: "/api_status", message: `api_status must be one of: ${[...API_STATUS_VALUES].join(", ")}.` });
  if (messages.length) throw new TaxpayerSystemValidationError(messages);
  return { schema_version: "1.0.0", api_status: apiStatus };
}

export type TaxpayerSystemRegistrationStatus = "DRAFT" | "APPROVED" | "SUSPENDED";
export type TaxpayerSystemAction = "APPROVE" | "SUSPEND";

const TAXPAYER_SYSTEM_TRANSITIONS: Record<TaxpayerSystemRegistrationStatus, Partial<Record<TaxpayerSystemAction, TaxpayerSystemRegistrationStatus>>> = {
  DRAFT: { APPROVE: "APPROVED" },
  APPROVED: { SUSPEND: "SUSPENDED" },
  SUSPENDED: { APPROVE: "APPROVED" },
};

export function assertTaxpayerSystemTransition(action: TaxpayerSystemAction, current: string): TaxpayerSystemRegistrationStatus {
  const rule = TAXPAYER_SYSTEM_TRANSITIONS[current as TaxpayerSystemRegistrationStatus];
  const target = rule?.[action];
  if (!target) throw new TaxpayerSystemValidationError([{ code: "TAXPAYER_SYSTEM_TRANSITION_INVALID", path: "/action", message: `Cannot ${action.toLowerCase()} a registration currently ${current}.` }]);
  return target;
}
