import { ensureDatabase } from "@/db/runtime";
import {
  calculateAndValidateInvoice,
  getVatNumber,
  InvoiceValidationError,
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

  const [lineResult, ledgerResult] = await Promise.all([
    db.prepare("SELECT * FROM invoice_lines WHERE invoice_id = ? ORDER BY line_number").bind(id).all<{
      id: string; line_number: number; description: string; quantity: string; unit_code: string; unit_price_cents: number;
      net_amount_cents: number; tax_rate_bps: number; tax_category: string; tax_amount_cents: number;
    }>(),
    db.prepare(`SELECT l.*, t.legal_name AS taxpayer_name FROM ledger_entries l JOIN taxpayers t ON t.id = l.taxpayer_id WHERE l.invoice_id = ? ORDER BY l.entry_type DESC`).bind(id).all<{
      id: string; taxpayer_name: string; entry_type: string; direction: string; amount_cents: number; period: string;
    }>(),
  ]);

  return {
    ...mapInvoice(row),
    sourceSystem: row.source_system,
    sourceDocumentId: row.source_document_id,
    payloadHash: row.payload_hash,
    signature: row.signature ?? "",
    signatureProfile: row.signature_profile ?? "",
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

export async function listReturns(user: UserContext) {
  const db = await ensureDatabase();
  const result = isNationalScope(user)
    ? await db.prepare(`SELECT r.*, t.legal_name, t.vat_number FROM vat_returns r JOIN taxpayers t ON t.id = r.taxpayer_id ORDER BY r.period DESC, t.legal_name`).all<Record<string, string | number | null>>()
    : await db.prepare(`SELECT r.*, t.legal_name, t.vat_number FROM vat_returns r JOIN taxpayers t ON t.id = r.taxpayer_id WHERE r.taxpayer_id = ? ORDER BY r.period DESC, t.legal_name`)
      .bind(user.taxpayerId ?? "__none__").all<Record<string, string | number | null>>();
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

export async function getPublicVerification(token: string) {
  const db = await ensureDatabase();
  return db.prepare(`SELECT c.status AS certificate_status, c.issued_at, c.invoice_hash, c.signature_profile,
    i.supplier_name, i.invoice_number, i.total_cents, i.currency
    FROM certificates c JOIN invoices i ON i.id = c.invoice_id WHERE c.verification_token = ?`).bind(token).first<{
      certificate_status: string; issued_at: string; invoice_hash: string; signature_profile: string;
      supplier_name: string; invoice_number: string; total_cents: number; currency: string;
    }>();
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
  const duplicate = await db.prepare("SELECT id FROM invoices WHERE supplier_taxpayer_id = ? AND source_system = ? AND source_document_id = ?")
    .bind(supplier.id, payload.source.system_id, payload.source.document_id).first<{ id: string }>();
  if (duplicate) throw new RepositoryConflictError(`Source document already exists as invoice ${duplicate.id}.`);

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

  for (const line of calculated.lines) {
    statements.push(db.prepare(`INSERT INTO invoice_lines VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind(
      crypto.randomUUID(), invoiceId, line.line_number, line.description.trim(), line.quantity, line.unit_code,
      line.unitPriceCents, line.netAmountCents, line.taxRateBps, line.tax.category, line.taxAmountCents,
    ));
  }
  statements.push(db.prepare("INSERT INTO certificates VALUES (?,?,?,?,?,?,?,?)").bind(
    certificateId, invoiceId, verificationToken, requestHash, signature, "DEV-SHA256", "VALID", now,
  ));
  statements.push(db.prepare("INSERT INTO ledger_entries VALUES (?,?,?,?,?,?,?,?,?)").bind(
    crypto.randomUUID(), transactionId, invoiceId, supplier.id, "OUTPUT_VAT", "CREDIT", calculated.taxCents, period, now,
  ));
  statements.push(db.prepare(`INSERT INTO vat_returns VALUES (?,?,?,?,?,?,?,?)
    ON CONFLICT(taxpayer_id, period) DO UPDATE SET output_tax_cents = output_tax_cents + excluded.output_tax_cents,
    net_payable_cents = net_payable_cents + excluded.output_tax_cents, last_calculated_at = excluded.last_calculated_at`).bind(
      crypto.randomUUID(), supplier.id, period, calculated.taxCents, 0, calculated.taxCents, "DRAFT", now,
  ));
  if (customer) {
    statements.push(db.prepare("INSERT INTO ledger_entries VALUES (?,?,?,?,?,?,?,?,?)").bind(
      crypto.randomUUID(), transactionId, invoiceId, customer.id, "INPUT_VAT", "DEBIT", calculated.taxCents, period, now,
    ));
    statements.push(db.prepare(`INSERT INTO vat_returns VALUES (?,?,?,?,?,?,?,?)
      ON CONFLICT(taxpayer_id, period) DO UPDATE SET input_tax_cents = input_tax_cents + excluded.input_tax_cents,
      net_payable_cents = net_payable_cents - excluded.input_tax_cents, last_calculated_at = excluded.last_calculated_at`).bind(
        crypto.randomUUID(), customer.id, period, 0, calculated.taxCents, -calculated.taxCents, "DRAFT", now,
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
    auditId, actor.userId, actor.role, "INVOICE_CERTIFIED", "INVOICE", invoiceId, "SUCCESS",
    auditDetails, priorAudit?.event_hash ?? null, auditHash, now,
  ));
  statements.push(db.prepare("INSERT INTO idempotency_records VALUES (?,?,?,?,?,?)").bind(
    crypto.randomUUID(), actor.userId, idempotencyKey, requestHash, invoiceId, now,
  ));
  statements.push(db.prepare("INSERT INTO outbox_events VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)").bind(
    crypto.randomUUID(), "INVOICE", invoiceId, "InvoiceCertified", 1, supplier.id,
    JSON.stringify({ invoice_id: invoiceId, transaction_id: transactionId, certificate_id: certificateId, correlation_id: context.correlationId }),
    "PENDING", 0, now, now, null, null,
  ));
  if (risk.level === "HIGH" || risk.level === "CRITICAL") {
    statements.push(db.prepare("INSERT INTO security_events VALUES (?,?,?,?,?,?,?,?,?,?)").bind(
      crypto.randomUUID(), "HIGH_RISK_TRANSACTION", risk.level, actor.userId, context.sourceToken,
      context.correlationId, "INVOICE_SUBMISSION", "FLAGGED",
      JSON.stringify({ invoiceId, transactionId, taxpayerId: supplier.id, riskReasons: risk.reasons.length }), now,
    ));
  }

  await db.batch(statements);
  const created = await getInvoiceById(invoiceId, actor);
  if (!created) throw new Error("Invoice was committed but could not be reloaded.");
  return created;
}
