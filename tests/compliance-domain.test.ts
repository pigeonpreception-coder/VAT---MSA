import { describe, expect, it } from "vitest";
import {
  assertCaseTransition,
  ComplianceValidationError,
  validateCaseOpening,
  validateCaseTransition,
  validateDispute,
  validateFindingIssuance,
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

describe("audit case lifecycle state machine", () => {
  it("walks the full happy-path lifecycle", () => {
    expect(assertCaseTransition("AUTHORIZE", "PROPOSED")).toBe("AUTHORIZED");
    expect(assertCaseTransition("ASSIGN", "AUTHORIZED")).toBe("ASSIGNED");
    expect(assertCaseTransition("ADVANCE", "ASSIGNED")).toBe("PLANNING");
    expect(assertCaseTransition("ADVANCE", "PLANNING")).toBe("EVIDENCE_COLLECTION");
    expect(assertCaseTransition("ADVANCE", "EVIDENCE_COLLECTION")).toBe("ANALYSIS");
    expect(assertCaseTransition("ADVANCE", "ANALYSIS")).toBe("TAXPAYER_RESPONSE");
    expect(assertCaseTransition("ADVANCE", "TAXPAYER_RESPONSE")).toBe("FINDINGS_REVIEW");
    expect(assertCaseTransition("ADVANCE", "FINDINGS_REVIEW")).toBe("DECISION");
    expect(assertCaseTransition("CLOSE", "DECISION")).toBe("CLOSED");
  });

  it("allows cancellation only from PROPOSED or AUTHORIZED", () => {
    expect(assertCaseTransition("CANCEL", "PROPOSED")).toBe("CANCELLED");
    expect(assertCaseTransition("CANCEL", "AUTHORIZED")).toBe("CANCELLED");
    expect(() => assertCaseTransition("CANCEL", "ASSIGNED")).toThrowError(ComplianceValidationError);
  });

  it("allows suspend from any working state and resume back with a null static target", () => {
    expect(assertCaseTransition("SUSPEND", "ANALYSIS")).toBe("SUSPENDED");
    expect(assertCaseTransition("RESUME", "SUSPENDED")).toBeNull();
    expect(() => assertCaseTransition("SUSPEND", "CLOSED")).toThrowError(ComplianceValidationError);
  });

  it("allows reopen and appeal-linking only from CLOSED", () => {
    expect(assertCaseTransition("REOPEN", "CLOSED")).toBe("FINDINGS_REVIEW");
    expect(assertCaseTransition("LINK_APPEAL", "CLOSED")).toBe("CLOSED");
    expect(() => assertCaseTransition("REOPEN", "DECISION")).toThrowError(ComplianceValidationError);
  });

  it("rejects any transition from a terminal CANCELLED case", () => {
    expect(() => assertCaseTransition("ADVANCE", "CANCELLED")).toThrowError(ComplianceValidationError);
    expect(() => assertCaseTransition("AUTHORIZE", "CANCELLED")).toThrowError(ComplianceValidationError);
  });

  it("normalizes a case transition command, uppercasing the action", () => {
    const result = validateCaseTransition({ schema_version: "1.0.0", action: "advance", reason: "Evidence collection is complete and the file is ready for analysis." });
    expect(result.action).toBe("ADVANCE");
    expect(result.officerId).toBeUndefined();
  });

  it("requires an officer_id for an ASSIGN action", () => {
    expect(() => validateCaseTransition({ schema_version: "1.0.0", action: "ASSIGN", reason: "Handing this case to the officer with subject-matter expertise." })).toThrowError(ComplianceValidationError);
    const result = validateCaseTransition({ schema_version: "1.0.0", action: "ASSIGN", reason: "Handing this case to the officer with subject-matter expertise.", officer_id: "user-0002" });
    expect(result.officerId).toBe("user-0002");
  });

  it("requires an appeal_reference for a LINK_APPEAL action", () => {
    expect(() => validateCaseTransition({ schema_version: "1.0.0", action: "LINK_APPEAL", reason: "The taxpayer has lodged a formal appeal against this decision." })).toThrowError(ComplianceValidationError);
    const result = validateCaseTransition({ schema_version: "1.0.0", action: "LINK_APPEAL", reason: "The taxpayer has lodged a formal appeal against this decision.", appeal_reference: "APPEAL-2026-0042" });
    expect(result.appealReference).toBe("APPEAL-2026-0042");
  });

  it("rejects an unrecognised action", () => {
    expect(() => validateCaseTransition({ schema_version: "1.0.0", action: "TELEPORT", reason: "Not a real action for this state machine." })).toThrowError(ComplianceValidationError);
  });

  it("rejects a reason outside the 10 to 2000 character bound", () => {
    expect(() => validateCaseTransition({ schema_version: "1.0.0", action: "ADVANCE", reason: "short" })).toThrowError(ComplianceValidationError);
  });
});

describe("finding issuance validation", () => {
  it("normalizes a well-formed finding", () => {
    const result = validateFindingIssuance({ schema_version: "1.0.0", finding_code: "finding-underdeclared-output-vat", title: "Underdeclared output VAT for the period", description: "Sampled invoices show output VAT amounts below the rate applicable to the declared supply category.", legal_reference: "VAT Act s. 21", amount_cents: 250000, currency: "nad" });
    expect(result.currency).toBe("NAD");
    expect(result.amount_cents).toBe(250000);
    expect(result.legal_reference).toBe("VAT Act s. 21");
  });

  it("rejects a negative or non-integer amount", () => {
    expect(() => validateFindingIssuance({ schema_version: "1.0.0", finding_code: "finding-0001", title: "Underdeclared output VAT for the period", description: "Sampled invoices show output VAT amounts below the applicable declared rate.", amount_cents: -100, currency: "NAD" })).toThrowError(ComplianceValidationError);
    expect(() => validateFindingIssuance({ schema_version: "1.0.0", finding_code: "finding-0001", title: "Underdeclared output VAT for the period", description: "Sampled invoices show output VAT amounts below the applicable declared rate.", amount_cents: 10.5, currency: "NAD" })).toThrowError(ComplianceValidationError);
  });

  it("rejects an invalid currency code", () => {
    expect(() => validateFindingIssuance({ schema_version: "1.0.0", finding_code: "finding-0001", title: "Underdeclared output VAT for the period", description: "Sampled invoices show output VAT amounts below the applicable declared rate.", amount_cents: 1000, currency: "N" })).toThrowError(ComplianceValidationError);
  });

  it("rejects a title or description outside their character bounds", () => {
    expect(() => validateFindingIssuance({ schema_version: "1.0.0", finding_code: "finding-0001", title: "Bad", description: "Sampled invoices show output VAT amounts below the applicable declared rate.", amount_cents: 1000, currency: "NAD" })).toThrowError(ComplianceValidationError);
    expect(() => validateFindingIssuance({ schema_version: "1.0.0", finding_code: "finding-0001", title: "Underdeclared output VAT for the period", description: "too short", amount_cents: 1000, currency: "NAD" })).toThrowError(ComplianceValidationError);
  });
});
