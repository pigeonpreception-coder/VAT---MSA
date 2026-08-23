import { ensureDatabase } from "@/db/runtime";
import {
  type AppliedTaxRule,
  calculateAndValidateInvoice,
  getVatNumber,
  InvoiceValidationError,
  scoreInvoice,
  sha256Hex,
  stableStringify,
} from "@/lib/domain/invoice";
import { signCertificationHash } from "@/lib/integrations/signing";
import type { InvoiceDetail, InvoiceSubmission, InvoiceSummary, RiskLevel, UserContext } from "@/lib/domain/types";
import { hasPermission, isNationalScope, requireTaxpayerScope } from "@/lib/auth";
import type { RequestContext } from "@/lib/security/request";

type InvoiceRow = {
  id: string; invoice_number: string; document_type: string; source_system: string; source_document_id: string;
  supplier_name: string; supplier_vat_number: string; customer_name: string; customer_vat_number: string | null;
  issue_date: string; currency: string; line_net_cents: number; tax_cents: number; total_cents: number;
  status: string; risk_level: RiskLevel; payload_hash: string; transaction_id: string; certificate_id: string;
  verification_token: string; certified_at: string; signature?: string; signature_profile?: string;
  tax_rule_set_id?: string; tax_rule_set_version?: string; tax_legal_authority_reference?: string;
  certification_hash?: string;
};

