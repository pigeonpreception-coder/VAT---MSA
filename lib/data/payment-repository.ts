import { ensureDatabase } from "@/db/runtime";
import { AccessDeniedError, isNationalScope } from "@/lib/auth";
import { appendAuditEvent } from "@/lib/data/audit-repository";
import { maskBeneficiaryReference, validateAllocatePaymentInput, validateRecordPaymentInput } from "@/lib/domain/payment";
import { sha256Hex, stableStringify } from "@/lib/domain/invoice";
import type { UserContext } from "@/lib/domain/types";
import { getPaymentConnectorPort, PaymentIntegrationUnavailableError } from "@/lib/integrations/payment";
import { RepositoryConflictError } from "./repository";

type PriorCommand = { request_hash: string; resource_id: string };

export class PaymentResourceError extends Error {
  readonly status: number;

  constructor(message: string, status = 422) {
    super(message);
    this.name = "PaymentResourceError";
    this.status = status;
  }
}

function validateKey(key: string) {
  if (key.length < 16 || key.length > 128) throw new PaymentResourceError("Idempotency-Key must contain 16 to 128 characters.");
}

async function replay(db: D1Database, actorId: string, command: string, key: string, hash: string) {
  const prior = await db.prepare("SELECT request_hash,resource_id FROM command_idempotency WHERE actor_id=? AND command_type=? AND idempotency_key=?").bind(actorId, command, key).first<PriorCommand>();
  if (!prior) return null;
  if (prior.request_hash !== hash) throw new RepositoryConflictError("The idempotency key was already used for a different payment command.");
  return prior.resource_id;
}

function commandRecord(db: D1Database, actorId: string, command: string, key: string, hash: string, resourceType: string, resourceId: string, now: string) {
  return db.prepare("INSERT INTO command_idempotency VALUES (?,?,?,?,?,?,?,?)").bind(crypto.randomUUID(), actorId, command, key, hash, resourceType, resourceId, now);
}

/** Delegates to the single shared hash-chain writer — see lib/data/audit-repository.ts's appendAuditEvent. */
async function auditRecord(db: D1Database, actor: UserContext, action: string, resourceType: string, resourceId: string, details: Record<string, unknown>, now: string) {
  return appendAuditEvent(db, actor, action, resourceType, resourceId, details, now);
}

