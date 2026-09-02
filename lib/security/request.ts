import { sha256Hex } from "@/lib/domain/invoice";
import type { UserContext } from "@/lib/domain/types";

const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const SAFE_DEVICE_PATTERN = /^[A-Za-z0-9._:-]{1,100}$/;
export const MAX_INVOICE_PAYLOAD_BYTES = 1_048_576;

export class RequestGuardError extends Error {
  readonly status: number;
  readonly code: string;
  readonly retryAfter: number | null;

  constructor(status: number, code: string, message: string, retryAfter: number | null = null) {
    super(message);
    this.name = "RequestGuardError";
    this.status = status;
    this.code = code;
    this.retryAfter = retryAfter;
  }
}

export type RequestContext = {
  correlationId: string;
  sourceToken: string;
  deviceId: string;
};

export function correlationIdFor(request: Request): string {
  const supplied = request.headers.get("x-correlation-id")?.trim() ?? "";
  return UUID_PATTERN.test(supplied) ? supplied : crypto.randomUUID();
}

export async function requestContext(request: Request): Promise<RequestContext> {
  const rawSource = request.headers.get("cf-connecting-ip")
    ?? request.headers.get("x-forwarded-for")?.split(",")[0]?.trim()
    ?? "unavailable";
  const rawDevice = request.headers.get("x-device-id")?.trim() ?? "browser-session";
  return {
    correlationId: correlationIdFor(request),
    sourceToken: `sha256:${(await sha256Hex(rawSource.slice(0, 128))).slice(0, 24)}`,
    deviceId: SAFE_DEVICE_PATTERN.test(rawDevice) ? rawDevice : "invalid-device-id",
  };
}

export async function readBoundedJson<T>(request: Request, maxBytes = MAX_INVOICE_PAYLOAD_BYTES): Promise<T> {
  const contentType = request.headers.get("content-type")?.toLowerCase() ?? "";
  if (!contentType.startsWith("application/json")) {
    throw new RequestGuardError(415, "CONTENT_TYPE_REQUIRED", "Content-Type must be application/json.");
  }
  const contentLength = Number(request.headers.get("content-length") ?? "0");
  if (Number.isFinite(contentLength) && contentLength > maxBytes) {
    throw new RequestGuardError(413, "PAYLOAD_TOO_LARGE", `JSON payload must not exceed ${maxBytes} bytes.`);
  }
  const reader = request.body?.getReader();
  if (!reader) throw new RequestGuardError(400, "EMPTY_BODY", "A JSON request body is required.");
  const chunks: Uint8Array[] = [];
  let receivedBytes = 0;
  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    receivedBytes += value.byteLength;
    if (receivedBytes > maxBytes) {
      await reader.cancel("VAT-MSA payload limit exceeded");
      throw new RequestGuardError(413, "PAYLOAD_TOO_LARGE", `JSON payload must not exceed ${maxBytes} bytes.`);
    }
    chunks.push(value);
  }
  if (receivedBytes === 0) throw new RequestGuardError(400, "EMPTY_BODY", "A JSON request body is required.");
  const bytes = new Uint8Array(receivedBytes);
  let offset = 0;
  for (const chunk of chunks) {
    bytes.set(chunk, offset);
    offset += chunk.byteLength;
  }
  try {
    return JSON.parse(new TextDecoder().decode(bytes)) as T;
  } catch {
    throw new RequestGuardError(400, "INVALID_JSON", "The request body is not valid JSON.");
  }
}

type RateBucket = { key: string; limit: number; windowSeconds: number };

export async function enforceInvoiceRateLimits(user: UserContext, context: RequestContext): Promise<void> {
  const tenant = user.taxpayerId ?? `role:${user.role}`;
  const buckets: RateBucket[] = [
    { key: `invoice:actor:${user.userId}`, limit: 120, windowSeconds: 60 },
    { key: `invoice:device:${context.deviceId}`, limit: 180, windowSeconds: 60 },
    { key: `invoice:source:${context.sourceToken}`, limit: 240, windowSeconds: 60 },
    { key: `invoice:tenant:${tenant}`, limit: 600, windowSeconds: 60 },
    { key: "invoice:global", limit: 5_000, windowSeconds: 60 },
  ];
  await enforceRateLimits(buckets);
}

