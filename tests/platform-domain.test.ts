import { describe, expect, it } from "vitest";
import { PlatformValidationError, safeFileName, validateOfflineBatch, validateReportParameters } from "@/lib/domain/platform";

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
});
