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

export type IdentityLinkInput = { userId: string; providerKey: string; subject: string };

/**
 * Module 1 Identity LinkIdentity: administratively links an additional
 * identity provider subject to an existing user. See linkIdentity in
 * lib/data/identity-repository.ts for why the provider must already be
 * ACTIVE + CONFIGURED (today, only SITES_WORKSPACE) and why the resulting
 * assurance_level is always 'ADMINISTRATIVE_LINK', never caller-supplied.
 */
export function normalizeIdentityLink(input: unknown): IdentityLinkInput {
  if (!input || typeof input !== "object" || Array.isArray(input)) {
    throw new IdentityValidationError([{ code: "DOCUMENT_INVALID", path: "/", message: "An identity link object is required." }]);
  }
  const source = input as Record<string, unknown>;
  const userId = textValue(source.user_id);
  if (!userId) throw new IdentityValidationError([{ code: "USER_ID_REQUIRED", path: "/user_id", message: "user_id is required." }]);
  const providerKey = textValue(source.provider_key).toUpperCase();
  if (!/^[A-Z][A-Z0-9_]{1,39}$/.test(providerKey)) {
    throw new IdentityValidationError([{ code: "PROVIDER_KEY_INVALID", path: "/provider_key", message: "provider_key must contain 2 to 40 uppercase letters, numbers or underscores." }]);
  }
  const subject = textValue(source.subject);
  if (!subject || subject.length > 200) {
    throw new IdentityValidationError([{ code: "SUBJECT_INVALID", path: "/subject", message: "subject must contain 1 to 200 characters." }]);
  }
  return { userId, providerKey, subject };
}

export type RegistrationDecision = { decision: "APPROVE" | "REJECT"; reason: string };

/**
 * Validates a NamRA/pilot-admin officer's decision on a pending registration
 * application (the standalone, non-ITAS approval path — ActivateOrganisation
 * per MODULE_DEVELOPMENT_PLAYBOOK.md). Approving materializes the taxpayer,
 * organisation, head-office branch, buyer/seller capabilities and the
 * submitter's owner membership; rejecting leaves no trace beyond the
 * registration record itself.
 */
export function normalizeRegistrationDecision(input: unknown): RegistrationDecision {
  if (!input || typeof input !== "object" || Array.isArray(input)) {
    throw new IdentityValidationError([{ code: "DOCUMENT_INVALID", path: "/", message: "A registration decision object is required." }]);
  }
  const source = input as Record<string, unknown>;
  const decision = String(source.decision ?? "").trim().toUpperCase();
  if (decision !== "APPROVE" && decision !== "REJECT") {
    throw new IdentityValidationError([{ code: "DECISION_INVALID", path: "/decision", message: "decision must be APPROVE or REJECT." }]);
  }
  const reason = textValue(source.reason).replaceAll(/\s+/g, " ");
  if (reason.length < 5 || reason.length > 240) {
    throw new IdentityValidationError([{ code: "REASON_INVALID", path: "/reason", message: "Provide a 5 to 240 character decision reason." }]);
  }
  return { decision: decision as "APPROVE" | "REJECT", reason };
}

/**
 * Roles a taxpayer-side administrator (or NamRA) may grant via
 * AssignMembership. Deliberately excludes NamRA roles, PILOT_ADMIN, platform
 * roles and the seller/buyer portal roles — granting those is out of scope
 * for this command and must never become reachable through it, since that
 * would be a privilege-escalation path for an organisation admin to hand out
 * NamRA or platform authority.
 */
export const ASSIGNABLE_MEMBERSHIP_ROLES = [
  "TAXPAYER_OWNER",
  "TAXPAYER_ADMIN",
  "TAXPAYER_ACCOUNTANT",
  "TAXPAYER_STAFF",
  "TAXPAYER_VIEWER",
] as const;
export type AssignableMembershipRole = (typeof ASSIGNABLE_MEMBERSHIP_ROLES)[number];

export type MembershipAssignment = { userId: string; roleCode: AssignableMembershipRole; branchId: string | null };

export type TaxpayerSuspension = { reason: string };

/** Module 1 SuspendTaxpayer: a NamRA/pilot-admin officer suspending vat_status
 * has real enforcement effect — counterparty resolution and taxpayer listing
 * queries already filter on vat_status='ACTIVE' (lib/data/repository.ts), so
 * a suspended taxpayer immediately stops being resolvable as an invoice
 * counterparty without any further code change. */
export function normalizeTaxpayerSuspension(input: unknown): TaxpayerSuspension {
  if (!input || typeof input !== "object" || Array.isArray(input)) {
    throw new IdentityValidationError([{ code: "DOCUMENT_INVALID", path: "/", message: "A suspension object is required." }]);
  }
  const source = input as Record<string, unknown>;
  const messages: IdentityValidationMessage[] = [];
  const reason = boundedText(source.reason, "/reason", "Suspension reason", 5, 240, messages);
  if (messages.length) throw new IdentityValidationError(messages);
  return { reason };
}

const BRANCH_CODE_PATTERN = /^[A-Z0-9][A-Z0-9-]{1,19}$/;

export type BranchInput = { code: string; name: string; address: string };

export function normalizeBranch(input: unknown): BranchInput {
  if (!input || typeof input !== "object" || Array.isArray(input)) {
    throw new IdentityValidationError([{ code: "DOCUMENT_INVALID", path: "/", message: "A branch object is required." }]);
  }
  const source = input as Record<string, unknown>;
  const messages: IdentityValidationMessage[] = [];
  const code = textValue(source.code).toUpperCase();
  if (!BRANCH_CODE_PATTERN.test(code)) {
    messages.push({ code: "BRANCH_CODE_INVALID", path: "/code", message: "code must contain 2 to 20 uppercase letters, numbers or hyphens." });
  }
  const name = boundedText(source.name, "/name", "Branch name", 2, 120, messages);
  const address = boundedText(source.address, "/address", "Branch address", 5, 500, messages);
  if (messages.length) throw new IdentityValidationError(messages);
  return { code, name, address };
}

