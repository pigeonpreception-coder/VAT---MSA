import { describe, expect, it } from "vitest";
import { SecurityValidationError, validateIncidentAction, validateIncidentClosure, validateIncidentCreate } from "@/lib/domain/security";

describe("security incident validation", () => {
  it("normalizes a manual incident creation with an optional source event and subject", () => {
    expect(validateIncidentCreate({ schema_version: "1.0.0", title: "Suspicious bulk export activity", severity: "high", source_event_id: "sec-0001", subject_user_id: "usr-0001", details: "Multiple large exports requested outside business hours." })).toEqual({
      schema_version: "1.0.0", title: "Suspicious bulk export activity", severity: "HIGH", sourceEventId: "sec-0001", subjectUserId: "usr-0001", details: "Multiple large exports requested outside business hours.",
    });
  });

  it("normalizes a manual incident creation with no source event or subject", () => {
    expect(validateIncidentCreate({ schema_version: "1.0.0", title: "Unusual login pattern", severity: "medium", details: "Reported by an external partner." })).toEqual({
      schema_version: "1.0.0", title: "Unusual login pattern", severity: "MEDIUM", details: "Reported by an external partner.",
    });
  });

  it("rejects an incident creation with an invalid severity or too-short title", () => {
    expect(() => validateIncidentCreate({ schema_version: "1.0.0", title: "Bad", severity: "MEDIUM", details: "Valid enough detail text." })).toThrowError(SecurityValidationError);
    expect(() => validateIncidentCreate({ schema_version: "1.0.0", title: "A valid incident title", severity: "SEVERE", details: "Valid enough detail text." })).toThrowError(SecurityValidationError);
  });

  it("normalizes an incident action (Contain/Revoke)", () => {
    expect(validateIncidentAction({ schema_version: "1.0.0", notes: "Escalated to the on-call security lead." })).toEqual({ schema_version: "1.0.0", notes: "Escalated to the on-call security lead." });
  });

  it("rejects an incident action with missing notes", () => {
    expect(() => validateIncidentAction({ schema_version: "1.0.0", notes: "no" })).toThrowError(SecurityValidationError);
  });

  it("normalizes an incident closure", () => {
    expect(validateIncidentClosure({ schema_version: "1.0.0", resolution_notes: "Confirmed as a false positive after reviewing the access logs." })).toEqual({
      schema_version: "1.0.0", resolutionNotes: "Confirmed as a false positive after reviewing the access logs.",
    });
  });

  it("rejects an incident closure with too-short resolution notes", () => {
    expect(() => validateIncidentClosure({ schema_version: "1.0.0", resolution_notes: "short" })).toThrowError(SecurityValidationError);
  });
});
