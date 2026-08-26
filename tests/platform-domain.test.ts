import { describe, expect, it } from "vitest";
import { PlatformValidationError, safeFileName, validateDocumentHold, validateDocumentScanResult, validateExportCancellation, validateExportCommand, validateOfflineBatch, validatePlatformChangeDecision, validatePlatformChangeRequest, validateProvisionStaff, validatePublishDataProductCommand, validateReportParameters, validateRunModelCommand } from "@/lib/domain/platform";

describe("platform edge validation", () => {
  it("accepts an ordered offline batch envelope", () => {
    const batch = validateOfflineBatch({ device_id: "offline-device-0001", batch_id: "72a56891-51af-4a62-b83f-594e147245db", sequence_from: 1, sequence_to: 2, created_at: "2026-08-10T09:00:00Z", documents: [{ id: 1 }, { id: 2 }], device_signature: "a".repeat(64) });
    expect(batch.documents).toHaveLength(2);
  });

  it("rejects a sequence range that does not match document count", () => {
    expect(() => validateOfflineBatch({ device_id: "offline-device-0001", batch_id: "72a56891-51af-4a62-b83f-594e147245db", sequence_from: 1, sequence_to: 3, created_at: "2026-08-10T09:00:00Z", documents: [{ id: 1 }], device_signature: "a".repeat(64) })).toThrowError(PlatformValidationError);
  });

  it("requires a 64-character previous batch hash", () => {
    expect(() => validateOfflineBatch({ device_id: "offline-device-0001", batch_id: "72a56891-51af-4a62-b83f-594e147245db", sequence_from: 1, sequence_to: 1, created_at: "2026-08-10T09:00:00Z", previous_batch_hash: "bad", documents: [{ id: 1 }], device_signature: "a".repeat(64) })).toThrowError(PlatformValidationError);
  });

  it("sanitises untrusted upload file names", () => {
    expect(safeFileName("../../invoice<script>.pdf")).toBe("invoice_script_.pdf");
  });

  it("bounds report parameter payloads", () => {
    expect(validateReportParameters({ period: "2026-08" })).toEqual({ period: "2026-08" });
    expect(() => validateReportParameters({ value: "x".repeat(20_000) })).toThrowError(PlatformValidationError);
  });

  it("normalizes a document scan result", () => {
    expect(validateDocumentScanResult({ schema_version: "1.0.0", outcome: "clean" })).toEqual({ schema_version: "1.0.0", outcome: "CLEAN" });
    expect(validateDocumentScanResult({ schema_version: "1.0.0", outcome: "infected", notes: "Trojan.Generic detected" })).toEqual({ schema_version: "1.0.0", outcome: "INFECTED", notes: "Trojan.Generic detected" });
  });

  it("rejects an unsupported scan outcome or oversized notes", () => {
    expect(() => validateDocumentScanResult({ schema_version: "1.0.0", outcome: "SUSPICIOUS" })).toThrowError(PlatformValidationError);
    expect(() => validateDocumentScanResult({ schema_version: "1.0.0", outcome: "CLEAN", notes: "x".repeat(1_001) })).toThrowError(PlatformValidationError);
  });

  it("normalizes a document retention hold application with a retention date", () => {
    expect(validateDocumentHold({ schema_version: "1.0.0", action: "apply", notes: "Subject to an ongoing legal request.", retained_until: "2031-01-01" })).toEqual({
      schema_version: "1.0.0", action: "APPLY", notes: "Subject to an ongoing legal request.", retained_until: "2031-01-01",
    });
  });

  it("normalizes a document retention hold release without a retention date", () => {
    expect(validateDocumentHold({ schema_version: "1.0.0", action: "release", notes: "Legal request concluded." })).toEqual({
      schema_version: "1.0.0", action: "RELEASE", notes: "Legal request concluded.",
    });
  });

  it("rejects a hold with too-short notes, an invalid date, or a release that sets retained_until", () => {
    expect(() => validateDocumentHold({ schema_version: "1.0.0", action: "APPLY", notes: "short" })).toThrowError(PlatformValidationError);
    expect(() => validateDocumentHold({ schema_version: "1.0.0", action: "APPLY", notes: "Valid enough reason text.", retained_until: "not-a-date" })).toThrowError(PlatformValidationError);
    expect(() => validateDocumentHold({ schema_version: "1.0.0", action: "RELEASE", notes: "Valid enough reason text.", retained_until: "2031-01-01" })).toThrowError(PlatformValidationError);
  });

  it("accepts a versioned, field-free export command", () => {
    expect(validateExportCommand({ schema_version: "1.0.0" })).toEqual({ schema_version: "1.0.0" });
  });

  it("rejects an export command with an unsupported schema version", () => {
    expect(() => validateExportCommand({ schema_version: "2.0.0" })).toThrowError(PlatformValidationError);
    expect(() => validateExportCommand(null)).toThrowError(PlatformValidationError);
  });

  it("normalizes an export cancellation with a valid reason", () => {
    expect(validateExportCancellation({ schema_version: "1.0.0", reason: "No longer needed for the audit." })).toEqual({
      schema_version: "1.0.0", reason: "No longer needed for the audit.",
    });
  });

  it("rejects an export cancellation with a missing or oversized reason", () => {
    expect(() => validateExportCancellation({ schema_version: "1.0.0", reason: "hi" })).toThrowError(PlatformValidationError);
    expect(() => validateExportCancellation({ schema_version: "1.0.0", reason: "x".repeat(501) })).toThrowError(PlatformValidationError);
  });

  it("normalizes a RunModel command with a valid report_run_id", () => {
    const reportRunId = "72a56891-51af-4a62-b83f-594e147245db";
    expect(validateRunModelCommand({ schema_version: "1.0.0", report_run_id: reportRunId })).toEqual({ schema_version: "1.0.0", report_run_id: reportRunId });
  });

  it("rejects a RunModel command with a missing or invalid report_run_id", () => {
    expect(() => validateRunModelCommand({ schema_version: "1.0.0", report_run_id: "not valid!" })).toThrowError(PlatformValidationError);
    expect(() => validateRunModelCommand({ schema_version: "1.0.0" })).toThrowError(PlatformValidationError);
  });

  it("normalizes a PublishDataProduct command with a valid model_run_id", () => {
    const modelRunId = "72a56891-51af-4a62-b83f-594e147245db";
    expect(validatePublishDataProductCommand({ schema_version: "1.0.0", model_run_id: modelRunId })).toEqual({ schema_version: "1.0.0", model_run_id: modelRunId });
  });

  it("rejects a PublishDataProduct command with a missing or invalid model_run_id", () => {
    expect(() => validatePublishDataProductCommand({ schema_version: "1.0.0", model_run_id: "not valid!" })).toThrowError(PlatformValidationError);
    expect(() => validatePublishDataProductCommand(null)).toThrowError(PlatformValidationError);
  });

  it("normalizes a RequestPlatformChange command for each target type", () => {
    expect(validatePlatformChangeRequest({ schema_version: "1.0.0", target_type: "feature_flag", target_id: "flag-itas-integration", proposed_value: { enabled: true }, reason: "Enable ITAS for the pilot cohort." })).toEqual({
      schema_version: "1.0.0", target_type: "FEATURE_FLAG", target_id: "flag-itas-integration", proposed_value: { enabled: true }, reason: "Enable ITAS for the pilot cohort.",
    });
  });

  it("rejects a RequestPlatformChange command with an invalid target_type, an oversized proposed_value, or a short reason", () => {
    expect(() => validatePlatformChangeRequest({ schema_version: "1.0.0", target_type: "SOMETHING_ELSE", target_id: "flag-itas-integration", proposed_value: { enabled: true }, reason: "Valid reason text." })).toThrowError(PlatformValidationError);
    expect(() => validatePlatformChangeRequest({ schema_version: "1.0.0", target_type: "FEATURE_FLAG", target_id: "flag-itas-integration", proposed_value: { blob: "x".repeat(5_000) }, reason: "Valid reason text." })).toThrowError(PlatformValidationError);
    expect(() => validatePlatformChangeRequest({ schema_version: "1.0.0", target_type: "FEATURE_FLAG", target_id: "flag-itas-integration", proposed_value: { enabled: true }, reason: "hi" })).toThrowError(PlatformValidationError);
  });

  it("normalizes a DecidePlatformChange command", () => {
    expect(validatePlatformChangeDecision({ schema_version: "1.0.0", decision: "approve", notes: "Confirmed with the release checklist." })).toEqual({
      schema_version: "1.0.0", decision: "APPROVE", notes: "Confirmed with the release checklist.",
    });
  });

  it("rejects a DecidePlatformChange command with an invalid decision or missing notes", () => {
    expect(() => validatePlatformChangeDecision({ schema_version: "1.0.0", decision: "MAYBE", notes: "Valid enough notes." })).toThrowError(PlatformValidationError);
    expect(() => validatePlatformChangeDecision({ schema_version: "1.0.0", decision: "REJECT", notes: "no" })).toThrowError(PlatformValidationError);
  });

  it("normalizes a ProvisionStaff command for an eligible platform role", () => {
    expect(validateProvisionStaff({ schema_version: "1.0.0", external_user_id: "ext-staff-0001", email: "Staff@NamRA.test", display_name: "New Staff Member", role: "security_analyst" })).toEqual({
      schema_version: "1.0.0", external_user_id: "ext-staff-0001", email: "staff@namra.test", display_name: "New Staff Member", role: "SECURITY_ANALYST",
    });
  });

  it("rejects a ProvisionStaff command with an invalid email or a non-provisionable role", () => {
    expect(() => validateProvisionStaff({ schema_version: "1.0.0", external_user_id: "ext-staff-0002", email: "not-an-email", display_name: "New Staff Member", role: "SECURITY_ANALYST" })).toThrowError(PlatformValidationError);
    expect(() => validateProvisionStaff({ schema_version: "1.0.0", external_user_id: "ext-staff-0003", email: "staff2@namra.test", display_name: "New Staff Member", role: "TAXPAYER_OWNER" })).toThrowError(PlatformValidationError);
  });
});
