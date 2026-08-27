import { ensureDatabase } from "@/db/runtime";
import { appendAuditEvent } from "@/lib/data/audit-repository";
import { RepositoryConflictError } from "@/lib/data/repository";
import { IdentityValidationError } from "@/lib/domain/identity";
import { generateTotpSecret, totpAuthUri, validateTotpCode, verifyTotpCode } from "@/lib/domain/mfa";
import type { UserContext } from "@/lib/domain/types";

/**
 * Security fix 2026-08-27 (SECURITY_GAP_ASSESSMENT.md item #2): a real,
 * server-verified step-up mechanism, replacing the previous
 * x-vat-msa-auth-assurance/x-vat-msa-reauthenticated-at request headers
 * that lib/security/step-up.ts's requireStepUp trusted verbatim from the
 * caller with zero server-side backing — no application code anywhere
 * ever set those headers on a genuine step-up event; only test fixtures
 * did. Enrolment (EnrollTotp/VerifyTotpEnrollment) and step-up
 * confirmation (ConfirmStepUp) are self-service — every actor manages
 * their own MFA credential, so no additional permission gate beyond being
 * authenticated is appropriate here, matching resolveTaxpayer-style
 * self-scoped commands elsewhere in this codebase. Deliberately no
 * idempotency-key mechanism: a TOTP code's own anti-replay check
 * (last_used_counter) already makes a retried request with the same code
 * fail exactly as it should — a genuine retry requires a fresh code, and
 * an idempotency key would work against that, not with it.
 */

const STEP_UP_WINDOW_MS = 5 * 60_000;

async function appendAudit(db: D1Database, actor: UserContext, action: string, resourceType: string, resourceId: string, details: Record<string, unknown>, now: string) {
  return appendAuditEvent(db, actor, action, resourceType, resourceId, details, now);
}

