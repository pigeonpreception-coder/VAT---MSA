import { ensureDatabase } from "@/db/runtime";
import { requireTaxpayerScope } from "@/lib/auth";
import { sha256Hex, stableStringify } from "@/lib/domain/invoice";
import {
  evaluateInvoiceMatch,
  normalizeExceptionAssignment,
  normalizeExceptionResolution,
  ReconciliationValidationError,
} from "@/lib/domain/reconciliation";
import type { UserContext } from "@/lib/domain/types";
import { RepositoryConflictError } from "./repository";

async function appendAudit(db: D1Database, actor: UserContext, action: string, resourceType: string, resourceId: string, details: Record<string, unknown>) {
  const now = new Date().toISOString();
  const id = crypto.randomUUID();
  const prior = await db.prepare("SELECT event_hash FROM audit_events ORDER BY occurred_at DESC LIMIT 1").first<{ event_hash: string }>();
  const body = stableStringify(details);
  const hash = await sha256Hex(`${prior?.event_hash ?? "GENESIS"}|${id}|${actor.userId}|${body}|${now}`);
  return db.prepare("INSERT INTO audit_events VALUES (?,?,?,?,?,?,?,?,?,?,?)").bind(id, actor.userId, actor.role, action, resourceType, resourceId, "SUCCESS", body, prior?.event_hash ?? null, hash, now);
}

