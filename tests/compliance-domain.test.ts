import { describe, expect, it } from "vitest";
import {
  ComplianceValidationError,
  validateCaseOpening,
  validateDispute,
  validateObligationCreation,
  validateObligationSatisfaction,
  validateRefundRequest,
  validateRefundReview,
} from "@/lib/domain/compliance";

describe("compliance command validation", () => {
  it("normalizes an evidence-led case opening", () => {
    const result = validateCaseOpening({ schema_version: "1.0.0", taxpayer_id: "tp-0001", case_type: "vat_audit", title: "Input VAT evidence review", opening_reason: "Matched evidence fell below the controlled review threshold for the period.", risk_tier: "high" });
    expect(result.case_type).toBe("VAT_AUDIT");
    expect(result.risk_tier).toBe("HIGH");
  });

  it("rejects a dispute without substantive grounds", () => {
    expect(() => validateDispute({ schema_version: "1.0.0", disputed_resource_type: "VAT_RETURN", disputed_resource_id: "returnv-1", grounds: "Wrong", disputed_amount_cents: 100, currency: "NAD" })).toThrowError(ComplianceValidationError);
  });

  it("requires exact integer cents in a dispute", () => {
    expect(() => validateDispute({ schema_version: "1.0.0", disputed_resource_type: "VAT_RETURN", disputed_resource_id: "returnv-1", grounds: "The underlying return calculation excludes independently supplied evidence.", disputed_amount_cents: 10.5, currency: "NAD" })).toThrowError(ComplianceValidationError);
  });

  it("validates a refund request by return-version identity", () => {
    expect(validateRefundRequest({ schema_version: "1.0.0", vat_return_version_id: "returnv-0003" }).vat_return_version_id).toBe("returnv-0003");
  });

  it("normalizes staged refund review decisions", () => {
    const review = validateRefundReview({ schema_version: "1.0.0", stage: "risk", decision: "request_information", findings: "The source evidence requires independent confirmation before supervisor review." });
    expect(review.stage).toBe("RISK");
    expect(review.decision).toBe("REQUEST_INFORMATION");
  });

  it("normalizes a well-formed obligation creation", () => {
    const result = validateObligationCreation({ schema_version: "1.0.0", taxpayer_id: "tp-0001", obligation_type: "vat_return", period_code: "2026-09", due_date: "2026-10-25", amount_cents: 500000, currency: "nad" });
    expect(result).toEqual({ schema_version: "1.0.0", taxpayer_id: "tp-0001", obligation_type: "VAT_RETURN", period_code: "2026-09", due_date: "2026-10-25", amount_cents: 500000, currency: "NAD" });
  });

  it("rejects a malformed period_code or due_date", () => {
    expect(() => validateObligationCreation({ schema_version: "1.0.0", taxpayer_id: "tp-0001", obligation_type: "VAT_RETURN", period_code: "Q3-2026", due_date: "2026-10-25", amount_cents: 500000, currency: "NAD" })).toThrowError(ComplianceValidationError);
    expect(() => validateObligationCreation({ schema_version: "1.0.0", taxpayer_id: "tp-0001", obligation_type: "VAT_RETURN", period_code: "2026-09", due_date: "25/10/2026", amount_cents: 500000, currency: "NAD" })).toThrowError(ComplianceValidationError);
  });

  it("rejects a missing taxpayer_id", () => {
    expect(() => validateObligationCreation({ schema_version: "1.0.0", obligation_type: "VAT_RETURN", period_code: "2026-09", due_date: "2026-10-25", amount_cents: 500000, currency: "NAD" })).toThrowError(ComplianceValidationError);
  });

  it("normalizes well-formed satisfaction notes", () => {
    expect(validateObligationSatisfaction({ schema_version: "1.0.0", notes: "Payment confirmed received via bank reconciliation." }).notes).toBe("Payment confirmed received via bank reconciliation.");
  });

  it("rejects satisfaction notes outside the 10 to 2000 character bound", () => {
    expect(() => validateObligationSatisfaction({ schema_version: "1.0.0", notes: "too short" })).toThrowError(ComplianceValidationError);
  });
});