function outboxEvent(db: D1Database, aggregateType: string, aggregateId: string, eventType: string, partitionKey: string, payload: Record<string, unknown>) {
  const now = new Date().toISOString();
  return db.prepare(`INSERT INTO outbox_events
    (id,aggregate_type,aggregate_id,event_type,event_version,partition_key,payload,status,publish_attempts,occurred_at,available_at,published_at,last_error)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(crypto.randomUUID(), aggregateType, aggregateId, eventType, 1, partitionKey, JSON.stringify(payload), "PENDING", 0, now, now, null, null);
}

type TotpCredentialRow = { user_id: string; secret_base32: string; status: string; last_used_counter: number | null };

/** EnrollTotp: (re)starts enrolment with a fresh secret. Refuses to overwrite an already-ACTIVE credential — a caller must go through a separate reset/disable path first (not yet built; today an ACTIVE credential is permanent, which is the safer default). */
export async function enrollTotp(actor: UserContext, correlationId: string): Promise<{ secret: string; otpauthUri: string }> {
  const db = await ensureDatabase();
  const existing = await db.prepare("SELECT status FROM mfa_totp_credentials WHERE user_id=?").bind(actor.userId).first<{ status: string }>();
  if (existing?.status === "ACTIVE") throw new RepositoryConflictError("MFA is already enrolled for this account.");
  const secret = generateTotpSecret();
  const now = new Date().toISOString();
  await db.batch([
    db.prepare(`INSERT INTO mfa_totp_credentials (user_id,secret_base32,status,last_used_counter,created_at,verified_at)
      VALUES (?,?,'PENDING_VERIFICATION',NULL,?,NULL)
      ON CONFLICT(user_id) DO UPDATE SET secret_base32=excluded.secret_base32, status='PENDING_VERIFICATION', last_used_counter=NULL, created_at=excluded.created_at, verified_at=NULL`)
      .bind(actor.userId, secret, now),
    outboxEvent(db, "MFA_CREDENTIAL", actor.userId, "MfaTotpEnrollmentStarted", actor.userId, { userId: actor.userId, correlationId }),
    await appendAudit(db, actor, "MFA_TOTP_ENROLLMENT_STARTED", "MFA_CREDENTIAL", actor.userId, { correlationId }, now),
  ]);
  return { secret, otpauthUri: totpAuthUri(secret, actor.email) };
}

/** VerifyTotpEnrollment: proves the actor's authenticator app genuinely holds the enrolled secret before it becomes usable for step-up. */
export async function verifyTotpEnrollment(actor: UserContext, payload: unknown, correlationId: string): Promise<{ status: string }> {
  const code = validateTotpCode(payload);
  const db = await ensureDatabase();
  const credential = await db.prepare("SELECT user_id,secret_base32,status,last_used_counter FROM mfa_totp_credentials WHERE user_id=?").bind(actor.userId).first<TotpCredentialRow>();
  if (!credential) throw new IdentityValidationError([{ code: "MFA_NOT_ENROLLED", path: "/", message: "No MFA enrolment is in progress for this account." }]);
  if (credential.status === "ACTIVE") throw new RepositoryConflictError("MFA is already active for this account.");
  const matchedCounter = await verifyTotpCode(credential.secret_base32, code);
  if (matchedCounter === null) throw new IdentityValidationError([{ code: "CODE_INCORRECT", path: "/code", message: "The verification code is incorrect or has expired." }]);
  const now = new Date().toISOString();
  await db.batch([
    db.prepare("UPDATE mfa_totp_credentials SET status='ACTIVE', last_used_counter=?, verified_at=? WHERE user_id=?").bind(matchedCounter, now, actor.userId),
    outboxEvent(db, "MFA_CREDENTIAL", actor.userId, "MfaTotpEnrolled", actor.userId, { userId: actor.userId, correlationId }),
    await appendAudit(db, actor, "MFA_TOTP_ENROLLED", "MFA_CREDENTIAL", actor.userId, { correlationId }, now),
  ]);
  return { status: "ACTIVE" };
}

/**
 * ConfirmStepUp: the actual replacement for the old client-asserted
 * header. Writes a real step_up_events row that requireStepUp now checks
 * server-side. matchedCounter must exceed the credential's own
 * last_used_counter — the standard TOTP anti-replay rule — so the exact
 * same code (or an earlier one, e.g. from a captured request) can never
 * confirm step-up twice.
 */
export async function confirmStepUp(actor: UserContext, payload: unknown, correlationId: string): Promise<{ method: string; expiresAt: string }> {
  const code = validateTotpCode(payload);
  const db = await ensureDatabase();
  const credential = await db.prepare("SELECT user_id,secret_base32,status,last_used_counter FROM mfa_totp_credentials WHERE user_id=?").bind(actor.userId).first<TotpCredentialRow>();
  if (!credential || credential.status !== "ACTIVE") {
    throw new IdentityValidationError([{ code: "MFA_NOT_ACTIVE", path: "/", message: "Multi-factor authentication is not enrolled for this account; step-up cannot be confirmed." }]);
  }
  const matchedCounter = await verifyTotpCode(credential.secret_base32, code);
  if (matchedCounter === null || (credential.last_used_counter !== null && matchedCounter <= credential.last_used_counter)) {
    throw new IdentityValidationError([{ code: "CODE_INCORRECT", path: "/code", message: "The verification code is incorrect, expired, or has already been used." }]);
  }
  const now = new Date();
  const nowIso = now.toISOString();
  const expiresAt = new Date(now.getTime() + STEP_UP_WINDOW_MS).toISOString();
  const id = crypto.randomUUID();
  await db.batch([
    db.prepare("UPDATE mfa_totp_credentials SET last_used_counter=? WHERE user_id=?").bind(matchedCounter, actor.userId),
    db.prepare("INSERT INTO step_up_events (id,user_id,method,verified_at,expires_at) VALUES (?,?,?,?,?)").bind(id, actor.userId, "TOTP", nowIso, expiresAt),
    outboxEvent(db, "STEP_UP", id, "StepUpConfirmed", actor.userId, { userId: actor.userId, expiresAt, correlationId }),
    await appendAudit(db, actor, "STEP_UP_CONFIRMED", "STEP_UP_EVENT", id, { expiresAt, correlationId }, nowIso),
  ]);
  return { method: "TOTP", expiresAt };
}

/** The one function lib/security/step-up.ts's requireStepUp actually needs — a fast, read-only freshness check. */
export async function hasFreshStepUp(userId: string): Promise<boolean> {
  const db = await ensureDatabase();
  const now = new Date().toISOString();
  const row = await db.prepare("SELECT 1 AS found FROM step_up_events WHERE user_id=? AND expires_at>? LIMIT 1").bind(userId, now).first<{ found: number }>();
  return row != null;
}

/** GetAssurance's read of the caller's own MFA/step-up posture. */
export async function getMfaStatus(userId: string): Promise<{ enrolled: boolean; hasRecentStepUp: boolean }> {
  const db = await ensureDatabase();
  const [credential, freshStepUp] = await Promise.all([
    db.prepare("SELECT status FROM mfa_totp_credentials WHERE user_id=?").bind(userId).first<{ status: string }>(),
    hasFreshStepUp(userId),
  ]);
  return { enrolled: credential?.status === "ACTIVE", hasRecentStepUp: freshStepUp };
}
