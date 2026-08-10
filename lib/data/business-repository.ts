import { ensureDatabase } from "@/db/runtime";
import { AccessDeniedError, isNationalScope } from "@/lib/auth";
import {
  normalizeAndValidateExpense,
  normalizeAndValidateJournal,
  normalizeAndValidateProject,
  normalizeAndValidateQuotation,
  normalizeAndValidateQuotationConversion,
  normalizeAndValidateStockMovement,
  type ExpenseSubmission,
  type JournalSubmission,
  type ProjectSubmission,
  type QuotationSubmission,
  type QuotationConversionSubmission,
  type StockMovementSubmission,
} from "@/lib/domain/business";
import { centsToDecimal, sha256Hex, stableStringify } from "@/lib/domain/invoice";
import type { InvoiceSubmission, UserContext } from "@/lib/domain/types";
import type { RequestContext } from "@/lib/security/request";
import { getInvoiceById, RepositoryConflictError, submitInvoice } from "./repository";

type OrganisationContext = { id: string; taxpayer_id: string; legal_name: string; vat_number: string };
type IdempotencyRow = { request_hash: string; resource_id: string };

type ConvertibleQuotation = {
  id: string;
  organisation_id: string;
  customer_party_id: string;
  quotation_number: string;
  currency: string;
  issue_date: string;
  status: string;
  subtotal_cents: number;
  tax_cents: number;
  total_cents: number;
  notes: string | null;
  accepted_at: string | null;
  converted_invoice_id: string | null;
  customer_name: string;
  customer_vat_number: string | null;
  customer_tin: string | null;
  supplier_name: string;
  supplier_vat_number: string;
};

type ConvertibleQuotationLine = {
  line_number: number;
  product_id: string | null;
  description: string;
  quantity_micros: number;
  unit_code: string;
  unit_price_cents: number;
  net_amount_cents: number;
  tax_category: "STANDARD" | "ZERO_RATED" | "EXEMPT" | "OUT_OF_SCOPE";
  tax_rate_bps: number;
  tax_amount_cents: number;
};

export class BusinessResourceError extends Error {
  readonly status: number;

  constructor(message: string, status = 422) {
    super(message);
    this.name = "BusinessResourceError";
    this.status = status;
  }
}

async function resolveOrganisation(user: UserContext, requestedOrganisationId?: string | null): Promise<OrganisationContext> {
  const db = await ensureDatabase();
  if (isNationalScope(user)) {
    const row = requestedOrganisationId
      ? await db.prepare(`SELECT o.id,o.taxpayer_id,o.legal_name,t.vat_number FROM organisations o
          JOIN taxpayers t ON t.id=o.taxpayer_id WHERE o.id=? AND o.status='ACTIVE'`).bind(requestedOrganisationId).first<OrganisationContext>()
      : await db.prepare(`SELECT o.id,o.taxpayer_id,o.legal_name,t.vat_number FROM organisations o
          JOIN taxpayers t ON t.id=o.taxpayer_id WHERE o.status='ACTIVE' ORDER BY o.id LIMIT 1`).first<OrganisationContext>();
    if (!row) throw new BusinessResourceError("No active organisation is available in the requested scope.", 404);
    return row;
  }
  const row = await db.prepare(`SELECT o.id,o.taxpayer_id,o.legal_name,t.vat_number FROM organisations o
    JOIN taxpayers t ON t.id=o.taxpayer_id WHERE o.taxpayer_id=? AND o.status='ACTIVE' LIMIT 1`)
    .bind(user.taxpayerId ?? "__none__").first<OrganisationContext>();
  if (!row) throw new AccessDeniedError("Your account is not assigned to an active taxpayer organisation.");
  if (requestedOrganisationId && requestedOrganisationId !== row.id) throw new AccessDeniedError("The requested organisation is outside your authorised scope.");
  return row;
}

function validateIdempotencyKey(key: string) {
  if (key.length < 16 || key.length > 128) throw new BusinessResourceError("Idempotency-Key must contain 16 to 128 characters.");
}

function microsToDecimal(micros: number): string {
  const whole = Math.floor(micros / 1_000_000);
  const fraction = String(micros % 1_000_000).padStart(6, "0").replace(/0+$/, "");
  return fraction ? `${whole}.${fraction}` : String(whole);
}

