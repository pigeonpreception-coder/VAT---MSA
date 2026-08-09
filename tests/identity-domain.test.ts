import { describe, expect, it } from "vitest";
import { IdentityValidationError, isDynamicTradingCapability, normalizeAndValidateRegistration } from "@/lib/domain/identity";

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
