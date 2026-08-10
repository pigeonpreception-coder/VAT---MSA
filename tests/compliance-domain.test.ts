import { describe, expect, it } from "vitest";
import { ComplianceValidationError, validateCaseOpening, validateDispute, validateRefundRequest, validateRefundReview } from "@/lib/domain/compliance";

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
});