export type BranchUpdate = { name?: string; address?: string; status?: "ACTIVE" | "INACTIVE" };

export function normalizeBranchUpdate(input: unknown): BranchUpdate {
  if (!input || typeof input !== "object" || Array.isArray(input)) {
    throw new IdentityValidationError([{ code: "DOCUMENT_INVALID", path: "/", message: "A branch update object is required." }]);
  }
  const source = input as Record<string, unknown>;
  const messages: IdentityValidationMessage[] = [];
  const result: BranchUpdate = {};
  if (source.name !== undefined) result.name = boundedText(source.name, "/name", "Branch name", 2, 120, messages);
  if (source.address !== undefined) result.address = boundedText(source.address, "/address", "Branch address", 5, 500, messages);
  if (source.status !== undefined) {
    const status = textValue(source.status).toUpperCase();
    if (status !== "ACTIVE" && status !== "INACTIVE") {
      messages.push({ code: "STATUS_INVALID", path: "/status", message: "status must be ACTIVE or INACTIVE." });
    } else {
      result.status = status;
    }
  }
  if (messages.length) throw new IdentityValidationError(messages);
  if (!Object.keys(result).length) {
    throw new IdentityValidationError([{ code: "NO_FIELDS", path: "/", message: "Provide at least one field to update: name, address or status." }]);
  }
  return result;
}

/**
 * Module 1 Buyer/Seller ClassifyTransaction: validates the counterparty VAT
 * number a caller wants to classify before attempting an invoice. Reuses the
 * same identifier pattern as registration so a malformed VAT number is
 * rejected the same way everywhere.
 */
export function normalizeCounterpartyVatNumber(rawVatNumber: unknown): string {
  const messages: IdentityValidationMessage[] = [];
  const vatNumber = identifier(rawVatNumber, "/vat_number", "VAT number", messages);
  if (messages.length) throw new IdentityValidationError(messages);
  return vatNumber;
}

export function normalizeMembershipAssignment(input: unknown): MembershipAssignment {
  if (!input || typeof input !== "object" || Array.isArray(input)) {
    throw new IdentityValidationError([{ code: "DOCUMENT_INVALID", path: "/", message: "A membership assignment object is required." }]);
  }
  const source = input as Record<string, unknown>;
  const userId = textValue(source.user_id);
  if (!userId) {
    throw new IdentityValidationError([{ code: "USER_ID_REQUIRED", path: "/user_id", message: "user_id is required." }]);
  }
  const roleCode = textValue(source.role_code).toUpperCase();
  if (!(ASSIGNABLE_MEMBERSHIP_ROLES as readonly string[]).includes(roleCode)) {
    throw new IdentityValidationError([{ code: "ROLE_NOT_ASSIGNABLE", path: "/role_code", message: `role_code must be one of: ${ASSIGNABLE_MEMBERSHIP_ROLES.join(", ")}.` }]);
  }
  const branchId = textValue(source.branch_id) || null;
  return { userId, roleCode: roleCode as AssignableMembershipRole, branchId };
}

export type UserInvitationInput = { email: string; roleCode: AssignableMembershipRole };

/**
 * Module 1 Identity ProvisionUser (explicit invite-and-claim, see
 * MODULE_DEVELOPMENT_PLAYBOOK.md's Phase C decision): an org admin invites a
 * not-yet-existing person by email. Reuses AssignMembership's role ceiling —
 * an invitation can never grant more than a taxpayer-side membership role,
 * for the same privilege-escalation reason ASSIGNABLE_MEMBERSHIP_ROLES exists.
 */
export function normalizeUserInvitation(input: unknown): UserInvitationInput {
  if (!input || typeof input !== "object" || Array.isArray(input)) {
    throw new IdentityValidationError([{ code: "DOCUMENT_INVALID", path: "/", message: "An invitation object is required." }]);
  }
  const source = input as Record<string, unknown>;
  const messages: IdentityValidationMessage[] = [];
  const email = textValue(source.email).toLowerCase();
  if (email.length > 254 || !EMAIL_PATTERN.test(email)) {
    messages.push({ code: "EMAIL_INVALID", path: "/email", message: "A valid email address is required." });
  }
  const roleCode = textValue(source.role_code).toUpperCase();
  if (!(ASSIGNABLE_MEMBERSHIP_ROLES as readonly string[]).includes(roleCode)) {
    messages.push({ code: "ROLE_NOT_ASSIGNABLE", path: "/role_code", message: `role_code must be one of: ${ASSIGNABLE_MEMBERSHIP_ROLES.join(", ")}.` });
  }
  if (messages.length) throw new IdentityValidationError(messages);
  return { email, roleCode: roleCode as AssignableMembershipRole };
}

export type InvitationClaim = { token: string };

export function normalizeInvitationClaim(input: unknown): InvitationClaim {
  if (!input || typeof input !== "object" || Array.isArray(input)) {
    throw new IdentityValidationError([{ code: "DOCUMENT_INVALID", path: "/", message: "A claim object is required." }]);
  }
  const source = input as Record<string, unknown>;
  const token = textValue(source.token);
  if (!token || token.length > 100) {
    throw new IdentityValidationError([{ code: "TOKEN_INVALID", path: "/token", message: "token is required." }]);
  }
  return { token };
}
