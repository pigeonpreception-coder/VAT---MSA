import { ensureDatabase } from "@/db/runtime";
import {
  calculateAndValidateInvoice,
  getVatNumber,
  InvoiceValidationError,
  normalizeInvoiceCancellation,
  scoreInvoice,
  sha256Hex,
  stableStringify,
} from "@/lib/domain/invoice";
import type { InvoiceDetail, InvoiceSubmission, InvoiceSummary, RiskLevel, UserContext } from "@/lib/domain/types";
import { hasPermission, isNationalScope, requireTaxpayerScope } from "@/lib/auth";
import type { RequestContext } from "@/lib/security/request";

type InvoiceRow = {
  id: string; invoice_number: string; document_type: string; source_system: string; source_document_id: string;
  supplier_name: string; supplier_vat_number: string; customer_name: string; customer_vat_number: string | null;
  issue_date: string; currency: string; line_net_cents: number; tax_cents: number; total_cents: number;
  status: string; risk_level: RiskLevel; payload_hash: string; transaction_id: string; certificate_id: string;
  verification_token: string; certified_at: string; signature?: string; signature_profile?: string;
};

type CorrectionOriginal = {
  id: string;
  invoice_number: string;
  document_type: string;
  source_document_id: string;
  customer_taxpayer_id: string | null;
  customer_vat_number: string | null;
  issue_date: string;
  currency: string;
  line_net_cents: number;
  tax_cents: number;
  total_cents: number;
  transaction_id: string;
};

export class RepositoryConflictError extends Error {
  constructor(message: string) { super(message); this.name = "RepositoryConflictError"; }
}

function mapInvoice(row: InvoiceRow): InvoiceSummary {
  return {
    id: row.id,
    invoiceNumber: row.invoice_number,
    documentType: row.document_type,
    supplierName: row.supplier_name,
    supplierVatNumber: row.supplier_vat_number,
    customerName: row.customer_name,
    customerVatNumber: row.customer_vat_number,
    issueDate: row.issue_date,
    currency: row.currency,
    lineNetCents: row.line_net_cents,
    taxCents: row.tax_cents,
    totalCents: row.total_cents,
    status: row.status,
    riskLevel: row.risk_level,
    transactionId: row.transaction_id,
    certificateId: row.certificate_id,
    verificationToken: row.verification_token,
    certifiedAt: row.certified_at,
  };
}

export async function listInvoices(user: UserContext, limit = 100): Promise<InvoiceSummary[]> {
  const db = await ensureDatabase();
  const result = isNationalScope(user)
    ? await db.prepare("SELECT * FROM invoices ORDER BY issue_date DESC, certified_at DESC LIMIT ?").bind(limit).all<InvoiceRow>()
    : await db.prepare("SELECT * FROM invoices WHERE supplier_taxpayer_id = ? OR customer_taxpayer_id = ? ORDER BY issue_date DESC, certified_at DESC LIMIT ?")
      .bind(user.taxpayerId ?? "__none__", user.taxpayerId ?? "__none__", limit).all<InvoiceRow>();
  return result.results.map(mapInvoice);
}

export async function getInvoiceById(id: string, user: UserContext): Promise<InvoiceDetail | null> {
  const db = await ensureDatabase();
  const row = isNationalScope(user)
    ? await db.prepare(`SELECT i.*, c.signature, c.signature_profile FROM invoices i JOIN certificates c ON c.invoice_id = i.id WHERE i.id = ?`).bind(id).first<InvoiceRow>()
    : await db.prepare(`SELECT i.*, c.signature, c.signature_profile FROM invoices i JOIN certificates c ON c.invoice_id = i.id
      WHERE i.id = ? AND (i.supplier_taxpayer_id = ? OR i.customer_taxpayer_id = ?)`).bind(id, user.taxpayerId ?? "__none__", user.taxpayerId ?? "__none__").first<InvoiceRow>();
  if (!row) return null;

  const [lineResult, ledgerResult, correctionResult, correctionsResult] = await Promise.all([
    db.prepare("SELECT * FROM invoice_lines WHERE invoice_id = ? ORDER BY line_number").bind(id).all<{
      id: string; line_number: number; description: string; quantity: string; unit_code: string; unit_price_cents: number;
      net_amount_cents: number; tax_rate_bps: number; tax_category: string; tax_amount_cents: number;
    }>(),
    db.prepare(`SELECT l.*, t.legal_name AS taxpayer_name FROM ledger_entries l JOIN taxpayers t ON t.id = l.taxpayer_id WHERE l.invoice_id = ? ORDER BY l.entry_type DESC`).bind(id).all<{
      id: string; taxpayer_name: string; entry_type: string; direction: string; amount_cents: number; period: string;
    }>(),
    db.prepare(`SELECT c.*,i.invoice_number AS original_invoice_number FROM invoice_corrections c
      JOIN invoices i ON i.id=c.original_invoice_id WHERE c.correction_invoice_id=?`).bind(id).first<{
        original_invoice_id: string; original_invoice_number: string; correction_type: string; reason_code: string | null; reason: string; status: string; created_at: string;
      }>(),
    db.prepare(`SELECT c.*,i.invoice_number AS correction_invoice_number,i.total_cents FROM invoice_corrections c
      JOIN invoices i ON i.id=c.correction_invoice_id WHERE c.original_invoice_id=? ORDER BY c.created_at`).bind(id).all<{
        correction_invoice_id: string; correction_invoice_number: string; correction_type: string; reason_code: string | null; reason: string; status: string; total_cents: number; created_at: string;
      }>(),
  ]);

  return {
    ...mapInvoice(row),
    sourceSystem: row.source_system,
    sourceDocumentId: row.source_document_id,
    payloadHash: row.payload_hash,
    signature: row.signature ?? "",
    signatureProfile: row.signature_profile ?? "",
    correction: correctionResult ? {
      originalInvoiceId: correctionResult.original_invoice_id,
      originalInvoiceNumber: correctionResult.original_invoice_number,
      correctionType: correctionResult.correction_type,
      reasonCode: correctionResult.reason_code,
      reason: correctionResult.reason,
      status: correctionResult.status,
      createdAt: correctionResult.created_at,
    } : null,
    corrections: correctionsResult.results.map((correction) => ({
      correctionInvoiceId: correction.correction_invoice_id,
      correctionInvoiceNumber: correction.correction_invoice_number,
      correctionType: correction.correction_type,
      reasonCode: correction.reason_code,
      reason: correction.reason,
      status: correction.status,
      totalCents: correction.total_cents,
      createdAt: correction.created_at,
    })),
    lines: lineResult.results.map((line) => ({
      id: line.id, lineNumber: line.line_number, description: line.description, quantity: line.quantity,
      unitCode: line.unit_code, unitPriceCents: line.unit_price_cents, netAmountCents: line.net_amount_cents,
      taxRateBps: line.tax_rate_bps, taxCategory: line.tax_category, taxAmountCents: line.tax_amount_cents,
    })),
    ledgerEntries: ledgerResult.results.map((entry) => ({
      id: entry.id, taxpayerName: entry.taxpayer_name, entryType: entry.entry_type,
      direction: entry.direction, amountCents: entry.amount_cents, period: entry.period,
    })),
  };
}

