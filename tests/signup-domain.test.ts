import { describe, expect, it } from "vitest";
import { normalizeAndValidateSelfServeSignup, SignupValidationError } from "@/lib/domain/signup";

function signup(overrides: Record<string, unknown> = {}) {
  return {
    schema_version: "1.0.0",
    applicant_name: "  Ndeshi   Amutenya  ",
    applicant_role: "owner",
    contact_email: "Ndeshi@Omatako.Example",
    country_code: "na",
    plan_code: "pilot_professional",
    vat_number: "vat-signup-001",
    tin: "tin-signup-001",
    company_registration_number: "bipa-signup-001",
    legal_name: "Omatako Digital Services (Pty) Ltd",
    trading_name: "Omatako Digital",
    taxpayer_type: "private_company",
    return_frequency: "bimonthly",
    address: "17 Mandume Ndemufayo Avenue, Windhoek",
    authority_attested: true,
    terms_accepted: true,
    privacy_notice_accepted: true,
    ...overrides,
  };
}

describe("self-serve signup validation", () => {
  it("normalizes applicant, plan and legal taxpayer identity deterministically", () => {
    expect(normalizeAndValidateSelfServeSignup(signup())).toMatchObject({
      applicant_name: "Ndeshi Amutenya",
      applicant_role: "OWNER",
      contact_email: "ndeshi@omatako.example",
      country_code: "NA",
      plan_code: "PILOT_PROFESSIONAL",
      vat_number: "VAT-SIGNUP-001",
      tin: "TIN-SIGNUP-001",
      taxpayer_type: "PRIVATE_COMPANY",
      return_frequency: "BIMONTHLY",
    });
  });

  it.each([
    ["authority_attested", false],
    ["terms_accepted", false],
    ["privacy_notice_accepted", false],
  ])("requires explicit %s consent", (field, value) => {
    expect(() => normalizeAndValidateSelfServeSignup(signup({ [field]: value }))).toThrow(SignupValidationError);
  });

  it("accepts only the controlled Namibia channel and declared applicant authorities", () => {
    expect(() => normalizeAndValidateSelfServeSignup(signup({ country_code: "ZA" }))).toThrow(/failed validation/i);
    expect(() => normalizeAndValidateSelfServeSignup(signup({ applicant_role: "ADMIN" }))).toThrow(SignupValidationError);
  });

  it("rejects mass-assignment fields such as price, password or activation state", () => {
    for (const field of ["price", "password", "licence_status"]) {
      expect(() => normalizeAndValidateSelfServeSignup(signup({ [field]: "unsafe" }))).toThrow(SignupValidationError);
    }
  });
});
