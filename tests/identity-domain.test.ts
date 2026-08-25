import { describe, expect, it } from "vitest";
import {
  IdentityValidationError,
  isDynamicTradingCapability,
  normalizeAndValidateRegistration,
  normalizeBranch,
  normalizeBranchUpdate,
  normalizeCounterpartyVatNumber,
  normalizeIdentifierCorrection,
  normalizeIdentityLink,
  normalizeInvitationClaim,
  normalizeMembershipAssignment,
  normalizeRegistrationDecision,
  normalizeTaxpayerSuspension,
  normalizeUserInvitation,
  normalizeUserSuspension,
} from "@/lib/domain/identity";

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

describe("registration decision (ActivateOrganisation approval gate)", () => {
  it("accepts a well-formed approval with a reason", () => {
    expect(normalizeRegistrationDecision({ decision: "approve", reason: "Documents verified against BIPA extract." })).toEqual({
      decision: "APPROVE",
      reason: "Documents verified against BIPA extract.",
    });
  });

  it("accepts a well-formed rejection", () => {
    expect(normalizeRegistrationDecision({ decision: "reject", reason: "VAT number does not match submitted evidence." }).decision).toBe("REJECT");
  });

  it("rejects an unsupported decision value", () => {
    expect(() => normalizeRegistrationDecision({ decision: "MAYBE", reason: "Not sure yet, needs more info." })).toThrow(IdentityValidationError);
  });

  it("rejects a reason outside the 5 to 240 character bound", () => {
    expect(() => normalizeRegistrationDecision({ decision: "APPROVE", reason: "no" })).toThrow(IdentityValidationError);
    expect(() => normalizeRegistrationDecision({ decision: "APPROVE", reason: "x".repeat(241) })).toThrow(IdentityValidationError);
  });
});

describe("membership assignment (AssignMembership role ceiling)", () => {
  it("accepts an assignable taxpayer-side role", () => {
    expect(normalizeMembershipAssignment({ user_id: "usr-1", role_code: "taxpayer_staff" })).toEqual({
      userId: "usr-1",
      roleCode: "TAXPAYER_STAFF",
      branchId: null,
    });
  });

  it("passes through an optional branch id", () => {
    expect(normalizeMembershipAssignment({ user_id: "usr-1", role_code: "TAXPAYER_VIEWER", branch_id: "br-1" }).branchId).toBe("br-1");
  });

  it("rejects a missing user id", () => {
    expect(() => normalizeMembershipAssignment({ role_code: "TAXPAYER_STAFF" })).toThrow(IdentityValidationError);
  });

  it("rejects NamRA, platform and portal roles as not assignable via this command", () => {
    for (const role of ["NAMRA_AUDITOR", "PILOT_ADMIN", "SUPER_ADMIN", "SELLER_ADMIN", "BUYER_ADMIN"]) {
      expect(() => normalizeMembershipAssignment({ user_id: "usr-1", role_code: role })).toThrow(IdentityValidationError);
    }
  });

  it("names ROLE_NOT_ASSIGNABLE in the underlying validation message for a rejected role", () => {
    try {
      normalizeMembershipAssignment({ user_id: "usr-1", role_code: "NAMRA_AUDITOR" });
      throw new Error("expected normalizeMembershipAssignment to throw");
    } catch (error) {
      expect(error).toBeInstanceOf(IdentityValidationError);
      expect((error as InstanceType<typeof IdentityValidationError>).messages[0]).toMatchObject({ code: "ROLE_NOT_ASSIGNABLE", path: "/role_code" });
    }
  });
});

describe("taxpayer suspension (SuspendTaxpayer)", () => {
  it("accepts a well-formed suspension reason", () => {
    expect(normalizeTaxpayerSuspension({ reason: "Repeated non-filing beyond the statutory deadline." })).toEqual({
      reason: "Repeated non-filing beyond the statutory deadline.",
    });
  });

  it("rejects a reason outside the 5 to 240 character bound", () => {
    expect(() => normalizeTaxpayerSuspension({ reason: "no" })).toThrow(IdentityValidationError);
    expect(() => normalizeTaxpayerSuspension({ reason: "x".repeat(241) })).toThrow(IdentityValidationError);
  });

  it("rejects a missing body", () => {
    expect(() => normalizeTaxpayerSuspension(null)).toThrow(IdentityValidationError);
  });
});

describe("branch validation (ListBranches / branch CRUD)", () => {
  it("normalizes a well-formed branch, uppercasing the code", () => {
    expect(normalizeBranch({ code: "swk-01", name: "Swakopmund Branch", address: "8 Theo-Ben Gurirab Street, Swakopmund" })).toEqual({
      code: "SWK-01",
      name: "Swakopmund Branch",
      address: "8 Theo-Ben Gurirab Street, Swakopmund",
    });
  });

  it("rejects a branch code outside the allowed pattern", () => {
    expect(() => normalizeBranch({ code: "s", name: "Swakopmund Branch", address: "8 Theo-Ben Gurirab Street, Swakopmund" })).toThrow(IdentityValidationError);
    expect(() => normalizeBranch({ code: "swk 01", name: "Swakopmund Branch", address: "8 Theo-Ben Gurirab Street, Swakopmund" })).toThrow(IdentityValidationError);
  });

  it("rejects a short branch name or address", () => {
    expect(() => normalizeBranch({ code: "SWK-01", name: "S", address: "8 Theo-Ben Gurirab Street, Swakopmund" })).toThrow(IdentityValidationError);
    expect(() => normalizeBranch({ code: "SWK-01", name: "Swakopmund Branch", address: "8" })).toThrow(IdentityValidationError);
  });

  it("accepts a partial update with only one field", () => {
    expect(normalizeBranchUpdate({ status: "inactive" })).toEqual({ status: "INACTIVE" });
  });

  it("rejects an update with no fields", () => {
    expect(() => normalizeBranchUpdate({})).toThrow(IdentityValidationError);
  });

  it("rejects an unsupported status value", () => {
    expect(() => normalizeBranchUpdate({ status: "CLOSED" })).toThrow(IdentityValidationError);
  });
});