export type InvoiceCancellationResult = { invoiceId: string; status: string };

/**
 * Module 2 Phase B CancelInvoice. Deliberately narrow and officer-only (see
 * normalizeInvoiceCancellation in lib/domain/invoice.ts): only a
 * TAX_INVOICE/SIMPLIFIED_TAX_INVOICE/SELF_BILLED_INVOICE with no active
 * correction against it can be cancelled — an invoice already in a
 * correction lineage must be resolved through further corrections, not
 * voided out from under them. Never deletes or mutates the original row;
 * reverses its ledger/return effect the same way a credit note does (new
 * flipped-direction rows, never mutating existing ones) and marks
 * status='CANCELLED', which the public VerifyInvoice endpoint surfaces.
 * Idempotent on an already-cancelled invoice.
 */
export async function cancelInvoice(
  actor: UserContext,
  invoiceId: string,
  input: unknown,
  correlationId: string,
): Promise<InvoiceCancellationResult> {
  const { reason } = normalizeInvoiceCancellation(input);
  const db = await ensureDatabase();
  const invoice = await db.prepare(`SELECT id, document_type, status, supplier_taxpayer_id, customer_taxpayer_id,
    tax_cents, issue_date, transaction_id FROM invoices WHERE id = ?`).bind(invoiceId).first<{
      id: string; document_type: string; status: string; supplier_taxpayer_id: string; customer_taxpayer_id: string | null;
      tax_cents: number; issue_date: string; transaction_id: string;
    }>();
  if (!invoice) throw new InvoiceValidationError([{ code: "INVOICE_NOT_FOUND", path: "/invoice_id", message: "The invoice does not exist." }]);
  requireTaxpayerScope(actor, invoice.supplier_taxpayer_id);
  if (invoice.status === "CANCELLED") return { invoiceId: invoice.id, status: "CANCELLED" };
  if (!["TAX_INVOICE", "SIMPLIFIED_TAX_INVOICE", "SELF_BILLED_INVOICE"].includes(invoice.document_type)) {
    throw new InvoiceValidationError([{ code: "NOT_CANCELLABLE_DOCUMENT_TYPE", path: "/document_type", message: "Only an original tax invoice can be cancelled; a credit or debit note cannot." }]);
  }
  const activeCorrection = await db.prepare("SELECT id FROM invoice_corrections WHERE original_invoice_id=? AND status='ACTIVE' LIMIT 1")
    .bind(invoice.id).first<{ id: string }>();
  if (activeCorrection) {
    throw new RepositoryConflictError("This invoice already has an active credit or debit note against it; resolve the correction lineage instead of cancelling.");
  }

  const now = new Date().toISOString();
  const transactionId = crypto.randomUUID();
  const period = invoice.issue_date.slice(0, 7);
  const statements: D1PreparedStatement[] = [
    db.prepare("UPDATE invoices SET status='CANCELLED' WHERE id=?").bind(invoice.id),
    // Module 2 Phase D: Reverse. A new VATTransaction linked back to the
    // certification it reverses via reference_transaction_id — the
    // certification row itself is never mutated.
    db.prepare("INSERT INTO vat_transactions VALUES (?,?,?,?,?,?)").bind(
      transactionId, invoice.id, invoice.supplier_taxpayer_id, "CANCELLATION", invoice.transaction_id, now,
    ),
    db.prepare("INSERT INTO ledger_entries VALUES (?,?,?,?,?,?,?,?,?)").bind(
      crypto.randomUUID(), transactionId, invoice.id, invoice.supplier_taxpayer_id, "OUTPUT_VAT", "DEBIT", invoice.tax_cents, period, now,
    ),
  ];
  if (invoice.customer_taxpayer_id) {
    statements.push(
      db.prepare("INSERT INTO ledger_entries VALUES (?,?,?,?,?,?,?,?,?)").bind(
        crypto.randomUUID(), transactionId, invoice.id, invoice.customer_taxpayer_id, "INPUT_VAT", "CREDIT", invoice.tax_cents, period, now,
      ),
    );
  }
  const priorAudit = await db.prepare("SELECT event_hash FROM audit_events ORDER BY occurred_at DESC LIMIT 1").first<{ event_hash: string }>();
  const auditId = crypto.randomUUID();
  const auditDetails = JSON.stringify({ invoiceId: invoice.id, reason, correlationId });
  const auditHash = await sha256Hex(`${priorAudit?.event_hash ?? "GENESIS"}|${auditId}|${actor.userId}|${auditDetails}|${now}`);
  statements.push(
    db.prepare("INSERT INTO audit_events VALUES (?,?,?,?,?,?,?,?,?,?,?)").bind(
      auditId, actor.userId, actor.role, "INVOICE_CANCELLED", "INVOICE", invoice.id, "SUCCESS",
      auditDetails, priorAudit?.event_hash ?? null, auditHash, now,
    ),
    db.prepare("INSERT INTO outbox_events VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)").bind(
      crypto.randomUUID(), "INVOICE", invoice.id, "InvoiceCancelled", 1, invoice.supplier_taxpayer_id,
      JSON.stringify({ invoice_id: invoice.id, reason, correlation_id: correlationId }),
      "PENDING", 0, now, now, null, null,
    ),
  );
  await db.batch(statements);
  return { invoiceId: invoice.id, status: "CANCELLED" };
}

