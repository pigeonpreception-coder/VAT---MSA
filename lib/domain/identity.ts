export type TaxpayerType =
  | "PRIVATE_COMPANY"
  | "CLOSE_CORPORATION"
  | "SOLE_PROPRIETOR"
  | "PARTNERSHIP"
  | "TRUST"
  | "NON_PROFIT"
  | "PUBLIC_ENTITY"
  | "OTHER";

export type ReturnFrequency = "MONTHLY" | "BIMONTHLY" | "QUARTERLY" | "ANNUAL";

export type RegistrationSubmission = {
  schema_version: "1.0.0";
  vat_number: string;
  tin: string;
  company_registration_number?: string;
  legal_name: string;
  trading_name?: string;
  taxpayer_type: TaxpayerType;
  return_frequency: ReturnFrequency;
  address: string;
  email: string;
};

export type NormalizedRegistrationSubmission = RegistrationSubmission;

export type IdentityValidationMessage = {
  code: string;
  path: string;
  message: string;
};

export class IdentityValidationError extends Error {
  readonly messages: IdentityValidationMessage[];

  constructor(messages: IdentityValidationMessage[]) {
    super("Registration failed validation.");
    this.name = "IdentityValidationError";
    this.messages = messages;
  }
}
const TAXPAYER_TYPES = new Set<TaxpayerType>([
  "PRIVATE_COMPANY",
  "CLOSE_CORPORATION",
  "SOLE_PROPRIETOR",
  "PARTNERSHIP",
  "TRUST",
  "NON_PROFIT",
  "PUBLIC_ENTITY",
  "OTHER",
]);

const RETURN_FREQUENCIES = new Set<ReturnFrequency>(["MONTHLY", "BIMONTHLY", "QUARTERLY", "ANNUAL"]);
const IDENTIFIER_PATTERN = /^[A-Z0-9][A-Z0-9./-]{2,39}$/;
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function textValue(value: unknown): string {
  return typeof value === "string" ? value.trim() : "";
}

function boundedText(
  value: unknown,
  path: string,
  label: string,
  min: number,
  max: number,
  messages: IdentityValidationMessage[],
): string {
  const normalized = textValue(value).replaceAll(/\s+/g, " ");
  if (normalized.length < min || normalized.length > max) {
    messages.push({ code: "FIELD_LENGTH_INVALID", path, message: `${label} must contain ${min} to ${max} characters.` });
  }
  return normalized;
}

function identifier(value: unknown, path: string, label: string, messages: IdentityValidationMessage[]): string {
  const normalized = textValue(value).toUpperCase();
  if (!IDENTIFIER_PATTERN.test(normalized)) {
    messages.push({ code: "IDENTIFIER_INVALID", path, message: `${label} must contain 3 to 40 letters, numbers, dots, slashes or hyphens.` });
  }
  return normalized;
}

export function normalizeAndValidateRegistration(payload: unknown): NormalizedRegistrationSubmission {
  const messages: IdentityValidationMessage[] = [];
  if (!payload || typeof payload !== "object" || Array.isArray(payload)) {
    throw new IdentityValidationError([{ code: "DOCUMENT_INVALID", path: "/", message: "The request body must be a registration object." }]);
  }

  const input = payload as Record<string, unknown>;
  if (input.schema_version !== "1.0.0") {
    messages.push({ code: "SCHEMA_VERSION_UNSUPPORTED", path: "/schema_version", message: "schema_version must be 1.0.0." });
  }

  const vatNumber = identifier(input.vat_number, "/vat_number", "VAT number", messages);
  const tin = identifier(input.tin, "/tin", "TIN", messages);
  const companyRegistrationNumber = textValue(input.company_registration_number)
    ? identifier(input.company_registration_number, "/company_registration_number", "Company registration number", messages)
    : undefined;
  const legalName = boundedText(input.legal_name, "/legal_name", "Legal name", 2, 200, messages);
  const tradingName = textValue(input.trading_name)
    ? boundedText(input.trading_name, "/trading_name", "Trading name", 2, 200, messages)
    : undefined;
  const address = boundedText(input.address, "/address", "Address", 5, 500, messages);
  const email = textValue(input.email).toLowerCase();
  if (email.length > 254 || !EMAIL_PATTERN.test(email)) {
    messages.push({ code: "EMAIL_INVALID", path: "/email", message: "A valid contact email address is required." });
  }

  const taxpayerType = textValue(input.taxpayer_type).toUpperCase() as TaxpayerType;
  if (!TAXPAYER_TYPES.has(taxpayerType)) {
    messages.push({ code: "TAXPAYER_TYPE_INVALID", path: "/taxpayer_type", message: "Select a supported taxpayer type." });
  }
  const returnFrequency = textValue(input.return_frequency).toUpperCase() as ReturnFrequency;
  if (!RETURN_FREQUENCIES.has(returnFrequency)) {
    messages.push({ code: "RETURN_FREQUENCY_INVALID", path: "/return_frequency", message: "Select a supported return frequency." });
  }

  if (vatNumber === tin) {
    messages.push({ code: "IDENTIFIERS_NOT_DISTINCT", path: "/tin", message: "VAT number and TIN must be distinct identifiers." });
  }
  if (messages.length) throw new IdentityValidationError(messages);

  return {
    schema_version: "1.0.0",
    vat_number: vatNumber,
    tin,
    ...(companyRegistrationNumber ? { company_registration_number: companyRegistrationNumber } : {}),
    legal_name: legalName,
    ...(tradingName ? { trading_name: tradingName } : {}),
    taxpayer_type: taxpayerType,
    return_frequency: returnFrequency,
    address,
    email,
  };
}

export function isDynamicTradingCapability(value: string): value is "BUYER" | "SELLER" {
  return value === "BUYER" || value === "SELLER";
}
