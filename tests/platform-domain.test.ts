import { describe, expect, it } from "vitest";
import { PlatformValidationError, safeFileName, validateDocumentHold, validateDocumentScanResult, validateOfflineBatch, validateReportParameters } from "@/lib/domain/platform";

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
});