export type InvoiceVatExplanation = {
  invoiceId: string;
  invoiceNumber: string;
  lines: Array<{
    lineNumber: number;
    taxCategory: string;
    taxableAmountCents: number;
    taxRateBps: number;
    taxAmountCents: number;
    vatRuleId: string | null;
    vatRuleVersion: number | null;
    ruleEffectiveFrom: string | null;
    ruleEffectiveTo: string | null;
  }>;
};

/**
 * Module 2 Phase A ExplainCalculation: for an already-certified invoice,
 * exactly which approved VATRule version produced each line's tax amount.
 * vat_rule_id is stored on invoice_lines at submission time (submitInvoice
 * above) — this just projects it back out, tenant-scoped the same way
 * getInvoiceById is.
 */
export async function explainInvoiceVat(id: string, user: UserContext): Promise<InvoiceVatExplanation | null> {
  const db = await ensureDatabase();
  const invoice = isNationalScope(user)
    ? await db.prepare("SELECT id, invoice_number FROM invoices WHERE id = ?").bind(id).first<{ id: string; invoice_number: string }>()
    : await db.prepare("SELECT id, invoice_number FROM invoices WHERE id = ? AND (supplier_taxpayer_id = ? OR customer_taxpayer_id = ?)")
      .bind(id, user.taxpayerId ?? "__none__", user.taxpayerId ?? "__none__").first<{ id: string; invoice_number: string }>();
  if (!invoice) return null;

  const lines = await db.prepare(`SELECT l.line_number, l.tax_category, l.net_amount_cents, l.tax_rate_bps, l.tax_amount_cents,
    r.id AS vat_rule_id, r.version AS vat_rule_version, r.effective_from AS rule_effective_from, r.effective_to AS rule_effective_to
    FROM invoice_lines l LEFT JOIN vat_rules r ON r.id = l.vat_rule_id
    WHERE l.invoice_id = ? ORDER BY l.line_number`).bind(id).all<{
      line_number: number; tax_category: string; net_amount_cents: number; tax_rate_bps: number; tax_amount_cents: number;
      vat_rule_id: string | null; vat_rule_version: number | null; rule_effective_from: string | null; rule_effective_to: string | null;
    }>();

  return {
    invoiceId: invoice.id,
    invoiceNumber: invoice.invoice_number,
    lines: lines.results.map((line) => ({
      lineNumber: line.line_number,
      taxCategory: line.tax_category,
      taxableAmountCents: line.net_amount_cents,
      taxRateBps: line.tax_rate_bps,
      taxAmountCents: line.tax_amount_cents,
      vatRuleId: line.vat_rule_id,
      vatRuleVersion: line.vat_rule_version,
      ruleEffectiveFrom: line.rule_effective_from,
      ruleEffectiveTo: line.rule_effective_to,
    })),
  };
}

export type TransactionTimelineEvent = {
  transactionId: string;
  transactionType: string;
  referenceTransactionId: string | null;
  invoiceId: string;
  invoiceNumber: string;
  documentType: string;
  occurredAt: string;
  ledgerEntries: Array<{ taxpayerName: string; entryType: string; direction: string; amountCents: number; period: string }>;
};
export type TransactionTimeline = { rootInvoiceId: string; rootInvoiceNumber: string; events: TransactionTimelineEvent[] };

/**
 * Module 2 Phase D GetTransactionTimeline: the complete audit narrative for
 * one invoice's lineage — its certification, every correction issued
 * against it, and its cancellation if any — as a chronological sequence of
 * VATTransaction events, each with the ledger postings it actually made.
 * Accepts any invoice id within a lineage (the true original, or one of its
 * corrections) and always resolves to the same timeline, rooted at the
 * original. Tenant scope is checked once, against the invoice the caller
 * asked for; that transitively secures the rest of the lineage, since a
 * correction's supplier and customer are invariant with its original by
 * construction (submitInvoice enforces both).
 */