type TaxRuleRow = {
  id: string;
  jurisdiction: string;
  version: string;
  effective_from: string;
  effective_to: string | null;
  standard_rate_bps: number;
  legal_authority_reference: string | null;
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
    ? await db.prepare(`SELECT i.*, c.signature, c.signature_profile, c.invoice_hash AS certification_hash,
        r.version AS tax_rule_set_version, r.legal_authority_reference AS tax_legal_authority_reference
        FROM invoices i JOIN certificates c ON c.invoice_id = i.id JOIN tax_rule_sets r ON r.id = i.tax_rule_set_id WHERE i.id = ?`).bind(id).first<InvoiceRow>()
    : await db.prepare(`SELECT i.*, c.signature, c.signature_profile, c.invoice_hash AS certification_hash,
        r.version AS tax_rule_set_version, r.legal_authority_reference AS tax_legal_authority_reference
        FROM invoices i JOIN certificates c ON c.invoice_id = i.id JOIN tax_rule_sets r ON r.id = i.tax_rule_set_id
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
    certificationHash: row.certification_hash ?? "",
    signature: row.signature ?? "",
    signatureProfile: row.signature_profile ?? "",
    taxRuleSetId: row.tax_rule_set_id ?? "",
    taxRuleSetVersion: row.tax_rule_set_version ?? "",
    taxLegalAuthorityReference: row.tax_legal_authority_reference ?? "",
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

async function resolveApprovedNamibiaTaxRule(db: D1Database, issueDate: string): Promise<AppliedTaxRule> {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(issueDate)) {
    throw new InvoiceValidationError([{ code: "ISSUE_DATE_INVALID", path: "/issue_date", message: "Issue date must use YYYY-MM-DD." }]);
  }

  const result = await db.prepare(`SELECT id,jurisdiction,version,effective_from,effective_to,standard_rate_bps,legal_authority_reference
    FROM tax_rule_sets
    WHERE jurisdiction='NA' AND status='AUTHORITY_APPROVED'
      AND effective_from<=? AND (effective_to IS NULL OR effective_to>=?)
    ORDER BY effective_from DESC LIMIT 2`).bind(issueDate, issueDate).all<TaxRuleRow>();
  if (result.results.length === 0) {
    throw new InvoiceValidationError([{ code: "APPROVED_TAX_RULE_NOT_AVAILABLE", path: "/issue_date", message: "No authority-approved Namibia VAT rule is effective for the invoice date." }]);
  }
  if (result.results.length > 1) {
    throw new InvoiceValidationError([{ code: "AMBIGUOUS_APPROVED_TAX_RULE", path: "/issue_date", message: "More than one authority-approved Namibia VAT rule is effective for the invoice date." }]);
  }
  const row = result.results[0];
  if (row.jurisdiction !== "NA" || !row.legal_authority_reference?.trim()) {
    throw new InvoiceValidationError([{ code: "APPROVED_TAX_RULE_INCOMPLETE", path: "/issue_date", message: "The effective Namibia VAT rule lacks an approved legal-authority reference." }]);
  }
  return {
    id: row.id,
    jurisdiction: "NA",
    version: row.version,
    effectiveFrom: row.effective_from,
    effectiveTo: row.effective_to,
    standardRateBps: row.standard_rate_bps,
    legalAuthorityReference: row.legal_authority_reference,
  };
}

export async function submitInvoice(payload: InvoiceSubmission, actor: UserContext, idempotencyKey: string, context: RequestContext): Promise<InvoiceDetail> {
  if (idempotencyKey.length < 16 || idempotencyKey.length > 128) {
    throw new InvoiceValidationError([{ code: "IDEMPOTENCY_KEY_INVALID", path: "/headers/idempotency-key", message: "Idempotency key must contain 16 to 128 characters." }]);
  }
  const db = await ensureDatabase();
  const taxRule = await resolveApprovedNamibiaTaxRule(db, payload.issue_date);
  const calculated = calculateAndValidateInvoice(payload, taxRule);
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
  const isCorrection = payload.document_type === "CREDIT_NOTE" || payload.document_type === "DEBIT_NOTE";
  let originalInvoice: CorrectionOriginal | null = null;
  if (isCorrection) {
    const reference = payload.original_document_reference!;
    if (reference.vat_msa_invoice_id) {
      originalInvoice = await db.prepare(`SELECT id,invoice_number,document_type,source_document_id,customer_taxpayer_id,customer_vat_number,
        issue_date,currency,line_net_cents,tax_cents,total_cents FROM invoices WHERE id=? AND supplier_taxpayer_id=?`)
        .bind(reference.vat_msa_invoice_id, supplier.id).first<CorrectionOriginal>();
      if (originalInvoice && originalInvoice.source_document_id !== reference.source_document_id) {
        throw new RepositoryConflictError("The correction's VAT-MSA invoice id and source document reference do not identify the same original invoice.");
      }
    } else {
      const candidates = await db.prepare(`SELECT id,invoice_number,document_type,source_document_id,customer_taxpayer_id,customer_vat_number,
        issue_date,currency,line_net_cents,tax_cents,total_cents FROM invoices WHERE source_document_id=? AND supplier_taxpayer_id=? LIMIT 2`)
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

  const now = new Date().toISOString();
  const invoiceId = crypto.randomUUID();
  const transactionId = crypto.randomUUID();
  const certificateId = crypto.randomUUID();
  const verificationToken = `vfy_${crypto.randomUUID().replaceAll("-", "")}`;
  const risk = scoreInvoice(payload, calculated, Boolean(customer));
  const status = risk.level === "HIGH" || risk.level === "CRITICAL" ? "EXCEPTION" : customer ? "MATCHED" : "CERTIFIED";
  const certificationHash = await sha256Hex(stableStringify({
    invoicePayloadHash: requestHash,
    taxRule: {
      id: taxRule.id,
      version: taxRule.version,
      standardRateBps: taxRule.standardRateBps,
      legalAuthorityReference: taxRule.legalAuthorityReference,
    },
  }));
  const certificateSignature = await signCertificationHash(certificationHash);
  const period = payload.issue_date.slice(0, 7);
  const statements: D1PreparedStatement[] = [];

  statements.push(db.prepare(`INSERT INTO invoices
    (id,invoice_number,document_type,source_system,source_document_id,supplier_taxpayer_id,supplier_name,supplier_vat_number,
     customer_taxpayer_id,customer_name,customer_vat_number,issue_date,currency,line_net_cents,tax_cents,total_cents,status,risk_level,
     payload_hash,transaction_id,certificate_id,verification_token,tax_rule_set_id,created_at,certified_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(
    invoiceId, payload.invoice_number.trim(), payload.document_type, payload.source.system_id.trim(), payload.source.document_id.trim(),
    supplier.id, payload.supplier.name.trim(), supplierVat, customer?.id ?? null, payload.customer.name.trim(), customerVat,
    payload.issue_date, payload.currency, calculated.lineNetCents, calculated.taxCents, calculated.totalCents, status, risk.level,
    requestHash, transactionId, certificateId, verificationToken, taxRule.id, now, now,
  ));

  if (originalInvoice) {
    const reference = payload.original_document_reference!;
    statements.push(db.prepare(`INSERT INTO invoice_corrections
      (id,original_invoice_id,correction_invoice_id,correction_type,reason_code,reason,status,created_by,created_at)
      VALUES (?,?,?,?,?,?,'ACTIVE',?,?)`).bind(crypto.randomUUID(), originalInvoice.id, invoiceId, payload.document_type, reference.reason_code ?? null, reference.reason!.trim(), actor.userId, now));
  }

  for (const line of calculated.lines) {
    statements.push(db.prepare(`INSERT INTO invoice_lines VALUES (?,?,?,?,?,?,?,?,?,?,?)`).bind(
      crypto.randomUUID(), invoiceId, line.line_number, line.description.trim(), line.quantity, line.unit_code,
      line.unitPriceCents, line.netAmountCents, line.taxRateBps, line.tax.category, line.taxAmountCents,
    ));
  }
  statements.push(db.prepare(`INSERT INTO certificates
    (id,invoice_id,verification_token,invoice_hash,signature,signature_profile,rule_set_version,status,issued_at)
    VALUES (?,?,?,?,?,?,?,?,?)`).bind(
    certificateId, invoiceId, verificationToken, certificationHash, certificateSignature.signature, certificateSignature.profile, taxRule.version, "VALID", now,
  ));
  const reversesVat = payload.document_type === "CREDIT_NOTE";
  const ledgerVatCents = Math.abs(calculated.taxCents);
  statements.push(db.prepare("INSERT INTO ledger_entries VALUES (?,?,?,?,?,?,?,?,?)").bind(
    crypto.randomUUID(), transactionId, invoiceId, supplier.id, "OUTPUT_VAT", reversesVat ? "DEBIT" : "CREDIT", ledgerVatCents, period, now,
  ));
  statements.push(db.prepare(`INSERT INTO vat_returns VALUES (?,?,?,?,?,?,?,?)
    ON CONFLICT(taxpayer_id, period) DO UPDATE SET output_tax_cents = output_tax_cents + excluded.output_tax_cents,
    net_payable_cents = net_payable_cents + excluded.output_tax_cents, last_calculated_at = excluded.last_calculated_at`).bind(
      crypto.randomUUID(), supplier.id, period, calculated.taxCents, 0, calculated.taxCents, "DRAFT", now,
  ));
  if (customer) {
    statements.push(db.prepare("INSERT INTO ledger_entries VALUES (?,?,?,?,?,?,?,?,?)").bind(
      crypto.randomUUID(), transactionId, invoiceId, customer.id, "INPUT_VAT", reversesVat ? "CREDIT" : "DEBIT", ledgerVatCents, period, now,
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
    taxRuleSetId: taxRule.id,
    taxRuleSetVersion: taxRule.version,
    certificationHash,
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
    JSON.stringify({ invoice_id: invoiceId, transaction_id: transactionId, certificate_id: certificateId, tax_rule_set_id: taxRule.id, tax_rule_set_version: taxRule.version, certification_hash: certificationHash, ...(originalInvoice ? { original_invoice_id: originalInvoice.id, correction_type: payload.document_type } : {}), correlation_id: context.correlationId }),
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
