import { ensureDatabase } from "@/db/runtime";
import { appendAuditEvent } from "@/lib/data/audit-repository";
import { sha256Hex, stableStringify } from "@/lib/domain/invoice";
import type { TaxCategory, UserContext } from "@/lib/domain/types";
import {
  normalizeVatRuleApproval,
  normalizeVatRuleEvaluationQuery,
  normalizeVatRuleProposal,
  VatRuleValidationError,
} from "@/lib/domain/vat-rules";
import { RepositoryConflictError } from "./repository";

const COUNTRY = "NA";

/** Module 8 Phase D: delegates to the single shared hash-chain writer — see lib/data/audit-repository.ts's appendAuditEvent. */
async function appendAudit(db: D1Database, actor: UserContext, action: string, resourceType: string, resourceId: string, details: Record<string, unknown>) {
  return appendAuditEvent(db, actor, action, resourceType, resourceId, details, new Date().toISOString());
}

type PriorCommand = { request_hash: string; resource_id: string };

/**
 * Security fix 2026-08-27 (SECURITY_GAP_ASSESSMENT.md item #8): this
 * repository had zero command_idempotency references — contradicting the
 * pattern every other repository in this codebase already establishes.
 * Same local validateIdempotencyKey/replay/commandRecord triple as
 * everywhere else.
 */
function validateIdempotencyKey(key: string) {
  if (key.length < 16 || key.length > 128) throw new VatRuleValidationError([{ code: "IDEMPOTENCY_KEY_INVALID", path: "/headers/idempotency-key", message: "Idempotency-Key must contain 16 to 128 characters." }]);
}

async function replay(db: D1Database, actorId: string, command: string, key: string, hash: string) {
  const prior = await db.prepare("SELECT request_hash,resource_id FROM command_idempotency WHERE actor_id=? AND command_type=? AND idempotency_key=?").bind(actorId, command, key).first<PriorCommand>();
  if (!prior) return null;
  if (prior.request_hash !== hash) throw new RepositoryConflictError("The idempotency key was already used for a different VAT rule command.");
  return prior.resource_id;
}

function commandRecord(db: D1Database, actorId: string, command: string, key: string, hash: string, resourceType: string, resourceId: string, now: string) {
  return db.prepare("INSERT INTO command_idempotency VALUES (?,?,?,?,?,?,?,?)").bind(crypto.randomUUID(), actorId, command, key, hash, resourceType, resourceId, now);
}