export async function getTransactionTimeline(id: string, user: UserContext): Promise<TransactionTimeline | null> {
  const db = await ensureDatabase();
  const invoice = isNationalScope(user)
    ? await db.prepare("SELECT id, invoice_number FROM invoices WHERE id = ?").bind(id).first<{ id: string; invoice_number: string }>()
    : await db.prepare("SELECT id, invoice_number FROM invoices WHERE id = ? AND (supplier_taxpayer_id = ? OR customer_taxpayer_id = ?)")
      .bind(id, user.taxpayerId ?? "__none__", user.taxpayerId ?? "__none__").first<{ id: string; invoice_number: string }>();
  if (!invoice) return null;

  const asCorrectionOf = await db.prepare("SELECT original_invoice_id FROM invoice_corrections WHERE correction_invoice_id = ?")
    .bind(invoice.id).first<{ original_invoice_id: string }>();
  const rootId = asCorrectionOf?.original_invoice_id ?? invoice.id;
  const root = rootId === invoice.id
    ? invoice
    : await db.prepare("SELECT id, invoice_number FROM invoices WHERE id = ?").bind(rootId).first<{ id: string; invoice_number: string }>();
  if (!root) return null;

  const corrections = await db.prepare("SELECT correction_invoice_id FROM invoice_corrections WHERE original_invoice_id = ? ORDER BY created_at")
    .bind(rootId).all<{ correction_invoice_id: string }>();
  const lineageInvoiceIds = [rootId, ...corrections.results.map((row) => row.correction_invoice_id)];

  const transactions = await db.prepare(`SELECT t.id, t.transaction_type, t.reference_transaction_id, t.invoice_id, t.created_at,
      i.invoice_number, i.document_type
    FROM vat_transactions t JOIN invoices i ON i.id = t.invoice_id
    WHERE t.invoice_id IN (${lineageInvoiceIds.map(() => "?").join(",")}) ORDER BY t.created_at`)
    .bind(...lineageInvoiceIds).all<{
      id: string; transaction_type: string; reference_transaction_id: string | null; invoice_id: string; created_at: string;
      invoice_number: string; document_type: string;
    }>();

  const ledgerByTransaction = await Promise.all(transactions.results.map((transaction) =>
    db.prepare(`SELECT l.entry_type, l.direction, l.amount_cents, l.period, tp.legal_name AS taxpayer_name
      FROM ledger_entries l JOIN taxpayers tp ON tp.id = l.taxpayer_id
      WHERE l.transaction_id = ? ORDER BY l.entry_type DESC`)
      .bind(transaction.id).all<{ entry_type: string; direction: string; amount_cents: number; period: string; taxpayer_name: string }>(),
  ));

  return {
    rootInvoiceId: root.id,
    rootInvoiceNumber: root.invoice_number,
    events: transactions.results.map((transaction, index) => ({
      transactionId: transaction.id,
      transactionType: transaction.transaction_type,
      referenceTransactionId: transaction.reference_transaction_id,
      invoiceId: transaction.invoice_id,
      invoiceNumber: transaction.invoice_number,
      documentType: transaction.document_type,
      occurredAt: transaction.created_at,
      ledgerEntries: ledgerByTransaction[index].results.map((entry) => ({
        taxpayerName: entry.taxpayer_name, entryType: entry.entry_type, direction: entry.direction,
        amountCents: entry.amount_cents, period: entry.period,
      })),
    })),
  };
}

export async function getDashboardSnapshot(user: UserContext) {
  const db = await ensureDatabase();
  const scoped = !isNationalScope(user);
  const taxpayerId = user.taxpayerId ?? "__none__";
  const [metrics, recentInvoices, recentAudit, riskCounts] = await Promise.all([
    scoped ? db.prepare(`SELECT COUNT(*) AS invoice_count, COALESCE(SUM(total_cents),0) AS total_cents,
      COALESCE(SUM(tax_cents),0) AS tax_cents,
      SUM(CASE WHEN status = 'EXCEPTION' THEN 1 ELSE 0 END) AS exception_count
      FROM invoices WHERE supplier_taxpayer_id = ? OR customer_taxpayer_id = ?`).bind(taxpayerId, taxpayerId).first<{ invoice_count: number; total_cents: number; tax_cents: number; exception_count: number }>()
      : db.prepare(`SELECT COUNT(*) AS invoice_count, COALESCE(SUM(total_cents),0) AS total_cents,
      COALESCE(SUM(tax_cents),0) AS tax_cents,
      SUM(CASE WHEN status = 'EXCEPTION' THEN 1 ELSE 0 END) AS exception_count
      FROM invoices`).first<{ invoice_count: number; total_cents: number; tax_cents: number; exception_count: number }>(),
    listInvoices(user, 6),
    hasPermission(user, "audit:read") ? db.prepare("SELECT * FROM audit_events ORDER BY occurred_at DESC LIMIT 6").all<{
      id: string; action: string; resource_type: string; resource_id: string; outcome: string; details: string; occurred_at: string;
    }>() : Promise.resolve({ results: [] }),
    scoped ? db.prepare("SELECT risk_level, COUNT(*) AS count FROM invoices WHERE supplier_taxpayer_id = ? OR customer_taxpayer_id = ? GROUP BY risk_level")
      .bind(taxpayerId, taxpayerId).all<{ risk_level: RiskLevel; count: number }>()
      : db.prepare("SELECT risk_level, COUNT(*) AS count FROM invoices GROUP BY risk_level").all<{ risk_level: RiskLevel; count: number }>(),
  ]);
  return {
    metrics: metrics ?? { invoice_count: 0, total_cents: 0, tax_cents: 0, exception_count: 0 },
    recentInvoices,
    recentAudit: recentAudit.results,
    riskCounts: riskCounts.results,
  };
}

export async function listTaxpayers() {
  const db = await ensureDatabase();
  const result = await db.prepare(`SELECT t.*,
    (SELECT o.id FROM organisations o WHERE o.taxpayer_id = t.id AND o.status = 'ACTIVE') AS organisation_id,
    COALESCE((SELECT GROUP_CONCAT(c.capability, ',') FROM organisation_capabilities c JOIN organisations o ON o.id=c.organisation_id
      WHERE o.taxpayer_id=t.id AND c.status='ACTIVE' AND datetime(c.effective_from)<=CURRENT_TIMESTAMP
        AND (c.effective_to IS NULL OR datetime(c.effective_to)>CURRENT_TIMESTAMP)), '') AS capabilities,
    (SELECT COUNT(*) FROM invoices i WHERE i.supplier_taxpayer_id = t.id OR i.customer_taxpayer_id = t.id) AS transaction_count,
    (SELECT COALESCE(SUM(amount_cents),0) FROM ledger_entries l WHERE l.taxpayer_id = t.id AND l.entry_type = 'OUTPUT_VAT') AS output_tax_cents,
    (SELECT COALESCE(SUM(amount_cents),0) FROM ledger_entries l WHERE l.taxpayer_id = t.id AND l.entry_type = 'INPUT_VAT') AS input_tax_cents
    FROM taxpayers t ORDER BY t.legal_name`).all<Record<string, string | number | null>>();
  return result.results;
}

