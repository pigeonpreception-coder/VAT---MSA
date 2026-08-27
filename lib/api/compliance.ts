import { AccessDeniedError, getCurrentUser, requirePermission } from "@/lib/auth";
import {
  addCaseNote,
  addEvidence,
  approveRiskAction,
  assignRiskReview,
  cancelNotification,
  closeConversation,
  ComplianceResourceError,
  createObligation,
  disputeRefund,
  evaluateRisk,
  fileDispute,
  getCaseEvidence,
  getCaseNotes,
  getCaseTimeline,
  getComplianceSnapshot,
  getConversation,
  getInbox,
  getNotifications,
  getRestrictedRisk,
  issueFinding,
  markNotificationRead,
  markObligationSatisfied,
  openAuditCase,
  queueNotification,
  recordEvidenceCustodyEvent,
  requestRefund,
  respondToConversation,
  sendNotice,
  transitionCase,
  transitionRefundClaim,
  updateNotificationPreference,
} from "@/lib/data/compliance-repository";
import { RepositoryConflictError } from "@/lib/data/repository";
import { ComplianceValidationError } from "@/lib/domain/compliance";
import { emitStructuredSecurityLog, enforceRateLimits, readBoundedJson, recordSecurityEvent, requestContext, RequestGuardError } from "@/lib/security/request";

export type ComplianceCommand =
  | "OPEN_AUDIT_CASE" | "FILE_DISPUTE" | "REQUEST_REFUND" | "TRANSITION_REFUND_CLAIM" | "DISPUTE_REFUND_CLAIM"
  | "CREATE_OBLIGATION" | "MARK_OBLIGATION_SATISFIED" | "TRANSITION_CASE" | "ISSUE_FINDING"
  | "ASSIGN_RISK_REVIEW" | "APPROVE_RISK_ACTION" | "EVALUATE_RISK"
  | "ADD_EVIDENCE" | "RECORD_EVIDENCE_CUSTODY_EVENT" | "ADD_CASE_NOTE"
  | "SEND_NOTICE" | "RESPOND_TO_CONVERSATION" | "CLOSE_CONVERSATION"
  | "QUEUE_NOTIFICATION" | "CANCEL_NOTIFICATION" | "MARK_NOTIFICATION_READ" | "UPDATE_NOTIFICATION_PREFERENCE";

function problem(status: number, code: string, title: string, detail: string, correlationId: string, errors?: unknown, retryAfter?: number | null) {
  return Response.json({ type: `https://vat-msa.local/problems/${code.toLowerCase().replaceAll("_", "-")}`, title, status, code, detail, correlationId, ...(errors ? { errors } : {}) }, { status, headers: { "content-type": "application/problem+json", "x-correlation-id": correlationId, "cache-control": "no-store", ...(retryAfter ? { "retry-after": String(retryAfter) } : {}) } });
}

export async function handleComplianceList(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "compliance:read");
    return Response.json(await getComplianceSnapshot(user), { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    return problem(500, "INTERNAL_ERROR", "Internal error", "The compliance workspace is temporarily unavailable.", context.correlationId);
  }
}

/** Module 4 Phase C CaseTimeline. Readable by a taxpayer-scoped actor for their own case (see getCaseTimeline's tenant check) as well as any national-scope actor. */
export async function handleCaseTimeline(request: Request, resourceId: string) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "compliance:read");
    const timeline = await getCaseTimeline(resourceId, user);
    if (!timeline) return problem(404, "RESOURCE_NOT_FOUND", "Not found", "Audit case was not found.", context.correlationId);
    return Response.json(timeline, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    return problem(500, "INTERNAL_ERROR", "Internal error", "The case timeline is temporarily unavailable.", context.correlationId);
  }
}

/** Module 4 Phase D GetCaseEvidence. Same tenant-visibility rule as CaseTimeline: national-scope or the case's own taxpayer. */
export async function handleCaseEvidence(request: Request, resourceId: string) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "compliance:read");
    const evidence = await getCaseEvidence(resourceId, user);
    if (!evidence) return problem(404, "RESOURCE_NOT_FOUND", "Not found", "Audit case was not found.", context.correlationId);
    return Response.json(evidence, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    return problem(500, "INTERNAL_ERROR", "Internal error", "The case evidence register is temporarily unavailable.", context.correlationId);
  }
}