export async function enforceRegistrationRateLimits(user: UserContext, context: RequestContext): Promise<void> {
  const tenant = user.taxpayerId ?? `role:${user.role}`;
  await enforceRateLimits([
    { key: `registration:actor:${user.userId}`, limit: 10, windowSeconds: 300 },
    { key: `registration:source:${context.sourceToken}`, limit: 20, windowSeconds: 300 },
    { key: `registration:tenant:${tenant}`, limit: 30, windowSeconds: 300 },
    { key: "registration:global", limit: 500, windowSeconds: 300 },
  ]);
}

/**
 * Security fix 2026-08-27 (SECURITY_GAP_ASSESSMENT.md item #8): a full-repo
 * audit found the identity, control-plane, reconciliation and vat-rule
 * route families called enforceRateLimits nowhere at all — roughly a third
 * of command routes had no rate limiting, contradicting the pattern
 * established everywhere else (compliance/vat-lifecycle/business/platform/
 * security/audit's own dispatchers, plus enforceInvoiceRateLimits/
 * enforceRegistrationRateLimits above). One generic, per-command bucket
 * builder — actor/tenant-or-role/global — reused by four thin,
 * family-named wrappers below, mirroring the exact 3-bucket shape
 * lib/api/compliance.ts's own handleComplianceCommand already uses.
 */
function commandRateLimitBuckets(family: string, command: string, user: UserContext): RateBucket[] {
  const tenant = user.taxpayerId ?? `role:${user.role}`;
  return [
    { key: `${family}:${command}:actor:${user.userId}`, limit: 30, windowSeconds: 60 },
    { key: `${family}:${command}:tenant:${tenant}`, limit: 120, windowSeconds: 60 },
    { key: `${family}:${command}:global`, limit: 1_000, windowSeconds: 60 },
  ];
}

export async function enforceIdentityRateLimits(command: string, user: UserContext): Promise<void> {
  await enforceRateLimits(commandRateLimitBuckets("identity", command, user));
}

export async function enforceControlPlaneRateLimits(command: string, user: UserContext): Promise<void> {
  await enforceRateLimits(commandRateLimitBuckets("control-plane", command, user));
}

export async function enforceReconciliationRateLimits(command: string, user: UserContext): Promise<void> {
  await enforceRateLimits(commandRateLimitBuckets("reconciliation", command, user));
}

export async function enforceVatRuleRateLimits(command: string, user: UserContext): Promise<void> {
  await enforceRateLimits(commandRateLimitBuckets("vat-rule", command, user));
}

/** claimInvitation is a token-guessing surface (Sec 18) — a stricter, purpose-built limit, not the generic per-command one above, keyed on the source IP/device rather than an authenticated actor (the claimant has no account yet at the point they call this). */
export async function enforceInvitationClaimRateLimits(context: RequestContext): Promise<void> {
  await enforceRateLimits([
    { key: `invitation-claim:source:${context.sourceToken}`, limit: 10, windowSeconds: 300 },
    { key: `invitation-claim:device:${context.deviceId}`, limit: 15, windowSeconds: 300 },
    { key: "invitation-claim:global", limit: 200, windowSeconds: 300 },
  ]);
}

/** GET /api/v1/verify/[token] is a public, unauthenticated, cached certificate-lookup endpoint — an enumeration surface (Sec 18). Keyed on source/device only, since there is no actor. */
export async function enforceVerifyTokenRateLimits(context: RequestContext): Promise<void> {
  await enforceRateLimits([
    { key: `verify-token:source:${context.sourceToken}`, limit: 30, windowSeconds: 60 },
    { key: `verify-token:device:${context.deviceId}`, limit: 45, windowSeconds: 60 },
    { key: "verify-token:global", limit: 2_000, windowSeconds: 60 },
  ]);
}