function outbox(db: D1Database, aggregateType: string, aggregateId: string, eventType: string, taxpayerId: string, payload: Record<string, unknown>, now: string) {
  return db.prepare(`INSERT INTO outbox_events
    (id,aggregate_type,aggregate_id,event_type,event_version,partition_key,payload,status,publish_attempts,occurred_at,available_at,published_at,last_error)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(crypto.randomUUID(), aggregateType, aggregateId, eventType, 1, taxpayerId, JSON.stringify(payload), "PENDING", 0, now, now, null, null);
}

type ClaimForPaymentRow = { id: string; taxpayer_id: string; status: string; net_payable_cents: number | null; amount_cents: number; currency: string; payment_instruction_id: string | null };

/**
 * Module 9 Phase D: RecordPayment. Officer-only, national-scope, operating
 * on a refund claim that has already reached PAYMENT_PENDING — Phase A/C's
 * deliberate terminal boundary for the claim state machine itself. This
 * command never touches refund_claims.status or refund_claim_transitions;
 * it only reads the claim to authorise a payment_instructions row against
 * it. Every call goes through PaymentConnectorPort.recordPayment first
 * (lib/integrations/payment.ts); today that always throws
 * PaymentIntegrationUnavailableError, because component-payment
 * (db/runtime.ts's service_components seed) is DISABLED by design and
 * nothing anywhere in this codebase ever writes to that row — so the
 * payment_instructions INSERT below, gated inside the try block, is
 * provably unreachable in this deployment (see
 * tests/routes/module-9-payment-connector.test.ts). On the unavailable
 * path this still records an honest audit-trail entry (AWAITING_AUTHORITY)
 * rather than silently failing or throwing a bare 5xx, mirroring
 * verifyTaxpayerIdentifiers's identical fail-closed shape for ITAS
 * (lib/data/identity-repository.ts).
 */
export async function recordRefundPayment(claimId: string, payload: unknown, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  if (!isNationalScope(actor)) throw new AccessDeniedError("Only an authorised national refund role may record a refund payment.");
  const input = validateRecordPaymentInput(payload);
  const db = await ensureDatabase();
  const claim = await db.prepare("SELECT id,taxpayer_id,status,net_payable_cents,amount_cents,currency,payment_instruction_id FROM refund_claims WHERE id=?").bind(claimId).first<ClaimForPaymentRow>();
  if (!claim) throw new PaymentResourceError("Refund claim was not found.", 404);
  if (claim.status !== "PAYMENT_PENDING") throw new RepositoryConflictError("A refund payment can only be recorded once the claim reaches PAYMENT_PENDING.");
  if (claim.payment_instruction_id) throw new RepositoryConflictError("A payment has already been recorded for this refund claim.");

  const hash = await sha256Hex(stableStringify({ claim_id: claim.id, input }));
  const prior = await replay(db, actor.userId, "RECORD_REFUND_PAYMENT", key, hash);
  if (prior) return db.prepare("SELECT * FROM payment_instructions WHERE id=?").bind(prior).first<Record<string, unknown>>();

  const amountCents = claim.net_payable_cents ?? claim.amount_cents;
  const beneficiaryMasked = maskBeneficiaryReference(input.beneficiary_reference);
  const now = new Date().toISOString();
  const requestReference = crypto.randomUUID();
  const connector = getPaymentConnectorPort(db);

  try {
    const result = await connector.recordPayment({
      requestReference, refundClaimId: claim.id, taxpayerId: claim.taxpayer_id,
      amountCents, currency: claim.currency, beneficiaryReferenceMasked: beneficiaryMasked,
      provider: input.provider, correlationId,
    });
    const instructionId = crypto.randomUUID();
    await db.batch([
      db.prepare(`INSERT INTO payment_instructions
          (id,refund_claim_id,taxpayer_id,amount_cents,currency,beneficiary_reference_masked,provider,status,provider_reference,idempotency_key,approved_by,approved_at,submitted_at,settled_at,last_error)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(
        instructionId, claim.id, claim.taxpayer_id, amountCents, claim.currency, beneficiaryMasked, input.provider,
        result.status, result.providerReference, requestReference, actor.userId, now, now, null, null,
      ),
      db.prepare("UPDATE refund_claims SET payment_instruction_id=? WHERE id=?").bind(instructionId, claim.id),
      commandRecord(db, actor.userId, "RECORD_REFUND_PAYMENT", key, hash, "PAYMENT_INSTRUCTION", instructionId, now),
      outbox(db, "PAYMENT_INSTRUCTION", instructionId, "PaymentRecorded", claim.taxpayer_id, { refund_claim_id: claim.id, amount_cents: amountCents, provider: input.provider, correlation_id: correlationId }, now),
      await auditRecord(db, actor, "REFUND_PAYMENT_RECORDED", "PAYMENT_INSTRUCTION", instructionId, { refundClaimId: claim.id, amountCents, provider: input.provider, providerReference: result.providerReference, correlationId }, now),
    ]);
    return db.prepare("SELECT * FROM payment_instructions WHERE id=?").bind(instructionId).first<Record<string, unknown>>();
  } catch (error) {
    if (!(error instanceof PaymentIntegrationUnavailableError)) throw error;
    const auditStatement = await auditRecord(db, actor, "REFUND_PAYMENT_ATTEMPTED", "REFUND_CLAIM", claim.id, { amountCents, provider: input.provider, outcome: "AWAITING_AUTHORITY", correlationId }, now);
    await auditStatement.run();
    return { refund_claim_id: claim.id, taxpayer_id: claim.taxpayer_id, amount_cents: amountCents, currency: claim.currency, status: "AWAITING_AUTHORITY", provider: input.provider, provider_reference: null, recorded_at: now };
  }
}

type PaymentInstructionRow = { id: string; refund_claim_id: string | null; taxpayer_id: string; amount_cents: number; currency: string; status: string; provider_reference: string | null };

/**
 * AllocatePayment: matches a settlement confirmation to an existing
 * payment_instructions row and marks it SETTLED. Addressed by refund claim
 * id (mirrors RecordPayment's own route shape) rather than a raw payment
 * instruction id, since the claim is the handle an officer already has
 * open. Gated by the same PaymentConnectorPort.allocatePayment call —
 * unreachable today for the same reason as RecordPayment, and in this
 * deployment doubly unreachable in practice: no payment_instructions row
 * can exist to allocate against in the first place, since RecordPayment
 * itself never gets past its own guard.
 */