export async function listTaxpayerOptions(user: UserContext) {
  const db = await ensureDatabase();
  const result = isNationalScope(user)
    ? await db.prepare("SELECT id, legal_name, vat_number FROM taxpayers WHERE vat_status = 'ACTIVE' ORDER BY legal_name").all<{ id: string; legal_name: string; vat_number: string }>()
    : await db.prepare("SELECT id, legal_name, vat_number FROM taxpayers WHERE id = ? AND vat_status = 'ACTIVE'").bind(user.taxpayerId ?? "__none__").all<{ id: string; legal_name: string; vat_number: string }>();
  return result.results;
}

export async function listExceptions(user: UserContext) {
  const db = await ensureDatabase();
  const query = `SELECT e.*, i.invoice_number, i.supplier_name, i.total_cents, i.currency
    FROM reconciliation_exceptions e JOIN invoices i ON i.id = e.invoice_id
    ${isNationalScope(user) ? "" : "WHERE i.supplier_taxpayer_id = ? OR i.customer_taxpayer_id = ?"}
    ORDER BY CASE e.severity WHEN 'CRITICAL' THEN 1 WHEN 'HIGH' THEN 2 WHEN 'MEDIUM' THEN 3 ELSE 4 END, e.created_at DESC`;
  const statement = db.prepare(query);
  const result = isNationalScope(user)
    ? await statement.all<Record<string, string | number | null>>()
    : await statement.bind(user.taxpayerId ?? "__none__", user.taxpayerId ?? "__none__").all<Record<string, string | number | null>>();
  return result.results;
}

export async function listAuditEvents(limit = 100) {
  const db = await ensureDatabase();
  const result = await db.prepare("SELECT * FROM audit_events ORDER BY occurred_at DESC LIMIT ?").bind(limit).all<Record<string, string | null>>();
  return result.results;
}

export async function getSecurityOperationsSnapshot() {
  const db = await ensureDatabase();
  const [eventCounts, incidents, recentEvents, outbox, database] = await Promise.all([
    db.prepare("SELECT severity, COUNT(*) AS count FROM security_events GROUP BY severity").all<{ severity: string; count: number }>(),
    db.prepare("SELECT * FROM security_incidents ORDER BY CASE severity WHEN 'CRITICAL' THEN 1 WHEN 'HIGH' THEN 2 WHEN 'MEDIUM' THEN 3 ELSE 4 END, updated_at DESC LIMIT 20").all<Record<string, string | null>>(),
    db.prepare("SELECT * FROM security_events ORDER BY occurred_at DESC LIMIT 20").all<Record<string, string | null>>(),
    db.prepare("SELECT status, COUNT(*) AS count FROM outbox_events GROUP BY status").all<{ status: string; count: number }>(),
    db.prepare("SELECT COUNT(*) AS taxpayers, (SELECT COUNT(*) FROM invoices) AS invoices, (SELECT COUNT(*) FROM audit_events) AS audit_events FROM taxpayers").first<{ taxpayers: number; invoices: number; audit_events: number }>(),
  ]);
  return { eventCounts: eventCounts.results, incidents: incidents.results, recentEvents: recentEvents.results, outbox: outbox.results, database };
}

/**
 * Module 2 Phase C/B: the public VerifyInvoice output now includes
 * correction lineage — previously only the authenticated GET /invoices/:id
 * showed whether an invoice had been credited/debited or was itself a
 * correction, which meant a paper/QR verification of a since-corrected
 * invoice looked identical to an unaffected one. Deliberately excludes
 * correction reason text (kept authenticated-only in getInvoiceById) since
 * this is an unauthenticated, public-posture endpoint.
 */
export async function getPublicVerification(token: string) {
  const db = await ensureDatabase();
  const invoice = await db.prepare(`SELECT c.status AS certificate_status, c.issued_at, c.invoice_hash, c.signature_profile,
    i.id AS invoice_id, i.status, i.document_type, i.supplier_name, i.invoice_number, i.total_cents, i.currency
    FROM certificates c JOIN invoices i ON i.id = c.invoice_id WHERE c.verification_token = ?`).bind(token).first<{
      certificate_status: string; issued_at: string; invoice_hash: string; signature_profile: string;
      invoice_id: string; status: string; document_type: string; supplier_name: string; invoice_number: string; total_cents: number; currency: string;
    }>();
  if (!invoice) return null;

  const [asOriginal, asCorrection] = await Promise.all([
    db.prepare(`SELECT c.correction_type, c.status, i.invoice_number, i.total_cents, c.created_at
      FROM invoice_corrections c JOIN invoices i ON i.id = c.correction_invoice_id
      WHERE c.original_invoice_id = ? ORDER BY c.created_at`).bind(invoice.invoice_id).all<{
        correction_type: string; status: string; invoice_number: string; total_cents: number; created_at: string;
      }>(),
    db.prepare(`SELECT c.correction_type, i.invoice_number FROM invoice_corrections c
      JOIN invoices i ON i.id = c.original_invoice_id WHERE c.correction_invoice_id = ?`).bind(invoice.invoice_id).first<{
        correction_type: string; invoice_number: string;
      }>(),
  ]);

  return {
    certificate_status: invoice.certificate_status,
    issued_at: invoice.issued_at,
    invoice_hash: invoice.invoice_hash,
    signature_profile: invoice.signature_profile,
    status: invoice.status,
    supplier_name: invoice.supplier_name,
    invoice_number: invoice.invoice_number,
    total_cents: invoice.total_cents,
    currency: invoice.currency,
    is_correction: Boolean(asCorrection),
    corrects_invoice_number: asCorrection?.invoice_number ?? null,
    correction_type: asCorrection?.correction_type ?? null,
    corrections: asOriginal.results.map((row) => ({
      correction_type: row.correction_type,
      status: row.status,
      invoice_number: row.invoice_number,
      total_cents: row.total_cents,
      created_at: row.created_at,
    })),
  };
}