async function priorCommand(db: D1Database, actorId: string, type: string, key: string, hash: string): Promise<string | null> {
  const prior = await db.prepare(`SELECT request_hash,resource_id FROM command_idempotency
    WHERE actor_id=? AND command_type=? AND idempotency_key=?`).bind(actorId, type, key).first<IdempotencyRow>();
  if (!prior) return null;
  if (prior.request_hash !== hash) throw new RepositoryConflictError("The idempotency key was already used for a different command payload.");
  return prior.resource_id;
}

async function auditEnvelope(db: D1Database, actor: UserContext, action: string, resourceType: string, resourceId: string, details: Record<string, unknown>, now: string) {
  const id = crypto.randomUUID();
  const prior = await db.prepare("SELECT event_hash FROM audit_events ORDER BY occurred_at DESC LIMIT 1").first<{ event_hash: string }>();
  const body = JSON.stringify(details);
  const hash = await sha256Hex(`${prior?.event_hash ?? "GENESIS"}|${id}|${actor.userId}|${body}|${now}`);
  return { id, action, resourceType, resourceId, body, previousHash: prior?.event_hash ?? null, hash };
}

function commandRecord(db: D1Database, actorId: string, type: string, key: string, hash: string, resourceType: string, resourceId: string, now: string) {
  return db.prepare(`INSERT INTO command_idempotency
    (id,actor_id,command_type,idempotency_key,request_hash,resource_type,resource_id,created_at)
    VALUES (?,?,?,?,?,?,?,?)`).bind(crypto.randomUUID(), actorId, type, key, hash, resourceType, resourceId, now);
}

function auditRecord(db: D1Database, actor: UserContext, audit: Awaited<ReturnType<typeof auditEnvelope>>, now: string) {
  return db.prepare("INSERT INTO audit_events VALUES (?,?,?,?,?,?,?,?,?,?,?)").bind(
    audit.id, actor.userId, actor.role, audit.action, audit.resourceType, audit.resourceId,
    "SUCCESS", audit.body, audit.previousHash, audit.hash, now,
  );
}

