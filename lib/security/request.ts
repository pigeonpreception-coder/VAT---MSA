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

export async function enforceSelfServeSignupSourceRateLimits(context: RequestContext): Promise<void> {
  await enforceRateLimits([
    { key: `signup:source:${context.sourceToken}`, limit: 5, windowSeconds: 3_600 },
    { key: "signup:global", limit: 500, windowSeconds: 3_600 },
  ]);
}

export async function enforceSelfServeSignupEmailRateLimit(email: string): Promise<void> {
  const emailToken = (await sha256Hex(email.trim().toLowerCase())).slice(0, 32);
  await enforceRateLimits([
    { key: `signup:email:${emailToken}`, limit: 3, windowSeconds: 86_400 },
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
  await db.prepare("INSERT INTO security_events VALUES (?,?,?,?,?,?,?,?,?,?)").bind(
    crypto.randomUUID(), input.eventType, input.severity, input.actorId ?? null,
    input.context.sourceToken, input.context.correlationId, input.action, input.outcome,
    JSON.stringify(input.details), new Date().toISOString(),
  ).run();
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