function outboxEvent(db: D1Database, aggregateType: string, aggregateId: string, eventType: string, partitionKey: string, payload: Record<string, unknown>) {
  const now = new Date().toISOString();
  return db.prepare(`INSERT INTO outbox_events
    (id,aggregate_type,aggregate_id,event_type,event_version,partition_key,payload,status,publish_attempts,occurred_at,available_at,published_at,last_error)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(crypto.randomUUID(), aggregateType, aggregateId, eventType, 1, partitionKey, JSON.stringify(payload), "PENDING", 0, now, now, null, null);
}

export type MatchSummary = {
  id: string;
  invoiceId: string;
  taxpayerId: string;
  status: string;
  matchType: string;
  confidenceBps: number;
  mismatches: string[];
  reconciledAt: string;
};

type InvoiceForMatch = {
  id: string; status: string; tax_cents: number; supplier_taxpayer_id: string; customer_taxpayer_id: string | null;
  issue_date: string; transaction_id: string;
};

type MatchRow = { id: string; invoice_id: string; taxpayer_id: string; status: string; match_type: string; confidence_bps: number; evidence: string; reconciled_at: string };

function mapMatch(row: MatchRow): MatchSummary {
  const evidence = JSON.parse(row.evidence) as { mismatches?: string[] };
  return {
    id: row.id, invoiceId: row.invoice_id, taxpayerId: row.taxpayer_id, status: row.status,
    matchType: row.match_type, confidenceBps: row.confidence_bps, mismatches: evidence.mismatches ?? [], reconciledAt: row.reconciled_at,
  };
}

/**
 * Module 3 Phase A RunMatch: an independent verification pass for one
 * invoice, re-deriving what its ledger postings *should* be from the
 * invoice's own declared figures and status, and comparing against what was
 * actually posted (see evaluateInvoiceMatch in lib/domain/reconciliation.ts
 * for the exact checks). Deliberately invoice-scoped rather than a
 * period-wide sweep or a literal scheduled job — this Workers deployment
 * has no cron/queue infrastructure wired up yet, so "scheduled/event-driven"
 * is left as a documented gap rather than faked; this is the correct
 * per-invoice building block such a job would call. Idempotent: a retry
 * against an already-matched invoice returns the existing match rather than
 * evaluating again, backed by reconciliation_matches' own
 * UNIQUE(invoice_id, taxpayer_id) constraint.
 */
export async function runMatch(actor: UserContext, invoiceId: string, correlationId: string): Promise<MatchSummary> {
  const db = await ensureDatabase();
  const invoice = await db.prepare(`SELECT id, status, tax_cents, supplier_taxpayer_id, customer_taxpayer_id, issue_date, transaction_id
    FROM invoices WHERE id = ?`).bind(invoiceId).first<InvoiceForMatch>();
  if (!invoice) throw new ReconciliationValidationError([{ code: "INVOICE_NOT_FOUND", path: "/invoice_id", message: "The invoice does not exist." }]);
  requireTaxpayerScope(actor, invoice.supplier_taxpayer_id);

  const existing = await db.prepare("SELECT * FROM reconciliation_matches WHERE invoice_id=? AND taxpayer_id=?")
    .bind(invoice.id, invoice.supplier_taxpayer_id).first<MatchRow>();
  if (existing) return mapMatch(existing);

  const [ownOutput, ownInput, cancellationOutput] = await Promise.all([
    db.prepare("SELECT amount_cents FROM ledger_entries WHERE transaction_id=? AND entry_type='OUTPUT_VAT'").bind(invoice.transaction_id).first<{ amount_cents: number }>(),
    db.prepare("SELECT amount_cents FROM ledger_entries WHERE transaction_id=? AND entry_type='INPUT_VAT'").bind(invoice.transaction_id).first<{ amount_cents: number }>(),
    invoice.status === "CANCELLED"
      ? db.prepare(`SELECT l.amount_cents FROM vat_transactions t JOIN ledger_entries l ON l.transaction_id=t.id
          WHERE t.reference_transaction_id=? AND t.transaction_type='CANCELLATION' AND l.entry_type='OUTPUT_VAT'`).bind(invoice.transaction_id).first<{ amount_cents: number }>()
      : Promise.resolve(null),
  ]);

  const result = evaluateInvoiceMatch({
    invoiceTaxCents: invoice.tax_cents,
    outputVatLedgerCents: ownOutput?.amount_cents ?? null,
    hasIdentifiedBuyer: Boolean(invoice.customer_taxpayer_id),
    inputVatLedgerCents: ownInput?.amount_cents ?? null,
    isCancelled: invoice.status === "CANCELLED",
    cancellationOutputVatLedgerCents: cancellationOutput?.amount_cents ?? null,
  });

  const organisation = await db.prepare("SELECT id FROM organisations WHERE taxpayer_id=?").bind(invoice.supplier_taxpayer_id).first<{ id: string }>();
  if (!organisation) throw new ReconciliationValidationError([{ code: "ORGANISATION_NOT_FOUND", path: "/", message: "The supplier's organisation could not be resolved." }]);
  const period = invoice.issue_date.slice(0, 7);
  const vatPeriod = await db.prepare("SELECT id FROM vat_periods WHERE taxpayer_id=? AND period_code=?").bind(invoice.supplier_taxpayer_id, period).first<{ id: string }>();

  const now = new Date().toISOString();
  const matchId = crypto.randomUUID();
  const evidence = JSON.stringify({ invoiceId: invoice.id, invoiceTaxCents: invoice.tax_cents, mismatches: result.mismatches });
  const statements = [
    db.prepare(`INSERT INTO reconciliation_matches
      (id,organisation_id,taxpayer_id,vat_period_id,invoice_id,ledger_entry_id,match_type,confidence_bps,status,evidence,reconciled_by,reconciled_at,created_at)
      VALUES (?,?,?,?,?,NULL,?,?,?,?,?,?,?)`).bind(
      matchId, organisation.id, invoice.supplier_taxpayer_id, vatPeriod?.id ?? null, invoice.id,
      "LEDGER_CONSISTENCY", result.status === "MATCHED" ? 10_000 : 0, result.status, evidence, actor.userId, now, now,
    ),
    outboxEvent(db, "RECONCILIATION_MATCH", matchId, result.status === "MATCHED" ? "VATTransactionMatched" : "ExceptionDetected", invoice.supplier_taxpayer_id, {
      matchId, invoiceId: invoice.id, status: result.status, correlationId,
    }),
    await appendAudit(db, actor, "RECONCILIATION_MATCH_RUN", "RECONCILIATION_MATCH", matchId, { invoiceId: invoice.id, status: result.status, mismatches: result.mismatches }),
  ];
  if (result.status === "EXCEPTION") {
    statements.push(
      db.prepare("INSERT INTO reconciliation_exceptions VALUES (?,?,?,?,?,?,?,?,NULL,NULL,NULL,NULL)").bind(
        crypto.randomUUID(), invoice.id, invoice.supplier_taxpayer_id, "LEDGER_MISMATCH", "HIGH", "OPEN", result.mismatches.join(" "), now,
      ),
    );
  }
  await db.batch(statements);
  return { id: matchId, invoiceId: invoice.id, taxpayerId: invoice.supplier_taxpayer_id, status: result.status, matchType: "LEDGER_CONSISTENCY", confidenceBps: result.status === "MATCHED" ? 10_000 : 0, mismatches: result.mismatches, reconciledAt: now };
}

export type ExceptionActionResult = { id: string; status: string };

type ExceptionRow = { id: string; status: string; taxpayer_id: string | null };

/**
 * Module 3 Phase A Assign: hands a reconciliation exception to a specific
 * officer. Not itself the work-queue (that's GetWorkQueue, Phase B — no
 * filter/status/officer/age query exists yet), just the mutation a queue
 * would call.
 */
export async function assignException(actor: UserContext, exceptionId: string, input: unknown, correlationId: string): Promise<ExceptionActionResult> {
  const { officerId } = normalizeExceptionAssignment(input);
  const db = await ensureDatabase();
  const exception = await db.prepare("SELECT id,status,taxpayer_id FROM reconciliation_exceptions WHERE id=?").bind(exceptionId).first<ExceptionRow>();
  if (!exception) throw new ReconciliationValidationError([{ code: "EXCEPTION_NOT_FOUND", path: "/exception_id", message: "The reconciliation exception does not exist." }]);
  if (exception.status === "RESOLVED") throw new RepositoryConflictError("This exception is already resolved and cannot be reassigned.");
  const officer = await db.prepare("SELECT id,status FROM app_users WHERE id=?").bind(officerId).first<{ id: string; status: string }>();
  if (!officer) throw new ReconciliationValidationError([{ code: "OFFICER_NOT_FOUND", path: "/officer_id", message: "The officer does not exist." }]);
  if (officer.status !== "ACTIVE") throw new ReconciliationValidationError([{ code: "OFFICER_NOT_ACTIVE", path: "/officer_id", message: "The officer is not active." }]);

  await db.batch([
    db.prepare("UPDATE reconciliation_exceptions SET status='ASSIGNED',assigned_officer_id=? WHERE id=?").bind(officerId, exceptionId),
    outboxEvent(db, "RECONCILIATION_EXCEPTION", exceptionId, "ExceptionAssigned", exception.taxpayer_id ?? exceptionId, { exceptionId, officerId, correlationId }),
    await appendAudit(db, actor, "EXCEPTION_ASSIGNED", "RECONCILIATION_EXCEPTION", exceptionId, { officerId }),
  ]);
  return { id: exceptionId, status: "ASSIGNED" };
}

/** Module 3 Phase A ResolveException. Idempotent on an already-resolved exception. */
export async function resolveException(actor: UserContext, exceptionId: string, input: unknown, correlationId: string): Promise<ExceptionActionResult> {
  const { notes } = normalizeExceptionResolution(input);
  const db = await ensureDatabase();
  const exception = await db.prepare("SELECT id,status,taxpayer_id FROM reconciliation_exceptions WHERE id=?").bind(exceptionId).first<ExceptionRow>();
  if (!exception) throw new ReconciliationValidationError([{ code: "EXCEPTION_NOT_FOUND", path: "/exception_id", message: "The reconciliation exception does not exist." }]);
  if (exception.status === "RESOLVED") return { id: exceptionId, status: "RESOLVED" };

  const now = new Date().toISOString();
  await db.batch([
    db.prepare("UPDATE reconciliation_exceptions SET status='RESOLVED',resolved_at=?,resolved_by=?,resolution_notes=? WHERE id=?").bind(now, actor.userId, notes, exceptionId),
    outboxEvent(db, "RECONCILIATION_EXCEPTION", exceptionId, "ExceptionResolved", exception.taxpayer_id ?? exceptionId, { exceptionId, correlationId }),
    await appendAudit(db, actor, "EXCEPTION_RESOLVED", "RECONCILIATION_EXCEPTION", exceptionId, { notes }),
  ]);
  return { id: exceptionId, status: "RESOLVED" };
}
