import { ensureDatabase } from "@/db/runtime";
import { sha256Hex, stableStringify } from "@/lib/domain/invoice";
import type { UserContext } from "@/lib/domain/types";
import { recordSecurityEvent, type RequestContext } from "@/lib/security/request";

export class AuditResourceError extends Error {
  readonly status: number;
  constructor(message: string, status = 422) { super(message); this.name = "AuditResourceError"; this.status = status; }
}

/**
 * Module 8 Phase D: the single, canonical hash-chained audit-event writer.
 * A 2026-08-26 audit found nine separate, independently hand-rolled copies
 * of this exact logic across eight repository files (`appendAudit` ×4,
 * `auditEnvelope`/`auditRecord` ×5, plus one hand-inlined duplicate in
 * `identity-repository.ts` that didn't even call its own file's local
 * helper) — real evidence-grade coverage (117+ call sites), but exactly
 * the kind of copy-pasted drift this module's own Phase C watch-outs
 * section already warned about for Workflow. All nine were verified
 * byte-for-byte identical in the one property that actually matters — the
 * hash-chain formula itself (`previous_hash|id|actor_id|body|occurred_at`,
 * genesis fallback `"GENESIS"`, `outcome` always `"SUCCESS"`) — so
 * consolidating them changes no historical row and breaks no existing
 * chain. The only real divergence was cosmetic: four files serialized
 * `details` with `stableStringify` (canonical, sorted-key JSON) and five
 * (plus the inline duplicate) used plain `JSON.stringify` (insertion-order
 * dependent). This function standardises on `stableStringify` going
 * forward — every new row's `details` text becomes deterministic
 * regardless of caller, and since chain verification re-hashes whatever
 * `details` text a row actually stored rather than re-deriving it from an
 * object, this is not a compatibility break for any existing row.
 *
 * `business-repository.ts`'s own two-stage `auditEnvelope`(data)+
 * `auditRecord`(statement) pair is deliberately NOT migrated onto this —
 * it has 20+ call sites all built around that specific two-stage shape,
 * and forcing it to match would mean touching every one of them for zero
 * behavioural gain. That remains a real, acknowledged exception, not a
 * silently-missed file.
 *
 * Always returns an unexecuted `D1PreparedStatement` — some existing call
 * sites push it into a `db.batch([...])` alongside other rows in the same
 * command, others `.run()` it standalone for an audit-only write. Never
 * call this twice against the same still-open batch for one command: the
 * "previous hash" lookup only sees already-committed rows, so two calls
 * in one uncommitted batch would both read the same prior hash and break
 * the chain's linearity (a genuine, pre-existing constraint of this
 * append-only design, documented at the one call site — Module 3
 * Phase D's `transitionAuditCase` — that had already discovered it).
 */
export async function appendAuditEvent(db: D1Database, actor: UserContext, action: string, resourceType: string, resourceId: string, details: Record<string, unknown>, now: string): Promise<D1PreparedStatement> {
  const id = crypto.randomUUID();
  const prior = await db.prepare("SELECT event_hash FROM audit_events ORDER BY occurred_at DESC LIMIT 1").first<{ event_hash: string }>();
  const body = stableStringify(details);
  const hash = await sha256Hex(`${prior?.event_hash ?? "GENESIS"}|${id}|${actor.userId}|${body}|${now}`);
  return db.prepare("INSERT INTO audit_events VALUES (?,?,?,?,?,?,?,?,?,?,?)").bind(id, actor.userId, actor.role, action, resourceType, resourceId, "SUCCESS", body, prior?.event_hash ?? null, hash, now);
}

export type AuditTrailFilter = { resourceType?: string; resourceId?: string; action?: string; actorId?: string; limit?: number; offset?: number };

/** Module 8 Phase D GetAuditTrail: a filterable, paginated, restricted read — the API counterpart to the existing app/audit/page.tsx (which keeps using its own simpler listAuditEvents). */
export async function searchAuditTrail(filter: AuditTrailFilter) {
  const db = await ensureDatabase();
  const conditions: string[] = [];
  const params: (string)[] = [];
  if (filter.resourceType) { conditions.push("resource_type=?"); params.push(filter.resourceType); }
  if (filter.resourceId) { conditions.push("resource_id=?"); params.push(filter.resourceId); }
  if (filter.action) { conditions.push("action=?"); params.push(filter.action); }
  if (filter.actorId) { conditions.push("actor_id=?"); params.push(filter.actorId); }
  const where = conditions.length ? `WHERE ${conditions.join(" AND ")}` : "";
  const limit = Math.min(Math.max(filter.limit ?? 50, 1), 200);
  const offset = Math.max(filter.offset ?? 0, 0);
  const [items, totalRow] = await Promise.all([
    db.prepare(`SELECT * FROM audit_events ${where} ORDER BY occurred_at DESC LIMIT ? OFFSET ?`).bind(...params, limit, offset).all<Record<string, unknown>>(),
    db.prepare(`SELECT COUNT(*) AS count FROM audit_events ${where}`).bind(...params).first<{ count: number }>(),
  ]);
  return { items: items.results, totalCount: Number(totalRow?.count ?? 0), limit, offset };
}