/** Module 4 Phase D GetCaseNotes. Same tenant-visibility rule as CaseTimeline/GetCaseEvidence. */
export async function handleCaseNotes(request: Request, resourceId: string) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "compliance:read");
    const notes = await getCaseNotes(resourceId, user);
    if (!notes) return problem(404, "RESOURCE_NOT_FOUND", "Not found", "Audit case was not found.", context.correlationId);
    return Response.json(notes, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    return problem(500, "INTERNAL_ERROR", "Internal error", "The case notes are temporarily unavailable.", context.correlationId);
  }
}

/** Module 4 Phase A GetRestrictedRisk. Distinct from the broad compliance:read-gated snapshot — gated on risk:read, and getRestrictedRisk itself refuses any non-national-scope actor outright (no taxpayer self-access, unlike CaseTimeline). */
export async function handleRestrictedRiskQuery(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "risk:read");
    const url = new URL(request.url);
    const result = await getRestrictedRisk(user, url.searchParams);
    return Response.json(result, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof ComplianceValidationError) return problem(422, "VALIDATION_FAILED", "Validation failed", error.message, context.correlationId, error.messages.map((item) => ({ ...item, severity: "ERROR" })));
    if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    return problem(500, "INTERNAL_ERROR", "Internal error", "Risk indicators are temporarily unavailable.", context.correlationId);
  }
}

/** Module 6 Phase C GetInbox: lists correspondence threads (not raw messages), filterable/paginated with a real total_count. */
export async function handleInbox(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "compliance:read");
    const result = await getInbox(user, new URL(request.url).searchParams);
    return Response.json(result, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof ComplianceValidationError) return problem(422, "VALIDATION_FAILED", "Validation failed", error.message, context.correlationId, error.messages.map((item) => ({ ...item, severity: "ERROR" })));
    if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    return problem(500, "INTERNAL_ERROR", "Internal error", "The correspondence inbox is temporarily unavailable.", context.correlationId);
  }
}

/** Module 6 Phase C: reads one full correspondence thread. Same tenant-visibility rule as CaseTimeline. */
export async function handleConversation(request: Request, resourceId: string) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "compliance:read");
    const conversation = await getConversation(resourceId, user);
    if (!conversation) return problem(404, "RESOURCE_NOT_FOUND", "Not found", "Correspondence thread was not found.", context.correlationId);
    return Response.json(conversation, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    return problem(500, "INTERNAL_ERROR", "Internal error", "The correspondence thread is temporarily unavailable.", context.correlationId);
  }
}