function outboxEvent(db: D1Database, aggregateType: string, aggregateId: string, eventType: string, partitionKey: string, payload: Record<string, unknown>) {
  const now = new Date().toISOString();
  return db.prepare(`INSERT INTO outbox_events
    (id,aggregate_type,aggregate_id,event_type,event_version,partition_key,payload,status,publish_attempts,occurred_at,available_at,published_at,last_error)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(crypto.randomUUID(), aggregateType, aggregateId, eventType, 1, partitionKey, JSON.stringify(payload), "PENDING", 0, now, now, null, null);
}

export type VatRuleSummary = {
  id: string;
  taxCategory: TaxCategory;
  country: string;
  rateBps: number;
  status: string;
  version: number;
  effectiveFrom: string;
  effectiveTo: string | null;
  proposedBy: string;
  proposedAt: string;
  approvedBy: string | null;
  approvedAt: string | null;
  proposalReason: string;
  supersededBy: string | null;
};

type VatRuleRow = {
  id: string; tax_category: TaxCategory; country: string; rate_bps: number; status: string; version: number;
  effective_from: string; effective_to: string | null; proposed_by: string; proposed_at: string;
  approved_by: string | null; approved_at: string | null; proposal_reason: string; superseded_by: string | null;
};

function mapRule(row: VatRuleRow): VatRuleSummary {
  return {
    id: row.id, taxCategory: row.tax_category, country: row.country, rateBps: row.rate_bps, status: row.status,
    version: row.version, effectiveFrom: row.effective_from, effectiveTo: row.effective_to, proposedBy: row.proposed_by,
    proposedAt: row.proposed_at, approvedBy: row.approved_by, approvedAt: row.approved_at,
    proposalReason: row.proposal_reason, supersededBy: row.superseded_by,
  };
}

export async function listVatRules(): Promise<VatRuleSummary[]> {
  const db = await ensureDatabase();
  const result = await db.prepare("SELECT * FROM vat_rules ORDER BY tax_category, version DESC").all<VatRuleRow>();
  return result.results.map(mapRule);
}

/**
 * Module 2 Phase A ProposeVatRule. A DRAFT until a different officer
 * approves it (approveVatRule below). version is the next integer for this
 * (tax_category, country) lineage, computed from the current max — not
 * user-supplied, so a proposal can never collide with or skip an existing
 * version.
 */
export async function proposeVatRule(actor: UserContext, input: unknown, idempotencyKey: string, correlationId: string): Promise<VatRuleSummary> {
  validateIdempotencyKey(idempotencyKey);
  const proposal = normalizeVatRuleProposal(input);
  const db = await ensureDatabase();
  const hash = await sha256Hex(stableStringify(proposal));
  const prior = await replay(db, actor.userId, "PROPOSE_VAT_RULE", idempotencyKey, hash);
  if (prior) return mapRule((await db.prepare("SELECT * FROM vat_rules WHERE id=?").bind(prior).first<VatRuleRow>())!);

  const current = await db.prepare("SELECT MAX(version) AS version FROM vat_rules WHERE tax_category=? AND country=?")
    .bind(proposal.taxCategory, COUNTRY).first<{ version: number | null }>();
  const version = (current?.version ?? 0) + 1;
  const now = new Date().toISOString();
  const id = crypto.randomUUID();
  await db.batch([
    db.prepare(`INSERT INTO vat_rules (id,tax_category,country,rate_bps,status,version,effective_from,effective_to,proposed_by,proposed_at,approved_by,approved_at,approval_reason,proposal_reason,superseded_by)
      VALUES (?,?,?,?,?,?,?,NULL,?,?,NULL,NULL,NULL,?,NULL)`)
      .bind(id, proposal.taxCategory, COUNTRY, proposal.rateBps, "DRAFT", version, proposal.effectiveFrom, actor.userId, now, proposal.reason),
    commandRecord(db, actor.userId, "PROPOSE_VAT_RULE", idempotencyKey, hash, "VAT_RULE", id, now),
    outboxEvent(db, "VAT_RULE", id, "VatRuleProposed", proposal.taxCategory, { ruleId: id, taxCategory: proposal.taxCategory, rateBps: proposal.rateBps, version, correlationId }),
    await appendAudit(db, actor, "VAT_RULE_PROPOSED", "VAT_RULE", id, { taxCategory: proposal.taxCategory, rateBps: proposal.rateBps, version, effectiveFrom: proposal.effectiveFrom }),
  ]);
  return {
    id, taxCategory: proposal.taxCategory, country: COUNTRY, rateBps: proposal.rateBps, status: "DRAFT", version,
    effectiveFrom: proposal.effectiveFrom, effectiveTo: null, proposedBy: actor.userId, proposedAt: now,
    approvedBy: null, approvedAt: null, proposalReason: proposal.reason, supersededBy: null,
  };
}

/**
 * Module 2 Phase A ApproveVatRule. Self-approval denied (the proposing
 * officer cannot also approve — segregation of duties, the same pattern
 * used for registration decisions and access requests). On approval,
 * retires whichever rule currently governs this (tax_category, country) with
 * an open-ended effective_to, closing it exactly where the new rule begins
 * so the two ranges never overlap and EvaluateVAT never has two candidates.
 */
export async function approveVatRule(actor: UserContext, ruleId: string, input: unknown, idempotencyKey: string, correlationId: string): Promise<VatRuleSummary> {
  validateIdempotencyKey(idempotencyKey);
  const approval = normalizeVatRuleApproval(input);
  const db = await ensureDatabase();
  const rule = await db.prepare("SELECT * FROM vat_rules WHERE id=?").bind(ruleId).first<VatRuleRow>();
  if (!rule) throw new VatRuleValidationError([{ code: "RULE_NOT_FOUND", path: "/rule_id", message: "The VAT rule proposal does not exist." }]);

  const hash = await sha256Hex(stableStringify({ ruleId, approval }));
  const prior = await replay(db, actor.userId, "APPROVE_VAT_RULE", idempotencyKey, hash);
  if (prior) return mapRule((await db.prepare("SELECT * FROM vat_rules WHERE id=?").bind(prior).first<VatRuleRow>())!);

  if (rule.status !== "DRAFT") throw new RepositoryConflictError(`This VAT rule is already ${rule.status}.`);
  if (actor.userId === rule.proposed_by) {
    throw new VatRuleValidationError([{ code: "SELF_APPROVAL_DENIED", path: "/actor", message: "The proposing officer cannot approve their own VAT rule." }]);
  }

  const superseding = await db.prepare(`SELECT id,effective_from FROM vat_rules
    WHERE tax_category=? AND country=? AND status='APPROVED' AND effective_to IS NULL`)
    .bind(rule.tax_category, rule.country).first<{ id: string; effective_from: string }>();
  if (superseding && rule.effective_from <= superseding.effective_from) {
    throw new VatRuleValidationError([{ code: "EFFECTIVE_FROM_NOT_FORWARD", path: "/effective_from", message: `This rule must take effect after the currently approved rule's effective date (${superseding.effective_from}).` }]);
  }

  const now = new Date().toISOString();
  const statements = [
    db.prepare("UPDATE vat_rules SET status='APPROVED',approved_by=?,approved_at=?,approval_reason=? WHERE id=?")
      .bind(actor.userId, now, approval.reason, rule.id),
    commandRecord(db, actor.userId, "APPROVE_VAT_RULE", idempotencyKey, hash, "VAT_RULE", rule.id, now),
    outboxEvent(db, "VAT_RULE", rule.id, "VatRuleApproved", rule.tax_category, { ruleId: rule.id, taxCategory: rule.tax_category, rateBps: rule.rate_bps, version: rule.version, correlationId }),
    await appendAudit(db, actor, "VAT_RULE_APPROVED", "VAT_RULE", rule.id, { taxCategory: rule.tax_category, rateBps: rule.rate_bps, version: rule.version, reason: approval.reason }),
  ];
  if (superseding) {
    statements.push(
      db.prepare("UPDATE vat_rules SET effective_to=?,superseded_by=? WHERE id=?").bind(rule.effective_from, rule.id, superseding.id),
    );
  }
  await db.batch(statements);
  return mapRule({ ...rule, status: "APPROVED", approved_by: actor.userId, approved_at: now, approval_reason: approval.reason } as VatRuleRow);
}

