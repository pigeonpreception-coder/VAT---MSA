import { describe, expect, it } from "vitest";
import {
  assertCaseTransition,
  ComplianceValidationError,
  normalizeRiskIndicatorQuery,
  validateCaseNoteAddition,
  validateCaseOpening,
  validateCaseTransition,
  validateDispute,
  validateEvidenceAddition,
  validateEvidenceCustodyEvent,
  validateFindingIssuance,
  validateObligationCreation,
  validateObligationSatisfaction,
  validateRefundRequest,
  validateRefundReview,
  validateRiskActionApproval,
  validateRiskEvaluationRequest,
  validateRiskReviewAssignment,
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

describe("risk review assignment validation", () => {
  it("normalizes a well-formed review assignment", () => {
    expect(validateRiskReviewAssignment({ schema_version: "1.0.0", officer_id: "usr-0002" }).officerId).toBe("usr-0002");
  });

  it("rejects a missing officer_id", () => {
    expect(() => validateRiskReviewAssignment({ schema_version: "1.0.0" })).toThrowError(ComplianceValidationError);
  });
});

describe("risk action approval validation", () => {
  it("normalizes a DISMISS decision without requiring case fields", () => {
    const result = validateRiskActionApproval({ schema_version: "1.0.0", decision: "dismiss", rationale: "The indicator was independently verified as a false positive against known evidence." });
    expect(result.decision).toBe("DISMISS");
  });

  it("normalizes an ESCALATE_TO_CASE decision, uppercasing case_type", () => {
    const result = validateRiskActionApproval({ schema_version: "1.0.0", decision: "escalate_to_case", rationale: "The reviewing officer independently confirmed the transaction pattern warrants a formal audit.", case_type: "vat_audit", case_title: "High-value transaction pattern review" });
    expect(result.decision).toBe("ESCALATE_TO_CASE");
    if (result.decision === "ESCALATE_TO_CASE") {
      expect(result.caseType).toBe("VAT_AUDIT");
      expect(result.caseTitle).toBe("High-value transaction pattern review");
    }
  });

  it("requires case_type and case_title when escalating to a case", () => {
    expect(() => validateRiskActionApproval({ schema_version: "1.0.0", decision: "ESCALATE_TO_CASE", rationale: "The reviewing officer independently confirmed the transaction pattern warrants a formal audit." })).toThrowError(ComplianceValidationError);
  });

  it("rejects an unsupported case_type when escalating", () => {
    expect(() => validateRiskActionApproval({ schema_version: "1.0.0", decision: "ESCALATE_TO_CASE", rationale: "The reviewing officer independently confirmed the transaction pattern warrants a formal audit.", case_type: "NOT_A_TYPE", case_title: "High-value transaction pattern review" })).toThrowError(ComplianceValidationError);
  });

  it("rejects an unrecognised decision", () => {
    expect(() => validateRiskActionApproval({ schema_version: "1.0.0", decision: "IGNORE", rationale: "The reviewing officer independently confirmed the transaction pattern warrants a formal audit." })).toThrowError(ComplianceValidationError);
  });

  it("rejects a rationale outside the 20 to 2000 character bound", () => {
    expect(() => validateRiskActionApproval({ schema_version: "1.0.0", decision: "DISMISS", rationale: "too short" })).toThrowError(ComplianceValidationError);
  });
});

describe("risk evaluation request validation", () => {
  it("accepts a well-formed request", () => {
    expect(validateRiskEvaluationRequest({ schema_version: "1.0.0" })).toEqual({ schema_version: "1.0.0" });
  });

  it("rejects an unsupported schema_version", () => {
    expect(() => validateRiskEvaluationRequest({ schema_version: "2.0.0" })).toThrowError(ComplianceValidationError);
  });
});

describe("restricted risk query normalization", () => {
  it("applies the default limit and no filters when the query is empty", () => {
    const result = normalizeRiskIndicatorQuery(new URLSearchParams());
    expect(result).toEqual({ taxpayerId: null, status: null, severity: null, limit: 50, offset: 0 });
  });

  it("normalizes taxpayer_id/status/severity filters, uppercasing status and severity", () => {
    const result = normalizeRiskIndicatorQuery(new URLSearchParams({ taxpayer_id: "tp-0001", status: "open", severity: "high" }));
    expect(result).toEqual({ taxpayerId: "tp-0001", status: "OPEN", severity: "HIGH", limit: 50, offset: 0 });
  });

  it("rejects an unsupported status or severity", () => {
    expect(() => normalizeRiskIndicatorQuery(new URLSearchParams({ status: "CLOSED" }))).toThrowError(ComplianceValidationError);
    expect(() => normalizeRiskIndicatorQuery(new URLSearchParams({ severity: "EXTREME" }))).toThrowError(ComplianceValidationError);
  });

  it("rejects a limit outside 1 to 200 and a negative offset", () => {
    expect(() => normalizeRiskIndicatorQuery(new URLSearchParams({ limit: "0" }))).toThrowError(ComplianceValidationError);
    expect(() => normalizeRiskIndicatorQuery(new URLSearchParams({ limit: "201" }))).toThrowError(ComplianceValidationError);
    expect(() => normalizeRiskIndicatorQuery(new URLSearchParams({ offset: "-1" }))).toThrowError(ComplianceValidationError);
  });

  it("accepts an explicit limit and offset within bounds", () => {
    const result = normalizeRiskIndicatorQuery(new URLSearchParams({ limit: "10", offset: "20" }));
    expect(result.limit).toBe(10);
    expect(result.offset).toBe(20);
  });
});

describe("evidence addition validation", () => {
  it("normalizes a citation of a canonical system record, uppercasing source_resource_type", () => {
    const result = validateEvidenceAddition({ schema_version: "1.0.0", source_resource_type: "invoice", source_resource_id: "inv-0001", description: "The certified invoice underpinning this finding." });
    expect(result.sourceResourceType).toBe("INVOICE");
    expect(result.checksumSha256).toBeUndefined();
  });

  it("requires a valid 64-character hex checksum for OTHER evidence", () => {
    expect(() => validateEvidenceAddition({ schema_version: "1.0.0", source_resource_type: "OTHER", source_resource_id: "ext-doc-1", description: "An externally supplied bank statement." })).toThrowError(ComplianceValidationError);
    expect(() => validateEvidenceAddition({ schema_version: "1.0.0", source_resource_type: "OTHER", source_resource_id: "ext-doc-1", description: "An externally supplied bank statement.", checksum_sha256: "not-a-hash" })).toThrowError(ComplianceValidationError);
    const result = validateEvidenceAddition({ schema_version: "1.0.0", source_resource_type: "OTHER", source_resource_id: "ext-doc-1", description: "An externally supplied bank statement.", checksum_sha256: "A".repeat(64) });
    expect(result.checksumSha256).toBe("a".repeat(64));
  });

  it("rejects an unsupported source_resource_type", () => {
    expect(() => validateEvidenceAddition({ schema_version: "1.0.0", source_resource_type: "EMAIL", source_resource_id: "msg-1", description: "An email thread between the taxpayer and supplier." })).toThrowError(ComplianceValidationError);
  });

  it("normalizes an optional supersedes_evidence_id", () => {
    const result = validateEvidenceAddition({ schema_version: "1.0.0", source_resource_type: "INVOICE", source_resource_id: "inv-0001", description: "A corrected citation replacing the earlier one.", supersedes_evidence_id: "evid-0001" });
    expect(result.supersedesEvidenceId).toBe("evid-0001");
  });

  it("rejects a description outside the 10 to 2000 character bound", () => {
    expect(() => validateEvidenceAddition({ schema_version: "1.0.0", source_resource_type: "INVOICE", source_resource_id: "inv-0001", description: "short" })).toThrowError(ComplianceValidationError);
  });
});

describe("evidence custody event validation", () => {
  it("normalizes a VERIFY action without requiring notes", () => {
    const result = validateEvidenceCustodyEvent({ schema_version: "1.0.0", action: "verify" });
    expect(result.action).toBe("VERIFY");
    expect(result.notes).toBeUndefined();
  });

  it("requires notes for SET_LEGAL_HOLD and RELEASE_LEGAL_HOLD", () => {
    expect(() => validateEvidenceCustodyEvent({ schema_version: "1.0.0", action: "SET_LEGAL_HOLD" })).toThrowError(ComplianceValidationError);
    const result = validateEvidenceCustodyEvent({ schema_version: "1.0.0", action: "SET_LEGAL_HOLD", notes: "Preserved pending the taxpayer's formal dispute of finding-0001." });
    expect(result.notes).toContain("Preserved pending");
  });

  it("rejects an unrecognised action", () => {
    expect(() => validateEvidenceCustodyEvent({ schema_version: "1.0.0", action: "DESTROY" })).toThrowError(ComplianceValidationError);
  });
});

describe("case note addition validation", () => {
  it("normalizes a well-formed note", () => {
    expect(validateCaseNoteAddition({ schema_version: "1.0.0", body: "Contacted the taxpayer's accountant to confirm the delivery date." }).body).toBe("Contacted the taxpayer's accountant to confirm the delivery date.");
  });

  it("normalizes an optional supersedes_note_id", () => {
    const result = validateCaseNoteAddition({ schema_version: "1.0.0", body: "Correction: the accountant confirmed a different delivery date than first recorded.", supersedes_note_id: "note-0001" });
    expect(result.supersedesNoteId).toBe("note-0001");
  });

  it("rejects a note body outside the 5 to 4000 character bound", () => {
    expect(() => validateCaseNoteAddition({ schema_version: "1.0.0", body: "Hi" })).toThrowError(ComplianceValidationError);
  });
});
