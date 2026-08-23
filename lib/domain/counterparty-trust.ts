export type CounterpartyIdentity = {
  legalName?: string | null;
  vatNumber?: string | null;
  tin?: string | null;
  companyRegistrationNumber?: string | null;
};

export type SyntheticCounterpartyVerificationSubmission = {
  schema_version: "1.0.0";
  authority_record: {
    legal_name: string;
    vat_number?: string;
    tin?: string;
    company_registration_number?: string;
    tax_registration_status: "ACTIVE" | "INACTIVE" | "SUSPENDED" | "CANCELLED" | "NOT_REGISTERED";
  };
};

export type IdentifierVerificationStatus = "NOT_PROVIDED" | "MATCHED" | "MISMATCH" | "INVALID";

export type CounterpartyTrustEvaluation = {
  trustStatus: "SYNTHETIC_VALID" | "MISMATCH" | "INVALID";
  taxRegistrationStatus: SyntheticCounterpartyVerificationSubmission["authority_record"]["tax_registration_status"];
  vatVerificationStatus: IdentifierVerificationStatus;
  tinVerificationStatus: IdentifierVerificationStatus;
  companyVerificationStatus: IdentifierVerificationStatus;
  confidenceBps: number;
  matchedFields: string[];
  conflictingFields: string[];
  reasonCode: string;
};

export class CounterpartyTrustValidationError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "CounterpartyTrustValidationError";
  }
}

function identifier(value: unknown): string | null {
  if (value === null || value === undefined || value === "") return null;
  if (typeof value !== "string") throw new CounterpartyTrustValidationError("Authority identifiers must be strings.");
  const normalized = value.trim().toUpperCase();
  if (!/^[A-Z0-9][A-Z0-9 ._/-]{1,39}$/u.test(normalized)) throw new CounterpartyTrustValidationError("Authority identifiers contain unsupported characters.");
  return normalized;
}

function legalName(value: unknown): string {
  if (typeof value !== "string") throw new CounterpartyTrustValidationError("Authority legal name is required.");
  const normalized = value.normalize("NFKC").trim().replaceAll(/\s+/gu, " ");
  if (normalized.length < 2 || normalized.length > 200) throw new CounterpartyTrustValidationError("Authority legal name must contain 2 to 200 characters.");
  return normalized;
}

function comparableName(value: string | null | undefined): string {
  return (value ?? "").normalize("NFKC").toUpperCase().replaceAll(/[^A-Z0-9]+/gu, " ").trim().replaceAll(/\s+/gu, " ");
}

export function normalizeSyntheticCounterpartyVerification(payload: unknown): SyntheticCounterpartyVerificationSubmission {
  if (!payload || typeof payload !== "object" || Array.isArray(payload)) throw new CounterpartyTrustValidationError("The verification request must be an object.");
  const input = payload as Record<string, unknown>;
  if (input.schema_version !== "1.0.0") throw new CounterpartyTrustValidationError("schema_version must be 1.0.0.");
  if (!input.authority_record || typeof input.authority_record !== "object" || Array.isArray(input.authority_record)) throw new CounterpartyTrustValidationError("authority_record is required.");
  const authority = input.authority_record as Record<string, unknown>;
  const taxStatus = typeof authority.tax_registration_status === "string" ? authority.tax_registration_status.trim().toUpperCase() : "";
  if (!["ACTIVE", "INACTIVE", "SUSPENDED", "CANCELLED", "NOT_REGISTERED"].includes(taxStatus)) throw new CounterpartyTrustValidationError("A supported synthetic tax-registration status is required.");
  const vatNumber = identifier(authority.vat_number);
  const tin = identifier(authority.tin);
  const companyRegistrationNumber = identifier(authority.company_registration_number);
  if (!vatNumber && !tin && !companyRegistrationNumber) throw new CounterpartyTrustValidationError("The synthetic authority record requires at least one tax or company identifier.");
  return {
    schema_version: "1.0.0",
    authority_record: {
      legal_name: legalName(authority.legal_name),
      ...(vatNumber ? { vat_number: vatNumber } : {}),
      ...(tin ? { tin } : {}),
      ...(companyRegistrationNumber ? { company_registration_number: companyRegistrationNumber } : {}),
      tax_registration_status: taxStatus as SyntheticCounterpartyVerificationSubmission["authority_record"]["tax_registration_status"],
    },
  };
}

export function evaluateCounterpartyTrust(
  party: CounterpartyIdentity,
  authority: SyntheticCounterpartyVerificationSubmission["authority_record"],
): CounterpartyTrustEvaluation {
  const matchedFields: string[] = [];
  const conflictingFields: string[] = [];
  let confidenceBps = 0;
  const compare = (partyValue: string | null | undefined, authorityValue: string | null | undefined, field: string, weight: number): IdentifierVerificationStatus => {
    const left = partyValue?.trim().toUpperCase() || null;
    const right = authorityValue?.trim().toUpperCase() || null;
    if (!left) return "NOT_PROVIDED";
    if (!right) {
      conflictingFields.push(field);
      return "INVALID";
    }
    if (left === right) {
      matchedFields.push(field);
      confidenceBps += weight;
      return "MATCHED";
    }
    conflictingFields.push(field);
    return "MISMATCH";
  };
  const vatVerificationStatus = compare(party.vatNumber, authority.vat_number, "vat_number", 4_000);
  const tinVerificationStatus = compare(party.tin, authority.tin, "tin", 3_500);
  const companyVerificationStatus = compare(party.companyRegistrationNumber, authority.company_registration_number, "company_registration_number", 1_500);
  if (comparableName(party.legalName) && comparableName(party.legalName) === comparableName(authority.legal_name)) {
    matchedFields.push("legal_name");
    confidenceBps += 1_000;
  } else {
    conflictingFields.push("legal_name");
  }
  const identifierMatches = matchedFields.filter((field) => field !== "legal_name").length;
  if (conflictingFields.length > 0) {
    return { trustStatus: "MISMATCH", taxRegistrationStatus: authority.tax_registration_status, vatVerificationStatus, tinVerificationStatus, companyVerificationStatus, confidenceBps, matchedFields, conflictingFields, reasonCode: "COUNTERPARTY_AUTHORITY_MISMATCH" };
  }
  if (identifierMatches === 0) {
    return { trustStatus: "INVALID", taxRegistrationStatus: authority.tax_registration_status, vatVerificationStatus, tinVerificationStatus, companyVerificationStatus, confidenceBps, matchedFields, conflictingFields, reasonCode: "COUNTERPARTY_IDENTIFIER_REQUIRED" };
  }
  return { trustStatus: "SYNTHETIC_VALID", taxRegistrationStatus: authority.tax_registration_status, vatVerificationStatus, tinVerificationStatus, companyVerificationStatus, confidenceBps, matchedFields, conflictingFields, reasonCode: "SYNTHETIC_COUNTERPARTY_MATCH" };
}