export async function enforceRateLimits(buckets: RateBucket[], nowMs = Date.now()): Promise<void> {
  const { ensureDatabase } = await import("@/db/runtime");
  const db = await ensureDatabase();
  const statements = buckets.map((bucket) => {
    const windowStart = Math.floor(nowMs / (bucket.windowSeconds * 1_000));
    const expiresAt = (windowStart + 2) * bucket.windowSeconds;
    return db.prepare(`INSERT INTO rate_limit_windows (bucket_key, window_start, request_count, expires_at)
      VALUES (?, ?, 1, ?)
      ON CONFLICT(bucket_key, window_start) DO UPDATE SET request_count = request_count + 1
      RETURNING request_count`).bind(bucket.key, windowStart, expiresAt);
  });
  const results = await db.batch<{ request_count: number }>(statements);
  for (let index = 0; index < results.length; index += 1) {
    const count = Number(results[index].results?.[0]?.request_count ?? 0);
    if (count > buckets[index].limit) {
      throw new RequestGuardError(429, "RATE_LIMIT_EXCEEDED", "Request rate exceeded the protected submission threshold.", buckets[index].windowSeconds);
    }
  }
  if (nowMs % 97 === 0) {
    await db.prepare("DELETE FROM rate_limit_windows WHERE expires_at < ?").bind(Math.floor(nowMs / 1_000)).run();
  }
}

export async function recordSecurityEvent(input: {
  eventType: string;
  severity: "LOW" | "MEDIUM" | "HIGH" | "CRITICAL";
  actorId?: string | null;
  context: RequestContext;
  action: string;
  outcome: string;
  details: Record<string, string | number | boolean | null>;
}): Promise<void> {
  const { ensureDatabase } = await import("@/db/runtime");
  const db = await ensureDatabase();
  const eventId = crypto.randomUUID();
  const now = new Date().toISOString();
  await db.prepare("INSERT INTO security_events VALUES (?,?,?,?,?,?,?,?,?,?)").bind(
    eventId, input.eventType, input.severity, input.actorId ?? null,
    input.context.sourceToken, input.context.correlationId, input.action, input.outcome,
    JSON.stringify(input.details), now,
  ).run();
  await evaluateDetectionRules(db, eventId, input.eventType, input.actorId ?? null, input.context.sourceToken, now).catch(() => undefined);
}

/**
 * Security fix 2026-08-27 (SECURITY_GAP_ASSESSMENT.md item #4): a 2026-08-26
 * audit found `AUTHORISATION_DENIED`/`RATE_LIMIT_EXCEEDED` events — the two
 * inputs Module 8's `REPEATED_AUTHORISATION_DENIALS`/`RATE_LIMIT_ABUSE`
 * detection rules actually watch — were emitted from only a handful of
 * route families, so those rules structurally could not fire across most
 * of the API. These two shared, best-effort recorders let every `lib/api/*`
 * problem+json helper wire both events with one call each, instead of
 * every individual route threading its own actor id and event-recording
 * logic through. `getCurrentUser()` is re-resolved here rather than
 * requiring every call site to pass one through: it is a pure, idempotent
 * read (no side effects), so calling it again on an already-failed request
 * is safe, gives real per-actor attribution for the common case (an
 * authenticated actor denied by a permission/role/rate-limit check), and
 * degrades gracefully to `actorId=null` for a genuinely unauthenticated
 * request. Neither function ever throws — a security-telemetry failure
 * must never mask the real error response, matching every existing call
 * site's own `.catch(() => undefined)` convention.
 */
export async function recordAuthorizationDenial(context: RequestContext, message: string, status: number): Promise<void> {
  const { getCurrentUser } = await import("@/lib/auth");
  const actorId = await getCurrentUser().then((user) => user.userId).catch(() => null);
  const permissionMatch = message.match(/does not have (\S+) permission/);
  await recordSecurityEvent({ eventType: "AUTHORISATION_DENIED", severity: "HIGH", actorId, context, action: permissionMatch ? permissionMatch[1] : "ACCESS_DENIED", outcome: "DENIED", details: { status } }).catch(() => undefined);
}

export async function recordRateLimitBreach(context: RequestContext, error: RequestGuardError, action = "RATE_LIMIT"): Promise<void> {
  if (![413, 429].includes(error.status)) return;
  const { getCurrentUser } = await import("@/lib/auth");
  const actorId = await getCurrentUser().then((user) => user.userId).catch(() => null);
  await recordSecurityEvent({ eventType: error.code, severity: error.status === 429 ? "MEDIUM" : "LOW", actorId, context, action, outcome: "REJECTED", details: { status: error.status } }).catch(() => undefined);
}