function outboxRecord(db: D1Database, aggregateType: string, aggregateId: string, eventType: string, partitionKey: string, payload: Record<string, unknown>, now: string) {
  return db.prepare(`INSERT INTO outbox_events
    (id,aggregate_type,aggregate_id,event_type,event_version,partition_key,payload,status,publish_attempts,occurred_at,available_at,published_at,last_error)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(
    crypto.randomUUID(), aggregateType, aggregateId, eventType, 1, partitionKey,
    JSON.stringify(payload), "PENDING", 0, now, now, null, null,
  );
}

async function requireOwnedReference(db: D1Database, table: string, id: string | undefined, organisationId: string, label: string) {
  if (!id) return;
  const allowedTables = new Set(["business_parties", "branches", "products", "warehouses", "projects", "expense_categories", "chart_of_accounts"]);
  if (!allowedTables.has(table)) throw new Error("Unsafe reference table.");
  const row = await db.prepare(`SELECT id FROM ${table} WHERE id=? AND organisation_id=?`).bind(id, organisationId).first<{ id: string }>();
  if (!row) throw new BusinessResourceError(`${label} does not exist in the authorised organisation.`);
}

export async function getBusinessPlatformSnapshot(user: UserContext, requestedOrganisationId?: string | null) {
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(user, requestedOrganisationId);
  const org = organisation.id;
  const [metrics, parties, products, quotations, accounts, journals, expenses, balances, projects, imports, categories, warehouses] = await Promise.all([
    db.prepare(`SELECT
      (SELECT COUNT(*) FROM business_parties WHERE organisation_id=? AND status='ACTIVE') AS parties,
      (SELECT COUNT(*) FROM quotations WHERE organisation_id=?) AS quotations,
      (SELECT COUNT(*) FROM expenses WHERE organisation_id=?) AS expenses,
      (SELECT COUNT(*) FROM projects WHERE organisation_id=? AND status IN ('PLANNED','ACTIVE')) AS projects,
      (SELECT COALESCE(SUM(total_cents),0) FROM quotations WHERE organisation_id=? AND status IN ('ISSUED','ACCEPTED','CONVERTED')) AS quoted_value_cents,
      (SELECT COALESCE(SUM(total_cents),0) FROM expenses WHERE organisation_id=? AND status<>'VOID') AS expense_value_cents`)
      .bind(org, org, org, org, org, org).first<Record<string, number>>(),
    db.prepare(`SELECT p.*,GROUP_CONCAT(r.relationship, ',') AS relationships FROM business_parties p
      LEFT JOIN party_relationships r ON r.party_id=p.id AND r.status='ACTIVE'
      WHERE p.organisation_id=? GROUP BY p.id ORDER BY p.display_name LIMIT 100`).bind(org).all<Record<string, string | null>>(),
    db.prepare("SELECT * FROM products WHERE organisation_id=? ORDER BY name LIMIT 100").bind(org).all<Record<string, string | number | null>>(),
    db.prepare(`SELECT q.*,p.display_name AS customer_name FROM quotations q JOIN business_parties p ON p.id=q.customer_party_id
      WHERE q.organisation_id=? ORDER BY q.issue_date DESC,q.created_at DESC LIMIT 100`).bind(org).all<Record<string, string | number | null>>(),
    db.prepare("SELECT * FROM chart_of_accounts WHERE organisation_id=? ORDER BY code LIMIT 200").bind(org).all<Record<string, string | null>>(),
    db.prepare("SELECT * FROM journal_entries WHERE organisation_id=? ORDER BY journal_date DESC,created_at DESC LIMIT 100").bind(org).all<Record<string, string | null>>(),
    db.prepare(`SELECT e.*,c.name AS category_name,p.display_name AS supplier_name FROM expenses e
      JOIN expense_categories c ON c.id=e.category_id LEFT JOIN business_parties p ON p.id=e.supplier_party_id
      WHERE e.organisation_id=? ORDER BY e.expense_date DESC,e.created_at DESC LIMIT 100`).bind(org).all<Record<string, string | number | null>>(),
    db.prepare(`SELECT b.*,w.name AS warehouse_name,p.sku,p.name AS product_name FROM inventory_balances b
      JOIN warehouses w ON w.id=b.warehouse_id JOIN products p ON p.id=b.product_id
      WHERE b.organisation_id=? ORDER BY w.name,p.name LIMIT 200`).bind(org).all<Record<string, string | number | null>>(),
    db.prepare(`SELECT p.*,bp.display_name AS customer_name,
      COALESCE((SELECT SUM(amount_cents) FROM project_budgets b WHERE b.project_id=p.id AND b.status<>'VOID'),0) AS budget_cents,
      COALESCE((SELECT SUM(amount_cents) FROM project_costs c WHERE c.project_id=p.id),0) AS cost_cents
      FROM projects p LEFT JOIN business_parties bp ON bp.id=p.customer_party_id
      WHERE p.organisation_id=? ORDER BY p.start_date DESC LIMIT 100`).bind(org).all<Record<string, string | number | null>>(),
    db.prepare("SELECT * FROM import_records WHERE organisation_id=? ORDER BY declaration_date DESC LIMIT 100").bind(org).all<Record<string, string | number | null>>(),
    db.prepare("SELECT * FROM expense_categories WHERE organisation_id=? AND status='ACTIVE' ORDER BY name").bind(org).all<Record<string, string | number>>(),
    db.prepare("SELECT * FROM warehouses WHERE organisation_id=? AND status='ACTIVE' ORDER BY name").bind(org).all<Record<string, string | number>>(),
  ]);
  return { organisation, metrics: metrics ?? {}, parties: parties.results, products: products.results, quotations: quotations.results, accounts: accounts.results, journals: journals.results, expenses: expenses.results, balances: balances.results, projects: projects.results, imports: imports.results, categories: categories.results, warehouses: warehouses.results };
}

export async function createQuotation(payload: QuotationSubmission, actor: UserContext, idempotencyKey: string, correlationId: string, requestedOrganisationId?: string | null) {
  validateIdempotencyKey(idempotencyKey);
  const quotation = normalizeAndValidateQuotation(payload);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const requestHash = await sha256Hex(stableStringify({ organisation_id: organisation.id, quotation }));
  const prior = await priorCommand(db, actor.userId, "CREATE_QUOTATION", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM quotations WHERE id=? AND organisation_id=?").bind(prior, organisation.id).first<Record<string, unknown>>();
  await requireOwnedReference(db, "business_parties", quotation.customer_party_id, organisation.id, "Customer party");
  await requireOwnedReference(db, "branches", quotation.branch_id, organisation.id, "Branch");
  for (const line of quotation.lines) await requireOwnedReference(db, "products", line.product_id, organisation.id, "Product");
  const duplicate = await db.prepare("SELECT id FROM quotations WHERE organisation_id=? AND quotation_number=?").bind(organisation.id, quotation.quotation_number).first<{ id: string }>();
  if (duplicate) throw new RepositoryConflictError(`Quotation number already exists as ${duplicate.id}.`);
  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  const audit = await auditEnvelope(db, actor, "QUOTATION_ISSUED", "QUOTATION", id, { organisationId: organisation.id, quotationNumber: quotation.quotation_number, totalCents: quotation.total_cents, correlationId }, now);
  const statements: D1PreparedStatement[] = [
    db.prepare(`INSERT INTO quotations
      (id,organisation_id,branch_id,customer_party_id,quotation_number,currency,issue_date,valid_until,status,subtotal_cents,tax_cents,total_cents,notes,created_by,approved_by,accepted_at,converted_invoice_id,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,NULL,NULL,?,?)`).bind(id, organisation.id, quotation.branch_id ?? null, quotation.customer_party_id, quotation.quotation_number, quotation.currency, quotation.issue_date, quotation.valid_until, "ISSUED", quotation.subtotal_cents, quotation.tax_cents, quotation.total_cents, quotation.notes ?? null, actor.userId, now, now),
  ];
  for (const line of quotation.lines) statements.push(db.prepare(`INSERT INTO quotation_lines
    (id,quotation_id,line_number,product_id,description,quantity_micros,unit_code,unit_price_cents,net_amount_cents,tax_category,tax_rate_bps,tax_amount_cents)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)`).bind(crypto.randomUUID(), id, line.line_number, line.product_id ?? null, line.description, line.quantity_micros, line.unit_code, line.unit_price_cents, line.net_amount_cents, line.tax_category, line.tax_rate_bps, line.tax_amount_cents));
  statements.push(commandRecord(db, actor.userId, "CREATE_QUOTATION", idempotencyKey, requestHash, "QUOTATION", id, now));
  statements.push(outboxRecord(db, "QUOTATION", id, "QuotationIssued", organisation.id, { quotation_id: id, organisation_id: organisation.id, total_cents: quotation.total_cents, correlation_id: correlationId }, now));
  statements.push(auditRecord(db, actor, audit, now));
  await db.batch(statements);
  return db.prepare("SELECT * FROM quotations WHERE id=?").bind(id).first<Record<string, unknown>>();
}

export async function acceptQuotation(id: string, actor: UserContext, idempotencyKey: string, correlationId: string, requestedOrganisationId?: string | null) {
  validateIdempotencyKey(idempotencyKey);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const requestHash = await sha256Hex(stableStringify({ organisation_id: organisation.id, quotation_id: id, action: "ACCEPT" }));
  const prior = await priorCommand(db, actor.userId, "ACCEPT_QUOTATION", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM quotations WHERE id=? AND organisation_id=?").bind(prior, organisation.id).first<Record<string, unknown>>();
  const quotation = await db.prepare("SELECT id,status,valid_until FROM quotations WHERE id=? AND organisation_id=?").bind(id, organisation.id).first<{ id: string; status: string; valid_until: string }>();
  if (!quotation) throw new BusinessResourceError("Quotation was not found in the authorised organisation.", 404);
  if (quotation.status !== "ISSUED") throw new RepositoryConflictError(`Only an issued quotation can be accepted; current status is ${quotation.status}.`);
  const today = new Date().toISOString().slice(0, 10);
  if (quotation.valid_until < today) throw new RepositoryConflictError("The quotation has expired and cannot be accepted.");
  const now = new Date().toISOString();
  const audit = await auditEnvelope(db, actor, "QUOTATION_ACCEPTED", "QUOTATION", id, { organisationId: organisation.id, correlationId }, now);
  await db.batch([
    db.prepare("UPDATE quotations SET status='ACCEPTED',accepted_at=?,updated_at=? WHERE id=? AND organisation_id=? AND status='ISSUED'").bind(now, now, id, organisation.id),
    commandRecord(db, actor.userId, "ACCEPT_QUOTATION", idempotencyKey, requestHash, "QUOTATION", id, now),
    outboxRecord(db, "QUOTATION", id, "QuotationAccepted", organisation.id, { quotation_id: id, organisation_id: organisation.id, correlation_id: correlationId }, now),
    auditRecord(db, actor, audit, now),
  ]);
  return db.prepare("SELECT * FROM quotations WHERE id=?").bind(id).first<Record<string, unknown>>();
}

export async function convertQuotationToInvoice(
  id: string,
  payload: QuotationConversionSubmission,
  actor: UserContext,
  idempotencyKey: string,
  context: RequestContext,
  requestedOrganisationId?: string | null,
) {
  validateIdempotencyKey(idempotencyKey);
  const conversion = normalizeAndValidateQuotationConversion(payload);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const requestHash = await sha256Hex(stableStringify({ organisation_id: organisation.id, quotation_id: id, conversion }));
  const prior = await priorCommand(db, actor.userId, "CONVERT_QUOTATION", idempotencyKey, requestHash);
  if (prior) {
    const existing = await getInvoiceById(prior, actor);
    if (!existing) throw new RepositoryConflictError("The prior quotation-conversion response is unavailable.");
    return existing;
  }

  const quotation = await db.prepare(`SELECT q.id,q.organisation_id,q.customer_party_id,q.quotation_number,q.currency,q.issue_date,
    q.status,q.subtotal_cents,q.tax_cents,q.total_cents,q.notes,q.accepted_at,q.converted_invoice_id,
    p.display_name AS customer_name,p.vat_number AS customer_vat_number,p.tin AS customer_tin,
    t.legal_name AS supplier_name,t.vat_number AS supplier_vat_number
    FROM quotations q
    JOIN business_parties p ON p.id=q.customer_party_id AND p.organisation_id=q.organisation_id
    JOIN organisations o ON o.id=q.organisation_id
    JOIN taxpayers t ON t.id=o.taxpayer_id
    WHERE q.id=? AND q.organisation_id=?`).bind(id, organisation.id).first<ConvertibleQuotation>();
  if (!quotation) throw new BusinessResourceError("Quotation was not found in the authorised organisation.", 404);
  if (quotation.converted_invoice_id) {
    const existing = await getInvoiceById(quotation.converted_invoice_id, actor);
    if (!existing) throw new RepositoryConflictError("The converted invoice is no longer available.");
    if (existing.invoiceNumber !== conversion.invoice_number || existing.issueDate !== conversion.issue_date) {
      throw new RepositoryConflictError(`Quotation was already converted to invoice ${existing.invoiceNumber}.`);
    }
    return existing;
  }
  if (quotation.status !== "ACCEPTED") throw new RepositoryConflictError(`Only an accepted quotation can be converted; current status is ${quotation.status}.`);
  if (conversion.issue_date < quotation.issue_date) throw new RepositoryConflictError("The invoice cannot be issued before the quotation.");

  const lineResult = await db.prepare(`SELECT line_number,product_id,description,quantity_micros,unit_code,unit_price_cents,
    net_amount_cents,tax_category,tax_rate_bps,tax_amount_cents FROM quotation_lines WHERE quotation_id=? ORDER BY line_number`)
    .bind(quotation.id).all<ConvertibleQuotationLine>();
  if (!lineResult.results.length) throw new RepositoryConflictError("The quotation has no lines and cannot be converted.");
  const customerIdentifier: InvoiceSubmission["customer"]["identifiers"][number] = quotation.customer_vat_number
    ? { type: "VAT_NUMBER", value: quotation.customer_vat_number, country: "NA" }
    : quotation.customer_tin
      ? { type: "TIN", value: quotation.customer_tin, country: "NA" }
      : { type: "OTHER", value: quotation.customer_party_id, country: "NA" };
  const submittedAt = quotation.accepted_at ?? `${quotation.issue_date}T00:00:00.000Z`;
  const invoicePayload: InvoiceSubmission = {
    schema_version: "1.0.0",
    document_type: "TAX_INVOICE",
    source: { system_id: "VAT-MSA-QUOTATION", document_id: quotation.id, submitted_at: submittedAt },
    supplier: { name: quotation.supplier_name, identifiers: [{ type: "VAT_NUMBER", value: quotation.supplier_vat_number, country: "NA" }] },
    customer: { name: quotation.customer_name, identifiers: [customerIdentifier] },
    invoice_number: conversion.invoice_number,
    issue_date: conversion.issue_date,
    ...(conversion.due_date ? { due_date: conversion.due_date } : {}),
    currency: quotation.currency,
    lines: lineResult.results.map((line) => ({
      line_number: line.line_number,
      ...(line.product_id ? { item_code: line.product_id } : {}),
      description: line.description,
      quantity: microsToDecimal(line.quantity_micros),
      unit_code: line.unit_code,
      unit_price: centsToDecimal(line.unit_price_cents),
      net_amount: centsToDecimal(line.net_amount_cents),
      tax: {
        category: line.tax_category === "OUT_OF_SCOPE" ? "OUTSIDE_SCOPE" : line.tax_category,
        rate: centsToDecimal(line.tax_rate_bps),
        taxable_amount: centsToDecimal(line.net_amount_cents),
        tax_amount: centsToDecimal(line.tax_amount_cents),
      },
    })),
    totals: {
      line_net_amount: centsToDecimal(quotation.subtotal_cents),
      tax_exclusive_amount: centsToDecimal(quotation.subtotal_cents),
      tax_amount: centsToDecimal(quotation.tax_cents),
      tax_inclusive_amount: centsToDecimal(quotation.total_cents),
      payable_amount: centsToDecimal(quotation.total_cents),
    },
    ...(quotation.notes ? { notes: [quotation.notes] } : {}),
  };

  // Invoice certification is independently idempotent. If this process stops after that
  // commit, the same key reloads the certified invoice and safely finishes quotation linkage.
  const invoice = await submitInvoice(invoicePayload, actor, idempotencyKey, context);
  const now = new Date().toISOString();
  const audit = await auditEnvelope(db, actor, "QUOTATION_CONVERTED", "QUOTATION", id, { organisationId: organisation.id, invoiceId: invoice.id, correlationId: context.correlationId }, now);
  await db.batch([
    db.prepare("UPDATE quotations SET status='CONVERTED',converted_invoice_id=?,updated_at=? WHERE id=? AND organisation_id=? AND status='ACCEPTED'").bind(invoice.id, now, id, organisation.id),
    commandRecord(db, actor.userId, "CONVERT_QUOTATION", idempotencyKey, requestHash, "INVOICE", invoice.id, now),
    outboxRecord(db, "QUOTATION", id, "QuotationConverted", organisation.id, { quotation_id: id, organisation_id: organisation.id, invoice_id: invoice.id, correlation_id: context.correlationId }, now),
    auditRecord(db, actor, audit, now),
  ]);
  return invoice;
}

export async function postJournal(payload: JournalSubmission, actor: UserContext, idempotencyKey: string, correlationId: string, requestedOrganisationId?: string | null) {
  validateIdempotencyKey(idempotencyKey);
  const journal = normalizeAndValidateJournal(payload);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const requestHash = await sha256Hex(stableStringify({ organisation_id: organisation.id, journal }));
  const prior = await priorCommand(db, actor.userId, "POST_JOURNAL", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM journal_entries WHERE id=? AND organisation_id=?").bind(prior, organisation.id).first<Record<string, unknown>>();
  for (const line of journal.lines) {
    await requireOwnedReference(db, "chart_of_accounts", line.account_id, organisation.id, "Account");
    await requireOwnedReference(db, "branches", line.branch_id, organisation.id, "Branch");
    await requireOwnedReference(db, "projects", line.project_id, organisation.id, "Project");
  }
  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  const audit = await auditEnvelope(db, actor, "JOURNAL_POSTED", "JOURNAL", id, { organisationId: organisation.id, journalNumber: journal.journal_number, correlationId }, now);
  const statements: D1PreparedStatement[] = [db.prepare(`INSERT INTO journal_entries
    (id,organisation_id,journal_number,journal_date,reference,description,currency,status,source_type,source_id,created_by,posted_by,created_at,posted_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(id, organisation.id, journal.journal_number, journal.journal_date, journal.reference ?? null, journal.description, journal.currency, "POSTED", journal.source_type, journal.source_id ?? null, actor.userId, actor.userId, now, now)];
  journal.lines.forEach((line, index) => statements.push(db.prepare(`INSERT INTO journal_lines
    (id,journal_entry_id,line_number,account_id,branch_id,project_id,description,debit_cents,credit_cents,tax_code)
    VALUES (?,?,?,?,?,?,?,?,?,?)`).bind(crypto.randomUUID(), id, index + 1, line.account_id, line.branch_id ?? null, line.project_id ?? null, line.description, line.debit_cents, line.credit_cents, line.tax_code ?? null)));
  statements.push(commandRecord(db, actor.userId, "POST_JOURNAL", idempotencyKey, requestHash, "JOURNAL", id, now));
  statements.push(outboxRecord(db, "JOURNAL", id, "JournalPosted", organisation.id, { journal_id: id, organisation_id: organisation.id, correlation_id: correlationId }, now));
  statements.push(auditRecord(db, actor, audit, now));
  await db.batch(statements);
  return db.prepare("SELECT * FROM journal_entries WHERE id=?").bind(id).first<Record<string, unknown>>();
}

