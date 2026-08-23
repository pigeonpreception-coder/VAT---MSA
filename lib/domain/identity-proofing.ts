export type IdentityProfile = {
  taxpayerId?: string;
  vatNumber: string;
  tin: string;
  companyRegistrationNumber?: string | null;
  legalName: string;
};

export type IdentityReconciliationOutcome =
  | "NO_CANDIDATE"
  | "CANDIDATE_FOUND"
  | "DUPLICATE_CONFIRMED"
  | "MISMATCH"
  | "MANUAL_REVIEW";

export type IdentityReconciliationResult = {
  outcome: IdentityReconciliationOutcome;
  confidenceBps: number;
  matchedFields: string[];
  conflictingFields: string[];
  reasonCode: string;
};

const IDENTIFIER_WEIGHTS = {
  vat_number: 4_000,
  tin: 3_500,
  company_registration_number: 1_500,
} as const;

function normalizedIdentifier(value: string | null | undefined): string | null {
  const normalized = value?.trim().toUpperCase() ?? "";
  return normalized || null;
}

function normalizedLegalName(value: string): string {
  return value.normalize("NFKC").toUpperCase().replaceAll(/[^A-Z0-9]+/gu, " ").trim().replaceAll(/\s+/gu, " ");
}

export function reconcileIdentityCandidate(submitted: IdentityProfile, candidate?: IdentityProfile | null): IdentityReconciliationResult {
  if (!candidate) {
    return { outcome: "NO_CANDIDATE", confidenceBps: 0, matchedFields: [], conflictingFields: [], reasonCode: "AUTHORITATIVE_CANDIDATE_REQUIRED" };
  }

  const matchedFields: string[] = [];
  const conflictingFields: string[] = [];
  let confidenceBps = 0;

  for (const [field, weight] of Object.entries(IDENTIFIER_WEIGHTS) as Array<[keyof typeof IDENTIFIER_WEIGHTS, number]>) {
    const submittedValue = normalizedIdentifier(field === "vat_number" ? submitted.vatNumber : field === "tin" ? submitted.tin : submitted.companyRegistrationNumber);
    const candidateValue = normalizedIdentifier(field === "vat_number" ? candidate.vatNumber : field === "tin" ? candidate.tin : candidate.companyRegistrationNumber);
    if (!submittedValue || !candidateValue) continue;
    if (submittedValue === candidateValue) {
      matchedFields.push(field);
      confidenceBps += weight;
    } else {
      conflictingFields.push(field);
    }
  }

  if (normalizedLegalName(submitted.legalName) === normalizedLegalName(candidate.legalName)) {
    matchedFields.push("legal_name");
    confidenceBps += 1_000;
  } else {
    conflictingFields.push("legal_name");
  }

  const identifierMatches = matchedFields.filter((field) => field !== "legal_name").length;
  const identifierConflicts = conflictingFields.filter((field) => field !== "legal_name").length;

  if (identifierMatches > 0 && identifierConflicts > 0) {
    return { outcome: "MISMATCH", confidenceBps, matchedFields, conflictingFields, reasonCode: "AUTHORITATIVE_IDENTIFIER_CONFLICT" };
  }
  if (identifierMatches >= 2 && identifierConflicts === 0) {
    return { outcome: "DUPLICATE_CONFIRMED", confidenceBps, matchedFields, conflictingFields, reasonCode: "CANONICAL_TAXPAYER_ALREADY_EXISTS" };
  }
  if (identifierMatches === 1) {
    return { outcome: "MANUAL_REVIEW", confidenceBps, matchedFields, conflictingFields, reasonCode: "PARTIAL_IDENTIFIER_MATCH" };
  }
  return { outcome: "CANDIDATE_FOUND", confidenceBps, matchedFields, conflictingFields, reasonCode: "NAME_ONLY_OR_UNSUPPORTED_CANDIDATE" };
}