export type ApplicableVatRule = { id: string; rateBps: number; version: number; effectiveFrom: string; effectiveTo: string | null };

/**
 * EvaluateVAT's core resolution: the single approved rule governing this
 * category as of this date, or null if none is bound. Callers (invoice
 * submission, the standalone evaluate route) must fail closed on null —
 * never assume a default rate.
 */
export async function getApplicableVatRule(db: D1Database, taxCategory: TaxCategory, isoDate: string): Promise<ApplicableVatRule | null> {
  const row = await db.prepare(`SELECT id,rate_bps,version,effective_from,effective_to FROM vat_rules
    WHERE tax_category=? AND country=? AND status='APPROVED' AND effective_from<=? AND (effective_to IS NULL OR effective_to>?)
    ORDER BY effective_from DESC LIMIT 1`)
    .bind(taxCategory, COUNTRY, isoDate, isoDate)
    .first<{ id: string; rate_bps: number; version: number; effective_from: string; effective_to: string | null }>();
  if (!row) return null;
  return { id: row.id, rateBps: row.rate_bps, version: row.version, effectiveFrom: row.effective_from, effectiveTo: row.effective_to };
}

export type VatRuleEvaluationResult = { taxCategory: TaxCategory; effectiveDate: string; rule: ApplicableVatRule };

/** Module 2 Phase A EvaluateVAT, as a standalone dry-run query — lets an ERP integrator preview the applicable rate before building an invoice. */
export async function evaluateVatRule(taxCategoryInput: unknown, dateInput: unknown): Promise<VatRuleEvaluationResult> {
  const { taxCategory, effectiveDate } = normalizeVatRuleEvaluationQuery(taxCategoryInput, dateInput);
  const db = await ensureDatabase();
  const rule = await getApplicableVatRule(db, taxCategory, effectiveDate);
  if (!rule) {
    throw new VatRuleValidationError([{ code: "NO_APPROVED_VAT_RULE", path: "/tax_category", message: `No approved VAT rule is bound for ${taxCategory} on ${effectiveDate}.` }]);
  }
  return { taxCategory, effectiveDate, rule };
}
