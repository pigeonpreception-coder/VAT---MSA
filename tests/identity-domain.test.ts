import { describe, expect, it } from "vitest";
import { IdentityValidationError, isDynamicTradingCapability, normalizeAndValidateRegistration } from "@/lib/domain/identity";
import { reconcileIdentityCandidate } from "@/lib/domain/identity-proofing";

function registration(overrides: Record<string, unknown> = {}) {
  return {
    schema_version: "1.0.0",
    vat_number: "vat-new-001",
    tin: "tin-new-001",
    company_registration_number: "bipa-2026-001",
    legal_name: "  Omatako   Digital Services (Pty) Ltd  ",
    trading_name: "Omatako Digital",
    taxpayer_type: "private_company",
    return_frequency: "bimonthly",
    address: "17 Mandume Ndemufayo Avenue, Windhoek",
    email: "Finance@Omatako.Example",
    ...overrides,
  };
}

describe("canonical taxpayer registration", () => {
  it("normalizes identifiers and legal identity fields deterministically", () => {
    expect(normalizeAndValidateRegistration(registration())).toMatchObject({
      vat_number: "VAT-NEW-001",
      tin: "TIN-NEW-001",
      company_registration_number: "BIPA-2026-001",
      legal_name: "Omatako Digital Services (Pty) Ltd",
      taxpayer_type: "PRIVATE_COMPANY",
      return_frequency: "BIMONTHLY",
      email: "finance@omatako.example",
    });
  });

  it("rejects a malformed identifier", () => {
    expect(() => normalizeAndValidateRegistration(registration({ vat_number: "<script>" }))).toThrow(IdentityValidationError);
  });

  it("requires VAT number and TIN to remain distinct", () => {
    expect(() => normalizeAndValidateRegistration(registration({ vat_number: "ID-100", tin: "ID-100" }))).toThrow(/failed validation/i);
  });

  it("rejects unsupported schema and classification values", () => {
    expect(() => normalizeAndValidateRegistration(registration({ schema_version: "2.0.0", taxpayer_type: "SELLER" }))).toThrow(IdentityValidationError);
  });

  it("models buyer and seller as transaction capabilities", () => {
    expect(isDynamicTradingCapability("BUYER")).toBe(true);
    expect(isDynamicTradingCapability("SELLER")).toBe(true);
    expect(isDynamicTradingCapability("BUYER_ACCOUNT")).toBe(false);
  });
});

describe("identity proofing reconciliation", () => {
  const submitted = {
    vatNumber: "VAT-100",
    tin: "TIN-100",
    companyRegistrationNumber: "BIPA-100",
    legalName: "Namib Example (Pty) Ltd",
  };

  it("classifies two exact authority identifiers as an existing canonical identity", () => {
    expect(reconcileIdentityCandidate(submitted, { taxpayerId: "tp-100", ...submitted })).toMatchObject({
      outcome: "DUPLICATE_CONFIRMED",
      confidenceBps: 10_000,
      reasonCode: "CANONICAL_TAXPAYER_ALREADY_EXISTS",
    });
  });

  it("routes conflicting authority identifiers to mismatch review", () => {
    expect(reconcileIdentityCandidate(submitted, {
      taxpayerId: "tp-100",
      ...submitted,
      tin: "TIN-DIFFERENT",
    })).toMatchObject({
      outcome: "MISMATCH",
      confidenceBps: 6_500,
      conflictingFields: ["tin"],
    });
  });

  it("does not treat a name-only candidate as verified", () => {
    expect(reconcileIdentityCandidate(submitted, {
      taxpayerId: "tp-200",
      vatNumber: "VAT-200",
      tin: "TIN-200",
      companyRegistrationNumber: "BIPA-200",
      legalName: "Namib Example Pty Ltd",
    })).toMatchObject({ outcome: "CANDIDATE_FOUND", confidenceBps: 1_000 });
  });

  it("requires an authoritative candidate before assigning confidence", () => {
    expect(reconcileIdentityCandidate(submitted, null)).toEqual({
      outcome: "NO_CANDIDATE",
      confidenceBps: 0,
      matchedFields: [],
      conflictingFields: [],
      reasonCode: "AUTHORITATIVE_CANDIDATE_REQUIRED",
    });
  });
});
