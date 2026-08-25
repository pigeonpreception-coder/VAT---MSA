import { describe, expect, it } from "vitest";
import {
  normalizeVatRuleApproval,
  normalizeVatRuleEvaluationQuery,
  normalizeVatRuleProposal,
  VatRuleValidationError,
} from "@/lib/domain/vat-rules";

describe("VAT rule proposal validation (ProposeVatRule)", () => {
  it("normalizes a well-formed proposal, uppercasing the category", () => {
    expect(normalizeVatRuleProposal({ tax_category: "standard", rate_bps: 1500, effective_from: "2027-01-01", reason: "Budget-approved rate change per the 2027 finance act." })).toEqual({
      taxCategory: "STANDARD",
      rateBps: 1500,
      effectiveFrom: "2027-01-01",
      reason: "Budget-approved rate change per the 2027 finance act.",
    });
  });

  it("rejects an unsupported tax category", () => {
    expect(() => normalizeVatRuleProposal({ tax_category: "LUXURY", rate_bps: 2500, effective_from: "2027-01-01", reason: "A made-up category not in the statutory list." })).toThrow(VatRuleValidationError);
  });

  it("rejects a rate outside 0 to 10000 basis points", () => {
    expect(() => normalizeVatRuleProposal({ tax_category: "STANDARD", rate_bps: 10_001, effective_from: "2027-01-01", reason: "Rate exceeds the valid basis-point range for testing." })).toThrow(VatRuleValidationError);
    expect(() => normalizeVatRuleProposal({ tax_category: "STANDARD", rate_bps: -1, effective_from: "2027-01-01", reason: "Negative rate is invalid for testing purposes." })).toThrow(VatRuleValidationError);
  });

  it("rejects a malformed effective_from date", () => {
    expect(() => normalizeVatRuleProposal({ tax_category: "STANDARD", rate_bps: 1500, effective_from: "01/01/2027", reason: "Malformed date format used for this test case." })).toThrow(VatRuleValidationError);
  });

  it("rejects a reason outside the 10 to 400 character bound", () => {
    expect(() => normalizeVatRuleProposal({ tax_category: "STANDARD", rate_bps: 1500, effective_from: "2027-01-01", reason: "too short" })).toThrow(VatRuleValidationError);
  });
});

describe("VAT rule approval validation (ApproveVatRule)", () => {
  it("accepts a well-formed approval reason", () => {
    expect(normalizeVatRuleApproval({ reason: "Verified against the published finance act amendment." })).toEqual({
      reason: "Verified against the published finance act amendment.",
    });
  });

  it("rejects a reason outside the 5 to 240 character bound", () => {
    expect(() => normalizeVatRuleApproval({ reason: "no" })).toThrow(VatRuleValidationError);
  });
});

describe("VAT rule evaluation query validation (EvaluateVAT)", () => {
  it("normalizes a well-formed query", () => {
    expect(normalizeVatRuleEvaluationQuery("zero_rated", "2026-08-25")).toEqual({ taxCategory: "ZERO_RATED", effectiveDate: "2026-08-25" });
  });

  it("rejects an unsupported category", () => {
    expect(() => normalizeVatRuleEvaluationQuery("LUXURY", "2026-08-25")).toThrow(VatRuleValidationError);
  });

  it("rejects a malformed date", () => {
    expect(() => normalizeVatRuleEvaluationQuery("STANDARD", "25-08-2026")).toThrow(VatRuleValidationError);
  });
});