/** Module 6 Phase D GetNotifications: a dedicated, filterable, paginated read of the current actor's own notifications — previously only bundled inside getComplianceSnapshot's fixed 100-row projection. Gated on dashboard:read (near-universal) since notifications are personal to every role, not just compliance-facing ones. */
export async function handleNotifications(request: Request) {
  const context = await requestContext(request);
  try {
    const user = await getCurrentUser();
    requirePermission(user, "dashboard:read");
    const result = await getNotifications(user, new URL(request.url).searchParams);
    return Response.json(result, { headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    if (error instanceof ComplianceValidationError) return problem(422, "VALIDATION_FAILED", "Validation failed", error.message, context.correlationId, error.messages.map((item) => ({ ...item, severity: "ERROR" })));
    if (error instanceof AccessDeniedError) return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    return problem(500, "INTERNAL_ERROR", "Internal error", "Notifications are temporarily unavailable.", context.correlationId);
  }
}

export async function handleComplianceCommand(request: Request, permission: string, command: ComplianceCommand, resourceId?: string) {
  const context = await requestContext(request);
  const startedAt = Date.now();
  let actorId: string | undefined;
  try {
    const user = await getCurrentUser();
    actorId = user.userId;
    requirePermission(user, permission);
    await enforceRateLimits([
      { key: `compliance:${command}:actor:${user.userId}`, limit: 30, windowSeconds: 60 },
      { key: `compliance:${command}:scope:${user.taxpayerId ?? user.role}`, limit: 120, windowSeconds: 60 },
      { key: `compliance:${command}:global`, limit: 1_000, windowSeconds: 60 },
    ]);
    const payload = await readBoundedJson<never>(request, 131_072);
    const key = request.headers.get("idempotency-key") ?? "";
    let result: Record<string, unknown> | null;
    if (command === "OPEN_AUDIT_CASE") result = await openAuditCase(payload, user, key, context.correlationId) as Record<string, unknown> | null;
    else if (command === "FILE_DISPUTE") result = await fileDispute(payload, user, key, context.correlationId) as Record<string, unknown> | null;
    else if (command === "REQUEST_REFUND") result = await requestRefund(payload, user, key, context.correlationId) as Record<string, unknown> | null;
    else if (command === "TRANSITION_REFUND_CLAIM") {
      if (!resourceId) throw new ComplianceResourceError("Refund claim id is required.", 400);
      result = await transitionRefundClaim(resourceId, payload, user, key, context.correlationId) as Record<string, unknown> | null;
    } else if (command === "DISPUTE_REFUND_CLAIM") {
      if (!resourceId) throw new ComplianceResourceError("Refund claim id is required.", 400);
      result = await disputeRefund(resourceId, payload, user, key, context.correlationId) as Record<string, unknown> | null;
    } else if (command === "CREATE_OBLIGATION") result = await createObligation(payload, user, key, context.correlationId) as Record<string, unknown> | null;
    else if (command === "MARK_OBLIGATION_SATISFIED") {
      if (!resourceId) throw new ComplianceResourceError("Tax obligation id is required.", 400);
      result = await markObligationSatisfied(resourceId, payload, user, key, context.correlationId) as Record<string, unknown> | null;
    } else if (command === "TRANSITION_CASE") {
      if (!resourceId) throw new ComplianceResourceError("Audit case id is required.", 400);
      result = await transitionCase(resourceId, payload, user, key, context.correlationId) as Record<string, unknown> | null;
    } else if (command === "ISSUE_FINDING") {
      if (!resourceId) throw new ComplianceResourceError("Audit case id is required.", 400);
      result = await issueFinding(resourceId, payload, user, key, context.correlationId) as Record<string, unknown> | null;
    } else if (command === "ASSIGN_RISK_REVIEW") {
      if (!resourceId) throw new ComplianceResourceError("Risk indicator id is required.", 400);
      result = await assignRiskReview(resourceId, payload, user, key, context.correlationId) as Record<string, unknown> | null;
    } else if (command === "APPROVE_RISK_ACTION") {
      if (!resourceId) throw new ComplianceResourceError("Risk indicator id is required.", 400);
      result = await approveRiskAction(resourceId, payload, user, key, context.correlationId) as Record<string, unknown> | null;
    } else if (command === "EVALUATE_RISK") {
      if (!resourceId) throw new ComplianceResourceError("Taxpayer id is required.", 400);
      result = await evaluateRisk(resourceId, payload, user, key, context.correlationId) as unknown as Record<string, unknown> | null;
    } else if (command === "ADD_EVIDENCE") {
      if (!resourceId) throw new ComplianceResourceError("Audit case id is required.", 400);
      result = await addEvidence(resourceId, payload, user, key, context.correlationId) as Record<string, unknown> | null;
    } else if (command === "RECORD_EVIDENCE_CUSTODY_EVENT") {
      if (!resourceId) throw new ComplianceResourceError("Evidence id is required.", 400);
      result = await recordEvidenceCustodyEvent(resourceId, payload, user, key, context.correlationId) as Record<string, unknown> | null;
    } else if (command === "ADD_CASE_NOTE") {
      if (!resourceId) throw new ComplianceResourceError("Audit case id is required.", 400);
      result = await addCaseNote(resourceId, payload, user, key, context.correlationId) as Record<string, unknown> | null;
    } else if (command === "SEND_NOTICE") result = await sendNotice(payload, user, key, context.correlationId) as Record<string, unknown> | null;
    else if (command === "RESPOND_TO_CONVERSATION") {
      if (!resourceId) throw new ComplianceResourceError("Correspondence thread id is required.", 400);
      result = await respondToConversation(resourceId, payload, user, key, context.correlationId) as Record<string, unknown> | null;
    } else if (command === "CLOSE_CONVERSATION") {
      if (!resourceId) throw new ComplianceResourceError("Correspondence thread id is required.", 400);
      result = await closeConversation(resourceId, payload, user, key, context.correlationId) as Record<string, unknown> | null;
    } else if (command === "QUEUE_NOTIFICATION") result = await queueNotification(payload, user, key, context.correlationId) as Record<string, unknown> | null;
    else if (command === "CANCEL_NOTIFICATION") {
      if (!resourceId) throw new ComplianceResourceError("Notification id is required.", 400);
      result = await cancelNotification(resourceId, payload, user, key, context.correlationId) as Record<string, unknown> | null;
    } else if (command === "MARK_NOTIFICATION_READ") {
      if (!resourceId) throw new ComplianceResourceError("Notification id is required.", 400);
      result = await markNotificationRead(resourceId, user, key, context.correlationId) as Record<string, unknown> | null;
    } else {
      result = await updateNotificationPreference(payload, user, key, context.correlationId) as Record<string, unknown> | null;
    }
    if (!result) throw new RepositoryConflictError("The idempotent compliance resource is no longer available.");
    emitStructuredSecurityLog({ level: "INFO", event: command, correlationId: context.correlationId, actorId, outcome: "SUCCESS", durationMs: Date.now() - startedAt });
    const status = command === "TRANSITION_REFUND_CLAIM" || command === "DISPUTE_REFUND_CLAIM" || command === "MARK_OBLIGATION_SATISFIED" || command === "TRANSITION_CASE" || command === "ASSIGN_RISK_REVIEW" || command === "APPROVE_RISK_ACTION" || command === "EVALUATE_RISK" || command === "RECORD_EVIDENCE_CUSTODY_EVENT" || command === "CLOSE_CONVERSATION" || command === "CANCEL_NOTIFICATION" || command === "MARK_NOTIFICATION_READ" || command === "UPDATE_NOTIFICATION_PREFERENCE" ? 200 : 201;
    return Response.json({ resource: result }, { status, headers: { "x-correlation-id": context.correlationId, "cache-control": "no-store" } });
  } catch (error) {
    emitStructuredSecurityLog({ level: error instanceof AccessDeniedError || error instanceof RequestGuardError ? "WARN" : "ERROR", event: command, correlationId: context.correlationId, actorId, outcome: error instanceof Error ? error.name : "FAILED", durationMs: Date.now() - startedAt });
    if (error instanceof RequestGuardError) return problem(error.status, error.code, error.status === 429 ? "Rate limited" : "Bad request", error.message, context.correlationId, undefined, error.retryAfter);
    if (error instanceof ComplianceValidationError) return problem(422, "VALIDATION_FAILED", "Validation failed", error.message, context.correlationId, error.messages.map((item) => ({ ...item, severity: "ERROR" })));
    if (error instanceof ComplianceResourceError) return problem(error.status, error.status === 404 ? "RESOURCE_NOT_FOUND" : "RESOURCE_INVALID", error.status === 404 ? "Not found" : "Invalid resource", error.message, context.correlationId);
    if (error instanceof RepositoryConflictError) return problem(409, "COMPLIANCE_CONFLICT", "Conflict", error.message, context.correlationId);
    if (error instanceof AccessDeniedError) {
      await recordSecurityEvent({ eventType: "AUTHORISATION_DENIED", severity: "HIGH", actorId, context, action: command, outcome: "DENIED", details: { status: error.status } }).catch(() => undefined);
      return problem(error.status, error.status === 401 ? "AUTH_REQUIRED" : "ACCESS_DENIED", error.status === 401 ? "Unauthorized" : "Forbidden", error.message, context.correlationId);
    }
    return problem(500, "INTERNAL_ERROR", "Internal error", "The compliance command could not be completed.", context.correlationId);
  }
}
