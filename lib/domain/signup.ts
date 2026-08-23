import {
  IdentityValidationError,
  normalizeAndValidateRegistration,
  type NormalizedRegistrationSubmission,
  type ReturnFrequency,
  type TaxpayerType,
} from "./identity";

export const SELF_SERVE_TERMS_VERSION = "2026-08-23";
export const SELF_SERVE_PRIVACY_NOTICE_VERSION = "2026-08-23";

export type ApplicantRole =
  | "OWNER"
  | "DIRECTOR"
  | "PARTNER"
  | "TRUSTEE"
  | "AUTHORISED_REPRESENTATIVE";

export type SelfServeSignupSubmission = {
  schema_version: "1.0.0";
  applicant_name: string;
  applicant_role: ApplicantRole;
  contact_email: string;
  country_code: "NA";
  plan_code: string;
  vat_number: string;
  tin: string;
  company_registration_number?: string;
  legal_name: string;
  trading_name?: string;
  taxpayer_type: TaxpayerType;
  return_frequency: ReturnFrequency;
  address: string;
  company_system_administrator_attested: boolean;
  terms_accepted: boolean;
  privacy_notice_accepted: boolean;
};

export type NormalizedSelfServeSignup = Omit<SelfServeSignupSubmission, keyof NormalizedRegistrationSubmission>
  & NormalizedRegistrationSubmission
  & {
    contact_email: string;
    plan_code: string;
  };

export type SignupValidationMessage = {
  code: string;
  path: string;
  message: string;
};

export class SignupValidationError extends Error {
  readonly messages: SignupValidationMessage[];

  constructor(messages: SignupValidationMessage[]) {
    super("Self-serve signup failed validation.");
    this.name = "SignupValidationError";
    this.messages = messages;
  }
}

const APPLICANT_ROLES = new Set<ApplicantRole>([
  "OWNER",
  "DIRECTOR",
  "PARTNER",
  "TRUSTEE",
  "AUTHORISED_REPRESENTATIVE",
]);
const PLAN_CODE_PATTERN = /^[A-Z][A-Z0-9_]{2,39}$/;
const ALLOWED_FIELDS = new Set([
  "schema_version",
  "applicant_name",
  "applicant_role",
  "contact_email",
  "country_code",
  "plan_code",
  "vat_number",
  "tin",
  "company_registration_number",
  "legal_name",
  "trading_name",
  "taxpayer_type",
  "return_frequency",
  "address",
  "company_system_administrator_attested",
  "terms_accepted",
  "privacy_notice_accepted",
]);

function text(value: unknown): string {
  return typeof value === "string" ? value.trim().replaceAll(/\s+/g, " ") : "";
}

export function normalizeAndValidateSelfServeSignup(payload: unknown): NormalizedSelfServeSignup {
  if (!payload || typeof payload !== "object" || Array.isArray(payload)) {
    throw new SignupValidationError([{ code: "DOCUMENT_INVALID", path: "/", message: "The request body must be a signup object." }]);
  }

  const input = payload as Record<string, unknown>;
  const messages: SignupValidationMessage[] = [];
  for (const field of Object.keys(input)) {
    if (!ALLOWED_FIELDS.has(field)) {
      messages.push({ code: "FIELD_UNEXPECTED", path: `/${field}`, message: "This field is not accepted." });
    }
  }

  const applicantName = text(input.applicant_name);
  if (applicantName.length < 2 || applicantName.length > 120) {
    messages.push({ code: "APPLICANT_NAME_INVALID", path: "/applicant_name", message: "Applicant name must contain 2 to 120 characters." });
  }

  const applicantRole = text(input.applicant_role).toUpperCase() as ApplicantRole;
  if (!APPLICANT_ROLES.has(applicantRole)) {
    messages.push({ code: "APPLICANT_ROLE_INVALID", path: "/applicant_role", message: "Select a supported applicant authority." });
  }

  const planCode = text(input.plan_code).toUpperCase();
  if (!PLAN_CODE_PATTERN.test(planCode)) {
    messages.push({ code: "PLAN_CODE_INVALID", path: "/plan_code", message: "Select an available licence plan." });
  }
  if (text(input.country_code).toUpperCase() !== "NA") {
    messages.push({ code: "COUNTRY_UNSUPPORTED", path: "/country_code", message: "This controlled signup channel currently accepts Namibia applications only." });
  }
  if (input.company_system_administrator_attested !== true) {
    messages.push({ code: "COMPANY_ADMIN_AUTHORITY_REQUIRED", path: "/company_system_administrator_attested", message: "Only the verified Company System Administrator may start a commercial subscription application." });
  }
  if (input.terms_accepted !== true) {
    messages.push({ code: "TERMS_ACCEPTANCE_REQUIRED", path: "/terms_accepted", message: "Accept the current terms to continue." });
  }
  if (input.privacy_notice_accepted !== true) {
    messages.push({ code: "PRIVACY_NOTICE_ACCEPTANCE_REQUIRED", path: "/privacy_notice_accepted", message: "Acknowledge the current privacy notice to continue." });
  }

  let registration: NormalizedRegistrationSubmission | null = null;
  try {
    registration = normalizeAndValidateRegistration({
      schema_version: input.schema_version,
      vat_number: input.vat_number,
      tin: input.tin,
      company_registration_number: input.company_registration_number,
      legal_name: input.legal_name,
      trading_name: input.trading_name,
      taxpayer_type: input.taxpayer_type,
      return_frequency: input.return_frequency,
      address: input.address,
      email: input.contact_email,
    });
  } catch (cause) {
    if (cause instanceof IdentityValidationError) messages.push(...cause.messages);
    else throw cause;
  }

  if (messages.length || !registration) throw new SignupValidationError(messages);

  return {
    ...registration,
    applicant_name: applicantName,
    applicant_role: applicantRole,
    contact_email: registration.email,
    country_code: "NA",
    plan_code: planCode,
    company_system_administrator_attested: true,
    terms_accepted: true,
    privacy_notice_accepted: true,
  };
}