export async function allocateRefundPayment(claimId: string, payload: unknown, actor: UserContext, key: string, correlationId: string) {
  validateKey(key);
  if (!isNationalScope(actor)) throw new AccessDeniedError("Only an authorised national refund role may allocate a refund payment.");
  const input = validateAllocatePaymentInput(payload);
  const db = await ensureDatabase();
  const claim = await db.prepare("SELECT id,taxpayer_id,payment_instruction_id FROM refund_claims WHERE id=?").bind(claimId).first<{ id: string; taxpayer_id: string; payment_instruction_id: string | null }>();
  if (!claim) throw new PaymentResourceError("Refund claim was not found.", 404);
  if (!claim.payment_instruction_id) throw new RepositoryConflictError("This refund claim has no recorded payment instruction to allocate against.");
  const instruction = await db.prepare("SELECT id,refund_claim_id,taxpayer_id,amount_cents,currency,status,provider_reference FROM payment_instructions WHERE id=?").bind(claim.payment_instruction_id).first<PaymentInstructionRow>();
  if (!instruction) throw new PaymentResourceError("Payment instruction was not found.", 404);
  if (instruction.status === "SETTLED") throw new RepositoryConflictError("This payment instruction has already been settled.");

  const hash = await sha256Hex(stableStringify({ instruction_id: instruction.id, input }));
  const prior = await replay(db, actor.userId, "ALLOCATE_REFUND_PAYMENT", key, hash);
  if (prior) return db.prepare("SELECT * FROM payment_instructions WHERE id=?").bind(prior).first<Record<string, unknown>>();

  const now = new Date().toISOString();
  const requestReference = crypto.randomUUID();
  const connector = getPaymentConnectorPort(db);

  try {
    const result = await connector.allocatePayment({
      requestReference, paymentInstructionId: instruction.id, settlementReference: input.settlement_reference,
      settledAmountCents: input.settled_amount_cents, correlationId,
    });
    await db.batch([
      db.prepare("UPDATE payment_instructions SET status=?, provider_reference=COALESCE(?,provider_reference), settled_at=? WHERE id=?").bind(result.status, result.settlementReference, now, instruction.id),
      commandRecord(db, actor.userId, "ALLOCATE_REFUND_PAYMENT", key, hash, "PAYMENT_INSTRUCTION", instruction.id, now),
      outbox(db, "PAYMENT_INSTRUCTION", instruction.id, "PaymentAllocated", instruction.taxpayer_id, { payment_instruction_id: instruction.id, settlement_reference: input.settlement_reference, correlation_id: correlationId }, now),
      await auditRecord(db, actor, "REFUND_PAYMENT_ALLOCATED", "PAYMENT_INSTRUCTION", instruction.id, { settlementReference: input.settlement_reference, settledAmountCents: input.settled_amount_cents, correlationId }, now),
    ]);
    return db.prepare("SELECT * FROM payment_instructions WHERE id=?").bind(instruction.id).first<Record<string, unknown>>();
  } catch (error) {
    if (!(error instanceof PaymentIntegrationUnavailableError)) throw error;
    const auditStatement = await auditRecord(db, actor, "REFUND_PAYMENT_ALLOCATION_ATTEMPTED", "PAYMENT_INSTRUCTION", instruction.id, { settlementReference: input.settlement_reference, outcome: "AWAITING_AUTHORITY", correlationId }, now);
    await auditStatement.run();
    return { payment_instruction_id: instruction.id, refund_claim_id: instruction.refund_claim_id, status: "AWAITING_AUTHORITY", settlement_reference: null, allocated_at: now };
  }
}

type OutstandingRefundRow = { id: string; claim_number: string; taxpayer_id: string; amount_cents: number; net_payable_cents: number | null; risk_tier: string; requested_at: string; approved_at: string | null };

/**
 * GetOutstanding: claims that have cleared every review stage
 * (PAYMENT_PENDING) but have no payment_instructions row yet — the honest
 * queue of what NamRA owes once Payment is authorised. Pure read: no
 * connector mutating call and no guard check on the claims query itself
 * (status() is read-only and safe to surface either way) — this never
 * mutates anything, it only reports the claim-side state Phase A/C already
 * built. Restricted to national-scope actors, matching getRestrictedRisk's
 * own precedent for an internal operational queue — no taxpayer-facing
 * "my outstanding refund" view exists yet, so this doesn't invent one.
 */
export async function getOutstandingRefunds(actor: UserContext) {
  if (!isNationalScope(actor)) throw new AccessDeniedError("The outstanding refund payment queue is restricted to national-scope refund roles.");
  const db = await ensureDatabase();
  const connectorStatus = await getPaymentConnectorPort(db).status();
  const rows = await db.prepare("SELECT id,claim_number,taxpayer_id,amount_cents,net_payable_cents,risk_tier,requested_at,approved_at FROM refund_claims WHERE status='PAYMENT_PENDING' AND payment_instruction_id IS NULL ORDER BY approved_at ASC").all<OutstandingRefundRow>();
  const totalOutstandingCents = rows.results.reduce((sum, row) => sum + (row.net_payable_cents ?? row.amount_cents), 0);
  return { claims: rows.results, totalOutstandingCents, connector: connectorStatus };
}