export async function submitInvoice(payload: InvoiceSubmission, actor: UserContext, idempotencyKey: string, context: RequestContext): Promise<InvoiceDetail> {
  if (idempotencyKey.length < 16 || idempotencyKey.length > 128) {
    throw new InvoiceValidationError([{ code: "IDEMPOTENCY_KEY_INVALID", path: "/headers/idempotency-key", message: "Idempotency key must contain 16 to 128 characters." }]);
  }
  const calculated = calculateAndValidateInvoice(payload);
  const db = await ensureDatabase();
  const requestHash = await sha256Hex(stableStringify(payload));
  const prior = await db.prepare("SELECT request_hash, response_invoice_id FROM idempotency_records WHERE actor_id = ? AND idempotency_key = ?")
    .bind(actor.userId, idempotencyKey).first<{ request_hash: string; response_invoice_id: string }>();
  if (prior) {
    if (prior.request_hash !== requestHash) throw new RepositoryConflictError("The idempotency key was already used for a different invoice payload.");
    const existing = await getInvoiceById(prior.response_invoice_id, actor);
    if (!existing) throw new RepositoryConflictError("The prior idempotent response is unavailable.");
    return existing;
  }

  // Module 2 Phase A: every line's tax rate must resolve to a NamRA-approved
  // VATRule for its category as of the invoice's issue date — fails closed
  // (no rule bound) rather than trusting the client-supplied rate, which is
  // all lib/domain/invoice.ts's calculateAndValidateInvoice checks (internal
  // arithmetic consistency only, not statutory correctness). Dynamic import
  // avoids a static circular dependency (vat-rule-repository.ts imports
  // RepositoryConflictError from this file), matching the pattern already
  // used in lib/security/request.ts for the same reason.
  const { getApplicableVatRule } = await import("./vat-rule-repository");
  const vatRuleIdByLineNumber = new Map<number, string>();
  for (const line of calculated.lines) {
    const rule = await getApplicableVatRule(db, line.tax.category, payload.issue_date);
    if (!rule) {
      throw new InvoiceValidationError([{ code: "NO_APPROVED_VAT_RULE", path: `/lines/${line.line_number - 1}/tax/category`, message: `No approved VAT rule is bound for ${line.tax.category} on ${payload.issue_date}.` }]);
    }
    if (rule.rateBps !== line.taxRateBps) {
      throw new InvoiceValidationError([{ code: "VAT_RATE_RULE_MISMATCH", path: `/lines/${line.line_number - 1}/tax/rate`, message: `${line.tax.category} must use ${(rule.rateBps / 100).toFixed(2)}% per approved rule version ${rule.version} (received ${(line.taxRateBps / 100).toFixed(2)}%).` }]);
    }
    vatRuleIdByLineNumber.set(line.line_number, rule.id);
  }

  const supplierVat = getVatNumber(payload.supplier);
  const customerVat = getVatNumber(payload.customer);
  const supplier = await db.prepare(`SELECT t.id FROM taxpayers t
    JOIN organisations o ON o.taxpayer_id=t.id AND o.status='ACTIVE'
    JOIN organisation_capabilities c ON c.organisation_id=o.id AND c.capability='SELLER' AND c.status='ACTIVE'
      AND datetime(c.effective_from)<=CURRENT_TIMESTAMP AND (c.effective_to IS NULL OR datetime(c.effective_to)>CURRENT_TIMESTAMP)
    WHERE t.vat_number=? AND t.vat_status='ACTIVE' LIMIT 1`).bind(supplierVat).first<{ id: string }>();
  if (!supplier) {
    throw new InvoiceValidationError([{ code: "SUPPLIER_NOT_AUTHORISED", path: "/supplier/identifiers", message: "Supplier VAT number does not resolve to an active organisation with seller capability." }]);
  }
  requireTaxpayerScope(actor, supplier.id);
  const customer = customerVat
    ? await db.prepare(`SELECT t.id FROM taxpayers t
      JOIN organisations o ON o.taxpayer_id=t.id AND o.status='ACTIVE'
      JOIN organisation_capabilities c ON c.organisation_id=o.id AND c.capability='BUYER' AND c.status='ACTIVE'
        AND datetime(c.effective_from)<=CURRENT_TIMESTAMP AND (c.effective_to IS NULL OR datetime(c.effective_to)>CURRENT_TIMESTAMP)
      WHERE t.vat_number=? AND t.vat_status='ACTIVE' LIMIT 1`).bind(customerVat).first<{ id: string }>()
    : null;
  const isCorrection = payload.document_type === "CREDIT_NOTE" || payload.document_type === "DEBIT_NOTE";
  let originalInvoice: CorrectionOriginal | null = null;
  if (isCorrection) {
    const reference = payload.original_document_reference!;
    if (reference.vat_msa_invoice_id) {
      originalInvoice = await db.prepare(`SELECT id,invoice_number,document_type,source_document_id,customer_taxpayer_id,customer_vat_number,
        issue_date,currency,line_net_cents,tax_cents,total_cents,transaction_id FROM invoices WHERE id=? AND supplier_taxpayer_id=?`)
        .bind(reference.vat_msa_invoice_id, supplier.id).first<CorrectionOriginal>();
      if (originalInvoice && originalInvoice.source_document_id !== reference.source_document_id) {
        throw new RepositoryConflictError("The correction's VAT-MSA invoice id and source document reference do not identify the same original invoice.");
      }
    } else {
      const candidates = await db.prepare(`SELECT id,invoice_number,document_type,source_document_id,customer_taxpayer_id,customer_vat_number,
        issue_date,currency,line_net_cents,tax_cents,total_cents,transaction_id FROM invoices WHERE source_document_id=? AND supplier_taxpayer_id=? LIMIT 2`)
        .bind(reference.source_document_id, supplier.id).all<CorrectionOriginal>();
      if (candidates.results.length > 1) throw new RepositoryConflictError("The source document reference is ambiguous; include vat_msa_invoice_id.");
      originalInvoice = candidates.results[0] ?? null;
    }
    if (!originalInvoice) throw new RepositoryConflictError("The original invoice was not found in the authorised supplier scope.");
    if (!["TAX_INVOICE", "SIMPLIFIED_TAX_INVOICE", "SELF_BILLED_INVOICE"].includes(originalInvoice.document_type)) throw new RepositoryConflictError("A correction must reference an original invoice, not another correction document.");
    if (originalInvoice.currency !== payload.currency) throw new RepositoryConflictError("A correction must use the original invoice currency.");
    if (payload.issue_date < originalInvoice.issue_date) throw new RepositoryConflictError("A correction cannot be issued before the original invoice.");
    if (originalInvoice.customer_taxpayer_id !== (customer?.id ?? null) || (originalInvoice.customer_vat_number ?? null) !== (customerVat ?? null)) throw new RepositoryConflictError("A correction must preserve the original customer identity.");
    if (payload.document_type === "CREDIT_NOTE") {
      const prior = await db.prepare(`SELECT COALESCE(SUM(i.line_net_cents),0) AS line_net_cents,
        COALESCE(SUM(i.tax_cents),0) AS tax_cents,COALESCE(SUM(i.total_cents),0) AS total_cents
        FROM invoice_corrections c JOIN invoices i ON i.id=c.correction_invoice_id
        WHERE c.original_invoice_id=? AND c.correction_type='CREDIT_NOTE' AND c.status='ACTIVE'`).bind(originalInvoice.id)
        .first<{ line_net_cents: number; tax_cents: number; total_cents: number }>();
      const cumulative = {
        line: Number(prior?.line_net_cents ?? 0) + calculated.lineNetCents,
        tax: Number(prior?.tax_cents ?? 0) + calculated.taxCents,
        total: Number(prior?.total_cents ?? 0) + calculated.totalCents,
      };
      if (Math.abs(cumulative.line) > originalInvoice.line_net_cents || Math.abs(cumulative.tax) > originalInvoice.tax_cents || Math.abs(cumulative.total) > originalInvoice.total_cents) {
        throw new RepositoryConflictError("The cumulative credit would exceed the original invoice value or VAT.");
      }
    }
  }
  const duplicate = await db.prepare("SELECT id FROM invoices WHERE supplier_taxpayer_id = ? AND source_system = ? AND source_document_id = ?")
    .bind(supplier.id, payload.source.system_id, payload.source.document_id).first<{ id: string }>();
  if (duplicate) throw new RepositoryConflictError(`Source document already exists as invoice ${duplicate.id}.`);
  // Module 2 Phase B: invoice_number must be unique per supplier — the
  // invoices table now enforces this (UNIQUE(supplier_taxpayer_id,
  // invoice_number)) as the backstop; this explicit check gives a clearer
  // error than the raw constraint violation for the common, non-racing case.
  const numberCollision = await db.prepare("SELECT id FROM invoices WHERE supplier_taxpayer_id = ? AND invoice_number = ?")
    .bind(supplier.id, payload.invoice_number.trim()).first<{ id: string }>();
  if (numberCollision) throw new RepositoryConflictError(`Invoice number ${payload.invoice_number.trim()} has already been used by this supplier.`);

  const now = new Date().toISOString();
  const invoiceId = crypto.randomUUID();
  const transactionId = crypto.randomUUID();
  const certificateId = crypto.randomUUID();
  const verificationToken = `vfy_${crypto.randomUUID().replaceAll("-", "")}`;
  const risk = scoreInvoice(payload, calculated, Boolean(customer));
  const status = risk.level === "HIGH" || risk.level === "CRITICAL" ? "EXCEPTION" : customer ? "MATCHED" : "CERTIFIED";
  const signature = `DEV.${await sha256Hex(`${requestHash}:${certificateId}:${now}`)}`;
  const period = payload.issue_date.slice(0, 7);
  const statements: D1PreparedStatement[] = [];

  statements.push(db.prepare(`INSERT INTO invoices VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(
    invoiceId, payload.invoice_number.trim(), payload.document_type, payload.source.system_id.trim(), payload.source.document_id.trim(),
    supplier.id, payload.supplier.name.trim(), supplierVat, customer?.id ?? null, payload.customer.name.trim(), customerVat,
    payload.issue_date, payload.currency, calculated.lineNetCents, calculated.taxCents, calculated.totalCents, status, risk.level,
    requestHash, transactionId, certificateId, verificationToken, now, now,
  ));

  if (originalInvoice) {
    const reference = payload.original_document_reference!;
    statements.push(db.prepare(`INSERT INTO invoice_corrections
      (id,original_invoice_id,correction_invoice_id,correction_type,reason_code,reason,status,created_by,created_at)
      VALUES (?,?,?,?,?,?,'ACTIVE',?,?)`).bind(crypto.randomUUID(), originalInvoice.id, invoiceId, payload.document_type, reference.reason_code ?? null, reference.reason!.trim(), actor.userId, now));
  }

  for (const line of calculated.lines) {
    statements.push(db.prepare(`INSERT INTO invoice_lines VALUES (?,?,?,?,?,?,?,?,?,?,?,?)`).bind(
      crypto.randomUUID(), invoiceId, line.line_number, line.description.trim(), line.quantity, line.unit_code,
      line.unitPriceCents, line.netAmountCents, line.taxRateBps, line.tax.category, line.taxAmountCents,
      vatRuleIdByLineNumber.get(line.line_number) ?? null,
    ));
  }
  statements.push(db.prepare("INSERT INTO certificates VALUES (?,?,?,?,?,?,?,?)").bind(
    certificateId, invoiceId, verificationToken, requestHash, signature, "DEV-SHA256", "VALID", now,
  ));
  // Module 2 Phase D: PostTransaction. transactionId already groups this
  // submission's ledger_entries; this row formalizes it as its own record
  // (VATTransaction) rather than only an implicit tag, and — for a
  // correction — links back to the original's transaction so
  // GetTransactionTimeline can walk the full lineage.
  statements.push(db.prepare("INSERT INTO vat_transactions VALUES (?,?,?,?,?,?)").bind(
    transactionId, invoiceId, supplier.id, originalInvoice ? "CORRECTION" : "CERTIFICATION", originalInvoice?.transaction_id ?? null, now,
  ));
  const reversesVat = payload.document_type === "CREDIT_NOTE";
  const ledgerVatCents = Math.abs(calculated.taxCents);
  statements.push(db.prepare("INSERT INTO ledger_entries VALUES (?,?,?,?,?,?,?,?,?)").bind(
    crypto.randomUUID(), transactionId, invoiceId, supplier.id, "OUTPUT_VAT", reversesVat ? "DEBIT" : "CREDIT", ledgerVatCents, period, now,
  ));
  if (customer) {
    statements.push(db.prepare("INSERT INTO ledger_entries VALUES (?,?,?,?,?,?,?,?,?)").bind(
      crypto.randomUUID(), transactionId, invoiceId, customer.id, "INPUT_VAT", reversesVat ? "CREDIT" : "DEBIT", ledgerVatCents, period, now,
    ));
  }

  let exceptionId: string | null = null;
  if (risk.reasons.length) {
    exceptionId = crypto.randomUUID();
    statements.push(db.prepare("INSERT INTO reconciliation_exceptions VALUES (?,?,?,?,?,?,?,?,NULL)").bind(
      exceptionId, invoiceId, supplier.id, customer ? "RISK_REVIEW" : "UNREGISTERED_BUYER",
      risk.level === "LOW" ? "MEDIUM" : risk.level, "OPEN", risk.reasons.join(" "), now,
    ));
  }

  const priorAudit = await db.prepare("SELECT event_hash FROM audit_events ORDER BY occurred_at DESC LIMIT 1").first<{ event_hash: string }>();
  const auditId = crypto.randomUUID();
  const auditDetails = JSON.stringify({
    invoiceNumber: payload.invoice_number,
    transactionId,
    certificateId,
    riskLevel: risk.level,
    exceptionId,
    correlationId: context.correlationId,
    deviceId: context.deviceId,
    sourceToken: context.sourceToken,
  });
  const auditHash = await sha256Hex(`${priorAudit?.event_hash ?? "GENESIS"}|${auditId}|${actor.userId}|${auditDetails}|${now}`);
  statements.push(db.prepare("INSERT INTO audit_events VALUES (?,?,?,?,?,?,?,?,?,?,?)").bind(
    auditId, actor.userId, actor.role, originalInvoice ? "INVOICE_CORRECTION_CERTIFIED" : "INVOICE_CERTIFIED", "INVOICE", invoiceId, "SUCCESS",
    auditDetails, priorAudit?.event_hash ?? null, auditHash, now,
  ));
  statements.push(db.prepare("INSERT INTO idempotency_records VALUES (?,?,?,?,?,?)").bind(
    crypto.randomUUID(), actor.userId, idempotencyKey, requestHash, invoiceId, now,
  ));
  statements.push(db.prepare("INSERT INTO outbox_events VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)").bind(
    crypto.randomUUID(), "INVOICE", invoiceId, originalInvoice ? "InvoiceCorrected" : "InvoiceCertified", 1, supplier.id,
    JSON.stringify({ invoice_id: invoiceId, transaction_id: transactionId, certificate_id: certificateId, ...(originalInvoice ? { original_invoice_id: originalInvoice.id, correction_type: payload.document_type } : {}), correlation_id: context.correlationId }),
    "PENDING", 0, now, now, null, null,
  ));
  if (risk.level === "HIGH" || risk.level === "CRITICAL") {
    statements.push(db.prepare("INSERT INTO security_events VALUES (?,?,?,?,?,?,?,?,?,?)").bind(
      crypto.randomUUID(), "HIGH_RISK_TRANSACTION", risk.level, actor.userId, context.sourceToken,
      context.correlationId, "INVOICE_SUBMISSION", "FLAGGED",
      JSON.stringify({ invoiceId, transactionId, taxpayerId: supplier.id, riskReasons: risk.reasons.length }), now,
    ));
  }

  try {
    await db.batch(statements);
  } catch (error) {
    // Module 2 Phase E: idempotency under concurrent retries. The SELECT-then-
    // INSERT check above is not itself atomic — two identical requests in
    // flight together can both pass it and both reach this batch. Whichever
    // commits second hits a UNIQUE constraint (idempotency_records, or the
    // invoices table's duplicate-source-document guard, whichever statement
    // in the batch executes first) and previously surfaced as a raw,
    // unhandled 500. Recover it into the same idempotent response the
    // earlier, non-racing case already returns, rather than letting the
    // constraint violation leak out as an opaque failure.
    const message = error instanceof Error ? error.message : String(error);
    if (!/unique constraint failed/i.test(message)) throw error;
    const race = await db.prepare("SELECT request_hash, response_invoice_id FROM idempotency_records WHERE actor_id = ? AND idempotency_key = ?")
      .bind(actor.userId, idempotencyKey).first<{ request_hash: string; response_invoice_id: string }>();
    if (race) {
      if (race.request_hash !== requestHash) throw new RepositoryConflictError("The idempotency key was already used for a different invoice payload.");
      const existing = await getInvoiceById(race.response_invoice_id, actor);
      if (existing) return existing;
    }
    // No idempotency record exists for this key, so the collision was on a
    // different constraint (most likely the invoices table's duplicate-
    // source-document guard) racing in under a different idempotency key —
    // the same conflict the earlier, non-racing "duplicate" check above
    // already reports for the non-concurrent case.
    throw new RepositoryConflictError("This invoice conflicts with one submitted concurrently for the same source document or invoice number.");
  }
  const created = await getInvoiceById(invoiceId, actor);
  if (!created) throw new Error("Invoice was committed but could not be reloaded.");
  return created;
}
