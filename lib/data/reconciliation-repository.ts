import { ensureDatabase } from "@/db/runtime";
import { isNationalScope, requireTaxpayerScope } from "@/lib/auth";
import { appendAuditEvent } from "@/lib/data/audit-repository";
import {
  evaluateInvoiceMatch,
  normalizeExceptionAssignment,
  normalizeExceptionResolution,
  normalizeWorkQueueQuery,
  ReconciliationValidationError,
} from "@/lib/domain/reconciliation";
import type { UserContext } from "@/lib/domain/types";
import { RepositoryConflictError } from "./repository";

/** Module 8 Phase D: delegates to the single shared hash-chain writer — see lib/data/audit-repository.ts's appendAuditEvent. */
async function appendAudit(db: D1Database, actor: UserContext, action: string, resourceType: string, resourceId: string, details: Record<string, unknown>) {
  return appendAuditEvent(db, actor, action, resourceType, resourceId, details, new Date().toISOString());
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
 *
 * Security fix 2026-08-27 (SECURITY_GAP_ASSESSMENT.md item #6): this
 * command performed no tenant-scope check at all — unlike runMatch in this
 * same file, which already calls requireTaxpayerScope. Every current
 * holder of reconciliation:manage is national-scope, so there was no live
 * leak, but the permission is tenant-grantable (see item #5's fix to
 * createOrganisationRole), and the inconsistency with runMatch was itself
 * the tell. Fixed the same way runMatch already does it.
 */
export async function assignException(actor: UserContext, exceptionId: string, input: unknown, correlationId: string): Promise<ExceptionActionResult> {
  const { officerId } = normalizeExceptionAssignment(input);
  const db = await ensureDatabase();
  const exception = await db.prepare("SELECT id,status,taxpayer_id FROM reconciliation_exceptions WHERE id=?").bind(exceptionId).first<ExceptionRow>();
  if (!exception) throw new ReconciliationValidationError([{ code: "EXCEPTION_NOT_FOUND", path: "/exception_id", message: "The reconciliation exception does not exist." }]);
  requireTaxpayerScope(actor, exception.taxpayer_id ?? "");
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

/** Module 3 Phase A ResolveException. Idempotent on an already-resolved exception. Security fix 2026-08-27 (SECURITY_GAP_ASSESSMENT.md item #6): see assignException's comment above — same missing tenant-scope check, same fix. */
export async function resolveException(actor: UserContext, exceptionId: string, input: unknown, correlationId: string): Promise<ExceptionActionResult> {
  const { notes } = normalizeExceptionResolution(input);
  const db = await ensureDatabase();
  const exception = await db.prepare("SELECT id,status,taxpayer_id FROM reconciliation_exceptions WHERE id=?").bind(exceptionId).first<ExceptionRow>();
  if (!exception) throw new ReconciliationValidationError([{ code: "EXCEPTION_NOT_FOUND", path: "/exception_id", message: "The reconciliation exception does not exist." }]);
  requireTaxpayerScope(actor, exception.taxpayer_id ?? "");
  if (exception.status === "RESOLVED") return { id: exceptionId, status: "RESOLVED" };

  const now = new Date().toISOString();
  await db.batch([
    db.prepare("UPDATE reconciliation_exceptions SET status='RESOLVED',resolved_at=?,resolved_by=?,resolution_notes=? WHERE id=?").bind(now, actor.userId, notes, exceptionId),
    outboxEvent(db, "RECONCILIATION_EXCEPTION", exceptionId, "ExceptionResolved", exception.taxpayer_id ?? exceptionId, { exceptionId, correlationId }),
    await appendAudit(db, actor, "EXCEPTION_RESOLVED", "RECONCILIATION_EXCEPTION", exceptionId, { notes }),
  ]);
  return { id: exceptionId, status: "RESOLVED" };
}

export type WorkQueueItem = {
  id: string;
  invoiceId: string;
  taxpayerId: string | null;
  exceptionType: string;
  severity: string;
  status: string;
  summary: string;
  createdAt: string;
  resolvedAt: string | null;
  assignedOfficerId: string | null;
  assignedOfficerName: string | null;
  resolvedBy: string | null;
  resolutionNotes: string | null;
  invoiceNumber: string;
  supplierName: string;
  totalCents: number;
  currency: string;
  ageDays: number;
};

export type WorkQueueResult = { items: WorkQueueItem[]; totalCount: number; limit: number; offset: number };

type WorkQueueRow = {
  id: string; invoice_id: string; taxpayer_id: string | null; exception_type: string; severity: string; status: string;
  summary: string; created_at: string; resolved_at: string | null; assigned_officer_id: string | null;
  assigned_officer_name: string | null; resolved_by: string | null; resolution_notes: string | null;
  invoice_number: string; supplier_name: string; total_cents: number; currency: string; age_days: number;
};

const AGE_DAYS_EXPRESSION = "CAST((julianday('now') - julianday(e.created_at)) AS INTEGER)";

/**
 * Module 3 Phase B GetWorkQueue: filter/status/officer/age predicates over
 * reconciliation_exceptions — listExceptions (above) took only the caller
 * for tenant scoping, with no filtering. Pagination (bounded limit, explicit
 * offset, a real totalCount) and covering indexes
 * (idx_reconciliation_exceptions_queue, idx_reconciliation_exceptions_officer
 * in db/runtime.ts) are designed in from the start, per this module's own
 * watch-out note about not retrofitting them after the first performance
 * complaint.
 */
export async function getWorkQueue(actor: UserContext, params: URLSearchParams): Promise<WorkQueueResult> {
  const query = normalizeWorkQueueQuery(params);
  const db = await ensureDatabase();

  const conditions: string[] = [];
  const values: unknown[] = [];
  if (!isNationalScope(actor)) {
    conditions.push("(i.supplier_taxpayer_id = ? OR i.customer_taxpayer_id = ?)");
    values.push(actor.taxpayerId ?? "__none__", actor.taxpayerId ?? "__none__");
  }
  if (query.status) {
    conditions.push("e.status = ?");
    values.push(query.status);
  }
  if (query.severity) {
    conditions.push("e.severity = ?");
    values.push(query.severity);
  }
  if (query.assignedOfficerId) {
    conditions.push("e.assigned_officer_id = ?");
    values.push(query.assignedOfficerId);
  }
  if (query.unassignedOnly) {
    conditions.push("e.assigned_officer_id IS NULL");
  }
  if (query.minAgeDays !== null) {
    conditions.push(`${AGE_DAYS_EXPRESSION} >= ?`);
    values.push(query.minAgeDays);
  }
  if (query.maxAgeDays !== null) {
    conditions.push(`${AGE_DAYS_EXPRESSION} <= ?`);
    values.push(query.maxAgeDays);
  }
  const whereClause = conditions.length ? `WHERE ${conditions.join(" AND ")}` : "";

  const [items, count] = await Promise.all([
    db.prepare(`SELECT e.id, e.invoice_id, e.taxpayer_id, e.exception_type, e.severity, e.status, e.summary,
        e.created_at, e.resolved_at, e.assigned_officer_id, officer.display_name AS assigned_officer_name,
        e.resolved_by, e.resolution_notes, i.invoice_number, i.supplier_name, i.total_cents, i.currency,
        ${AGE_DAYS_EXPRESSION} AS age_days
      FROM reconciliation_exceptions e
      JOIN invoices i ON i.id = e.invoice_id
      LEFT JOIN app_users officer ON officer.id = e.assigned_officer_id
      ${whereClause}
      ORDER BY CASE e.severity WHEN 'CRITICAL' THEN 1 WHEN 'HIGH' THEN 2 WHEN 'MEDIUM' THEN 3 ELSE 4 END, e.created_at DESC
      LIMIT ? OFFSET ?`)
      .bind(...values, query.limit, query.offset).all<WorkQueueRow>(),
    db.prepare(`SELECT COUNT(*) AS n FROM reconciliation_exceptions e JOIN invoices i ON i.id = e.invoice_id ${whereClause}`)
      .bind(...values).first<{ n: number }>(),
  ]);

  return {
    items: items.results.map((row) => ({
      id: row.id, invoiceId: row.invoice_id, taxpayerId: row.taxpayer_id, exceptionType: row.exception_type,
      severity: row.severity, status: row.status, summary: row.summary, createdAt: row.created_at, resolvedAt: row.resolved_at,
      assignedOfficerId: row.assigned_officer_id, assignedOfficerName: row.assigned_officer_name, resolvedBy: row.resolved_by,
      resolutionNotes: row.resolution_notes, invoiceNumber: row.invoice_number, supplierName: row.supplier_name,
      totalCents: row.total_cents, currency: row.currency, ageDays: row.age_days,
    })),
    totalCount: count?.n ?? 0,
    limit: query.limit,
    offset: query.offset,
  };
}