export type ChainVerificationResult = { valid: boolean; verifiedCount: number; firstBreakId: string | null; firstBreakReason: string | null };

/**
 * Module 8 Phase D: re-derives every row's event_hash in occurred_at order
 * and confirms both the previous_hash linkage and the hash itself still
 * match what the row claims — a genuine tamper/corruption check, not a
 * simulated one. Pure read, no writes; `runAuditChainVerification` below
 * is what persists the result and raises an incident on failure.
 */
export async function verifyAuditChain(db: D1Database): Promise<ChainVerificationResult> {
  const rows = await db.prepare("SELECT id,actor_id,details,previous_hash,event_hash,occurred_at FROM audit_events ORDER BY occurred_at ASC, id ASC")
    .all<{ id: string; actor_id: string; details: string; previous_hash: string | null; event_hash: string; occurred_at: string }>();
  let priorHash: string | null = null;
  let verifiedCount = 0;
  for (const row of rows.results) {
    if ((row.previous_hash ?? null) !== priorHash) return { valid: false, verifiedCount, firstBreakId: row.id, firstBreakReason: "PREVIOUS_HASH_MISMATCH" };
    const expectedHash = await sha256Hex(`${priorHash ?? "GENESIS"}|${row.id}|${row.actor_id}|${row.details}|${row.occurred_at}`);
    if (expectedHash !== row.event_hash) return { valid: false, verifiedCount, firstBreakId: row.id, firstBreakReason: "EVENT_HASH_MISMATCH" };
    priorHash = row.event_hash;
    verifiedCount += 1;
  }
  return { valid: true, verifiedCount, firstBreakId: null, firstBreakReason: null };
}

/**
 * Module 8 Phase D VerifyAuditChain (the "chain-verification job with
 * alerting on breaks" the playbook names). This deployment has no
 * cron/queue infrastructure to run it on a schedule — the same recurring
 * gap Module 3's RunMatch and this module's own Phase A/B/C already had to
 * document — so it is a genuine, on-demand, actor-triggered command
 * instead, with its own result persisted as a real row (not a fire-and-
 * forget console log) so "was the chain last verified, and did it pass"
 * is itself an answerable, auditable question. A failed verification opens
 * a CRITICAL security incident through Module 8 Phase B's own detection
 * pipeline (`AUDIT_CHAIN_INTEGRITY_BREACH`, threshold 1 — even a single
 * break is worth an incident) — real "alerting," reusing infrastructure
 * this module already built rather than inventing a second one.
 */
export async function runAuditChainVerification(actor: UserContext, correlationId: string) {
  const db = await ensureDatabase();
  const startedAt = new Date().toISOString();
  const result = await verifyAuditChain(db);
  const completedAt = new Date().toISOString();
  const id = crypto.randomUUID();
  await db.prepare(`INSERT INTO audit_chain_verifications
    (id,requested_by,status,verified_count,first_break_id,first_break_reason,started_at,completed_at)
    VALUES (?,?,?,?,?,?,?,?)`).bind(id, actor.userId, result.valid ? "PASSED" : "FAILED", result.verifiedCount, result.firstBreakId, result.firstBreakReason, startedAt, completedAt).run();
  if (!result.valid) {
    const context: RequestContext = { correlationId, sourceToken: "sha256:audit-chain-verification", deviceId: "system" };
    await recordSecurityEvent({
      eventType: "AUDIT_CHAIN_BREAK", severity: "CRITICAL", actorId: actor.userId, context,
      action: "VERIFY_AUDIT_CHAIN", outcome: "FAILED",
      details: { verificationId: id, verifiedCount: result.verifiedCount, firstBreakId: result.firstBreakId ?? "", firstBreakReason: result.firstBreakReason ?? "" },
    }).catch(() => undefined);
  }
  return { id, status: result.valid ? "PASSED" : "FAILED", verifiedCount: result.verifiedCount, firstBreakId: result.firstBreakId, firstBreakReason: result.firstBreakReason, startedAt, completedAt };
}

export async function listAuditChainVerifications(limit = 50) {
  const db = await ensureDatabase();
  const bounded = Math.min(Math.max(limit, 1), 200);
  const rows = await db.prepare("SELECT * FROM audit_chain_verifications ORDER BY started_at DESC LIMIT ?").bind(bounded).all<Record<string, unknown>>();
  return rows.results;
}