describe("counterparty VAT number validation (ClassifyTransaction)", () => {
  it("normalizes a well-formed VAT number to uppercase", () => {
    expect(normalizeCounterpartyVatNumber("vat1000123")).toBe("VAT1000123");
  });

  it("rejects a missing VAT number", () => {
    expect(() => normalizeCounterpartyVatNumber(null)).toThrow(IdentityValidationError);
  });

  it("rejects a malformed VAT number", () => {
    expect(() => normalizeCounterpartyVatNumber("<script>")).toThrow(IdentityValidationError);
  });
});

describe("identity link validation (LinkIdentity)", () => {
  it("normalizes a well-formed link, uppercasing the provider key", () => {
    expect(normalizeIdentityLink({ user_id: "usr-1", provider_key: "sites_workspace", subject: "external-subject-123" })).toEqual({
      userId: "usr-1",
      providerKey: "SITES_WORKSPACE",
      subject: "external-subject-123",
    });
  });

  it("rejects a missing user id", () => {
    expect(() => normalizeIdentityLink({ provider_key: "SITES_WORKSPACE", subject: "sub-1" })).toThrow(IdentityValidationError);
  });

  it("rejects a malformed provider key", () => {
    expect(() => normalizeIdentityLink({ user_id: "usr-1", provider_key: "sites workspace!", subject: "sub-1" })).toThrow(IdentityValidationError);
  });

  it("rejects an empty or oversized subject", () => {
    expect(() => normalizeIdentityLink({ user_id: "usr-1", provider_key: "ITAS", subject: "" })).toThrow(IdentityValidationError);
    expect(() => normalizeIdentityLink({ user_id: "usr-1", provider_key: "ITAS", subject: "x".repeat(201) })).toThrow(IdentityValidationError);
  });
});

describe("user suspension validation (SuspendUser)", () => {
  it("accepts a well-formed suspension reason", () => {
    expect(normalizeUserSuspension({ reason: "Suspected compromised credentials pending investigation." })).toEqual({
      reason: "Suspected compromised credentials pending investigation.",
    });
  });

  it("rejects a reason outside the 5 to 240 character bound", () => {
    expect(() => normalizeUserSuspension({ reason: "no" })).toThrow(IdentityValidationError);
    expect(() => normalizeUserSuspension({ reason: "x".repeat(241) })).toThrow(IdentityValidationError);
  });

  it("rejects a missing body", () => {
    expect(() => normalizeUserSuspension(null)).toThrow(IdentityValidationError);
  });
});

describe("user invitation validation (ProvisionUser invite half)", () => {
  it("normalizes a well-formed invitation, lowercasing the email and uppercasing the role", () => {
    expect(normalizeUserInvitation({ email: "New.Hire@Example.Com", role_code: "taxpayer_staff" })).toEqual({
      email: "new.hire@example.com",
      roleCode: "TAXPAYER_STAFF",
    });
  });

  it("rejects a malformed email", () => {
    expect(() => normalizeUserInvitation({ email: "not-an-email", role_code: "TAXPAYER_STAFF" })).toThrow(IdentityValidationError);
  });

  it("rejects NamRA, platform and portal roles as not invitable via this command", () => {
    for (const role of ["NAMRA_AUDITOR", "PILOT_ADMIN", "SUPER_ADMIN"]) {
      expect(() => normalizeUserInvitation({ email: "person@example.com", role_code: role })).toThrow(IdentityValidationError);
    }
  });
});

describe("identifier correction validation (IdentifierVersion)", () => {
  it("normalizes a well-formed correction, uppercasing the identifier", () => {
    expect(normalizeIdentifierCorrection({ identifier_value: "vat-new-002", reason: "Corrected a transposed digit reported by the taxpayer." })).toEqual({
      identifierValue: "VAT-NEW-002",
      reason: "Corrected a transposed digit reported by the taxpayer.",
    });
  });

  it("rejects a malformed identifier value", () => {
    expect(() => normalizeIdentifierCorrection({ identifier_value: "<script>", reason: "Corrected a transposed digit reported by the taxpayer." })).toThrow(IdentityValidationError);
  });

  it("rejects a reason outside the 5 to 240 character bound", () => {
    expect(() => normalizeIdentifierCorrection({ identifier_value: "VAT-NEW-002", reason: "no" })).toThrow(IdentityValidationError);
  });
});

describe("invitation claim validation (ProvisionUser claim half)", () => {
  it("accepts a well-formed token", () => {
    expect(normalizeInvitationClaim({ token: "abc123" })).toEqual({ token: "abc123" });
  });

  it("rejects a missing or oversized token", () => {
    expect(() => normalizeInvitationClaim({})).toThrow(IdentityValidationError);
    expect(() => normalizeInvitationClaim({ token: "x".repeat(101) })).toThrow(IdentityValidationError);
  });
});
