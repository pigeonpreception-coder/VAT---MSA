export class SecurityValidationError extends Error {
  readonly messages: Array<{ code: string; path: string; message: string }>;
  constructor(messages: Array<{ code: string; path: string; message: string }>) {
    super("Security command failed validation.");
    this.name = "SecurityValidationError";
    this.messages = messages;
  }
}

const ID_PATTERN = /^[A-Za-z0-9][A-Za-z0-9._:-]{1,99}$/;
const SEVERITIES = new Set(["LOW", "MEDIUM", "HIGH", "CRITICAL"]);

function text(value: unknown): string {
  return typeof value === "string" ? value.trim() : "";
}

export type IncidentCreateSubmission = { schema_version: "1.0.0"; title: string; severity: string; sourceEventId?: string; subjectUserId?: string; details: string };

/** Module 8 Phase B CreateIncident: manual incident creation — the SOC-analyst counterpart to a rule-detected incident (see evaluateDetectionRules in lib/security/request.ts). */
export function validateIncidentCreate(payload: unknown): IncidentCreateSubmission {
  if (!payload || typeof payload !== "object" || Array.isArray(payload)) throw new SecurityValidationError([{ code: "PAYLOAD_INVALID", path: "/", message: "The request body must be an object." }]);
  const input = payload as Record<string, unknown>;
  const messages: Array<{ code: string; path: string; message: string }> = [];
  if (input.schema_version !== "1.0.0") messages.push({ code: "SCHEMA_VERSION_UNSUPPORTED", path: "/schema_version", message: "schema_version must be 1.0.0." });
  const title = text(input.title);
  if (title.length < 5 || title.length > 200) messages.push({ code: "TITLE_INVALID", path: "/title", message: "title must contain 5 to 200 characters." });
  const severity = text(input.severity).toUpperCase();
  if (!SEVERITIES.has(severity)) messages.push({ code: "SEVERITY_INVALID", path: "/severity", message: "severity must be LOW, MEDIUM, HIGH or CRITICAL." });
  const sourceEventId = text(input.source_event_id) || undefined;
  if (sourceEventId && !ID_PATTERN.test(sourceEventId)) messages.push({ code: "SOURCE_EVENT_ID_INVALID", path: "/source_event_id", message: "source_event_id is invalid." });
  const subjectUserId = text(input.subject_user_id) || undefined;
  if (subjectUserId && !ID_PATTERN.test(subjectUserId)) messages.push({ code: "SUBJECT_USER_ID_INVALID", path: "/subject_user_id", message: "subject_user_id is invalid." });
  const details = text(input.details);
  if (details.length < 5 || details.length > 1_000) messages.push({ code: "DETAILS_INVALID", path: "/details", message: "details must contain 5 to 1000 characters." });
  if (messages.length) throw new SecurityValidationError(messages);
  return { schema_version: "1.0.0", title, severity, ...(sourceEventId ? { sourceEventId } : {}), ...(subjectUserId ? { subjectUserId } : {}), details };
}

export type IncidentActionSubmission = { schema_version: "1.0.0"; notes: string };

/** Module 8 Phase B Contain/Revoke: every incident action needs a recorded rationale, the same as every other maker-checker/governance action in this codebase. */
export function validateIncidentAction(payload: unknown): IncidentActionSubmission {
  if (!payload || typeof payload !== "object" || Array.isArray(payload)) throw new SecurityValidationError([{ code: "PAYLOAD_INVALID", path: "/", message: "The request body must be an object." }]);
  const input = payload as Record<string, unknown>;
  const messages: Array<{ code: string; path: string; message: string }> = [];
  if (input.schema_version !== "1.0.0") messages.push({ code: "SCHEMA_VERSION_UNSUPPORTED", path: "/schema_version", message: "schema_version must be 1.0.0." });
  const notes = text(input.notes);
  if (notes.length < 5 || notes.length > 500) messages.push({ code: "NOTES_INVALID", path: "/notes", message: "notes must contain 5 to 500 characters." });
  if (messages.length) throw new SecurityValidationError(messages);
  return { schema_version: "1.0.0", notes };
}

export type IncidentClosureSubmission = { schema_version: "1.0.0"; resolutionNotes: string };

/** Module 8 Phase B Close. */
export function validateIncidentClosure(payload: unknown): IncidentClosureSubmission {
  if (!payload || typeof payload !== "object" || Array.isArray(payload)) throw new SecurityValidationError([{ code: "PAYLOAD_INVALID", path: "/", message: "The request body must be an object." }]);
  const input = payload as Record<string, unknown>;
  const messages: Array<{ code: string; path: string; message: string }> = [];
  if (input.schema_version !== "1.0.0") messages.push({ code: "SCHEMA_VERSION_UNSUPPORTED", path: "/schema_version", message: "schema_version must be 1.0.0." });
  const resolutionNotes = text(input.resolution_notes);
  if (resolutionNotes.length < 10 || resolutionNotes.length > 1_000) messages.push({ code: "RESOLUTION_NOTES_INVALID", path: "/resolution_notes", message: "resolution_notes must contain 10 to 1000 characters." });
  if (messages.length) throw new SecurityValidationError(messages);
  return { schema_version: "1.0.0", resolutionNotes };
}