type DetectionRuleRow = { id: string; code: string; group_by: string; threshold_count: number; window_minutes: number; severity: string };

/**
 * Module 8 Phase B Detection: a small, fixed, code-versioned rule catalogue
 * (`security_detection_rules`, seed-only definitions — the same posture
 * Module 4's risk rules and Module 7's certified metrics already
 * established), evaluated inline on every security event rather than by a
 * polling job — this Workers deployment has no cron/queue infrastructure
 * (the same recurring gap Module 3's RunMatch and Module 6/7/8's own prior
 * phases already documented), so "build the workflow now even without a
 * production SOC" is done as a synchronous, event-driven check instead of
 * a scheduled one. A rule fires once its event_type/group_by count reaches
 * threshold_count within window_minutes, and is deliberately de-duplicated
 * against any already-open incident for the same rule+group so repeated
 * denials from the same actor don't spawn a new incident on every event
 * past the threshold.
 */
async function evaluateDetectionRules(db: D1Database, eventId: string, eventType: string, actorId: string | null, sourceToken: string, now: string): Promise<void> {
  const rules = await db.prepare("SELECT id,code,group_by,threshold_count,window_minutes,severity FROM security_detection_rules WHERE event_type=? AND status='ACTIVE'")
    .bind(eventType).all<DetectionRuleRow>();
  for (const rule of rules.results) {
    const groupKey = rule.group_by === "actor_id" ? actorId : sourceToken;
    if (!groupKey) continue;
    const column = rule.group_by === "actor_id" ? "actor_id" : "source_token";
    const windowStart = new Date(Date.parse(now) - rule.window_minutes * 60_000).toISOString();
    const count = await db.prepare(`SELECT COUNT(*) AS count FROM security_events WHERE event_type=? AND ${column}=? AND occurred_at>=?`)
      .bind(eventType, groupKey, windowStart).first<{ count: number }>();
    if (Number(count?.count ?? 0) < rule.threshold_count) continue;
    const existingOpen = await db.prepare("SELECT id FROM security_incidents WHERE detection_rule_id=? AND group_key=? AND status IN ('OPEN','CONTAINED')")
      .bind(rule.id, groupKey).first<{ id: string }>();
    if (existingOpen) continue;
    const incidentId = crypto.randomUUID();
    const subjectUserId = rule.group_by === "actor_id" ? groupKey : null;
    await db.batch([
      db.prepare(`INSERT INTO security_incidents
        (id,title,severity,status,source_event_id,automated_action,owner,detection_rule_id,group_key,subject_user_id,opened_at,updated_at,closed_at,closed_by,resolution_notes)
        VALUES (?,?,?,'OPEN',?,?,NULL,?,?,?,?,?,NULL,NULL,NULL)`)
        .bind(incidentId, `${rule.code} threshold exceeded for ${groupKey}`, rule.severity, eventId, `AUTO_OPENED_BY_${rule.code}`, rule.id, groupKey, subjectUserId, now, now),
      db.prepare(`INSERT INTO security_playbook_actions (id,incident_id,action_type,actor_id,automated,details,performed_at)
        VALUES (?,?,'DETECTED',NULL,1,?,?)`)
        .bind(crypto.randomUUID(), incidentId, JSON.stringify({ ruleCode: rule.code, groupBy: rule.group_by, groupKey, thresholdCount: rule.threshold_count, windowMinutes: rule.window_minutes }), now),
      db.prepare(`INSERT INTO outbox_events (id,aggregate_type,aggregate_id,event_type,event_version,partition_key,payload,status,publish_attempts,occurred_at,available_at,published_at,last_error)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)`)
        .bind(crypto.randomUUID(), "SECURITY_INCIDENT", incidentId, "SecurityIncidentDetected", 1, groupKey, JSON.stringify({ incident_id: incidentId, rule_code: rule.code, severity: rule.severity }), "PENDING", 0, now, now, null, null),
    ]);
  }
}

export function emitStructuredSecurityLog(input: {
  level: "INFO" | "WARN" | "ERROR";
  event: string;
  correlationId: string;
  actorId?: string;
  outcome: string;
  durationMs: number;
}): void {
  console.log(JSON.stringify({
    timestamp: new Date().toISOString(),
    service: "vat-msa-web",
    ...input,
  }));
}