export async function createExpense(payload: ExpenseSubmission, actor: UserContext, idempotencyKey: string, correlationId: string, requestedOrganisationId?: string | null) {
  validateIdempotencyKey(idempotencyKey);
  const expense = normalizeAndValidateExpense(payload);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const requestHash = await sha256Hex(stableStringify({ organisation_id: organisation.id, expense }));
  const prior = await priorCommand(db, actor.userId, "CREATE_EXPENSE", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM expenses WHERE id=? AND organisation_id=?").bind(prior, organisation.id).first<Record<string, unknown>>();
  await requireOwnedReference(db, "expense_categories", expense.category_id, organisation.id, "Expense category");
  await requireOwnedReference(db, "business_parties", expense.supplier_party_id, organisation.id, "Supplier party");
  await requireOwnedReference(db, "projects", expense.project_id, organisation.id, "Project");
  await requireOwnedReference(db, "branches", expense.branch_id, organisation.id, "Branch");
  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  const audit = await auditEnvelope(db, actor, "EXPENSE_RECORDED", "EXPENSE", id, { organisationId: organisation.id, expenseNumber: expense.expense_number, totalCents: expense.total_cents, correlationId }, now);
  await db.batch([
    db.prepare(`INSERT INTO expenses
      (id,organisation_id,branch_id,category_id,supplier_party_id,project_id,expense_number,expense_date,description,currency,net_cents,tax_cents,total_cents,status,receipt_document_id,created_by,approved_by,created_at,approved_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'DRAFT',NULL,?,NULL,?,NULL)`).bind(id, organisation.id, expense.branch_id ?? null, expense.category_id, expense.supplier_party_id ?? null, expense.project_id ?? null, expense.expense_number, expense.expense_date, expense.description, expense.currency, expense.net_cents, expense.tax_cents, expense.total_cents, actor.userId, now),
    commandRecord(db, actor.userId, "CREATE_EXPENSE", idempotencyKey, requestHash, "EXPENSE", id, now),
    outboxRecord(db, "EXPENSE", id, "ExpenseRecorded", organisation.id, { expense_id: id, organisation_id: organisation.id, total_cents: expense.total_cents, correlation_id: correlationId }, now),
    auditRecord(db, actor, audit, now),
  ]);
  return db.prepare("SELECT * FROM expenses WHERE id=?").bind(id).first<Record<string, unknown>>();
}

export async function recordStockMovement(payload: StockMovementSubmission, actor: UserContext, idempotencyKey: string, correlationId: string, requestedOrganisationId?: string | null) {
  validateIdempotencyKey(idempotencyKey);
  const movement = normalizeAndValidateStockMovement(payload);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const requestHash = await sha256Hex(stableStringify({ organisation_id: organisation.id, movement }));
  const prior = await priorCommand(db, actor.userId, "RECORD_STOCK_MOVEMENT", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM stock_movements WHERE id=? AND organisation_id=?").bind(prior, organisation.id).first<Record<string, unknown>>();
  await requireOwnedReference(db, "warehouses", movement.warehouse_id, organisation.id, "Warehouse");
  await requireOwnedReference(db, "products", movement.product_id, organisation.id, "Product");
  const balance = await db.prepare("SELECT quantity_micros FROM inventory_balances WHERE warehouse_id=? AND product_id=?").bind(movement.warehouse_id, movement.product_id).first<{ quantity_micros: number }>();
  if ((balance?.quantity_micros ?? 0) + movement.quantity_micros < 0) throw new RepositoryConflictError("The movement would make on-hand inventory negative.");
  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  const audit = await auditEnvelope(db, actor, "STOCK_MOVEMENT_RECORDED", "STOCK_MOVEMENT", id, { organisationId: organisation.id, productId: movement.product_id, quantityMicros: movement.quantity_micros, correlationId }, now);
  await db.batch([
    db.prepare(`INSERT INTO inventory_balances (id,organisation_id,warehouse_id,product_id,quantity_micros,average_cost_cents,version,updated_at)
      VALUES (?,?,?,?,?,?,1,?) ON CONFLICT(warehouse_id,product_id) DO UPDATE SET
      average_cost_cents=CASE WHEN excluded.quantity_micros>0 AND inventory_balances.quantity_micros+excluded.quantity_micros>0
        THEN CAST(ROUND((inventory_balances.quantity_micros*inventory_balances.average_cost_cents+excluded.quantity_micros*excluded.average_cost_cents)*1.0/(inventory_balances.quantity_micros+excluded.quantity_micros)) AS INTEGER)
        ELSE inventory_balances.average_cost_cents END,
      quantity_micros=inventory_balances.quantity_micros+excluded.quantity_micros,
      version=inventory_balances.version+1,updated_at=excluded.updated_at`).bind(crypto.randomUUID(), organisation.id, movement.warehouse_id, movement.product_id, movement.quantity_micros, movement.unit_cost_cents, now),
    db.prepare(`INSERT INTO stock_movements
      (id,organisation_id,warehouse_id,product_id,movement_type,quantity_micros,unit_cost_cents,reference_type,reference_id,reason,occurred_at,actor_id)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?)`).bind(id, organisation.id, movement.warehouse_id, movement.product_id, movement.movement_type, movement.quantity_micros, movement.unit_cost_cents, movement.reference_type, movement.reference_id, movement.reason, movement.occurred_at, actor.userId),
    commandRecord(db, actor.userId, "RECORD_STOCK_MOVEMENT", idempotencyKey, requestHash, "STOCK_MOVEMENT", id, now),
    outboxRecord(db, "STOCK_MOVEMENT", id, "StockMovementRecorded", organisation.id, { stock_movement_id: id, organisation_id: organisation.id, quantity_micros: movement.quantity_micros, correlation_id: correlationId }, now),
    auditRecord(db, actor, audit, now),
  ]);
  return db.prepare("SELECT * FROM stock_movements WHERE id=?").bind(id).first<Record<string, unknown>>();
}

export async function createProject(payload: ProjectSubmission, actor: UserContext, idempotencyKey: string, correlationId: string, requestedOrganisationId?: string | null) {
  validateIdempotencyKey(idempotencyKey);
  const project = normalizeAndValidateProject(payload);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const requestHash = await sha256Hex(stableStringify({ organisation_id: organisation.id, project }));
  const prior = await priorCommand(db, actor.userId, "CREATE_PROJECT", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM projects WHERE id=? AND organisation_id=?").bind(prior, organisation.id).first<Record<string, unknown>>();
  await requireOwnedReference(db, "business_parties", project.customer_party_id, organisation.id, "Customer party");
  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  const audit = await auditEnvelope(db, actor, "PROJECT_CREATED", "PROJECT", id, { organisationId: organisation.id, code: project.code, correlationId }, now);
  const statements: D1PreparedStatement[] = [
    db.prepare(`INSERT INTO projects (id,organisation_id,code,name,customer_party_id,manager_user_id,currency,start_date,end_date,status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,'PLANNED',?,?)`).bind(id, organisation.id, project.code, project.name, project.customer_party_id ?? null, actor.userId, project.currency, project.start_date, project.end_date ?? null, now, now),
  ];
  if (project.budget_cents !== undefined) statements.push(db.prepare(`INSERT INTO project_budgets
    (id,project_id,category,amount_cents,approved_amount_cents,status,approved_by,approved_at,created_at)
    VALUES (?,?,?,?,0,'PROPOSED',NULL,NULL,?)`).bind(crypto.randomUUID(), id, "TOTAL", project.budget_cents, now));
  statements.push(commandRecord(db, actor.userId, "CREATE_PROJECT", idempotencyKey, requestHash, "PROJECT", id, now));
  statements.push(outboxRecord(db, "PROJECT", id, "ProjectCreated", organisation.id, { project_id: id, organisation_id: organisation.id, correlation_id: correlationId }, now));
  statements.push(auditRecord(db, actor, audit, now));
  await db.batch(statements);
  return db.prepare("SELECT * FROM projects WHERE id=?").bind(id).first<Record<string, unknown>>();
}
