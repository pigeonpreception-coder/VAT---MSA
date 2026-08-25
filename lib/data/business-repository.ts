import { ensureDatabase } from "@/db/runtime";
import { AccessDeniedError, isNationalScope } from "@/lib/auth";
import {
  evaluateQuotationLifecycle,
  normalizeAndValidateAccount,
  normalizeAndValidateBusinessParty,
  normalizeAndValidateBusinessPartyDeactivation,
  normalizeAndValidateExpense,
  normalizeAndValidateExpenseCategory,
  normalizeAndValidateExpenseRejection,
  normalizeAndValidateJournal,
  normalizeAndValidateJournalReversal,
  normalizeAndValidatePeriodClose,
  normalizeAndValidateProject,
  normalizeAndValidateProjectBudgetApproval,
  normalizeAndValidateProjectCost,
  normalizeAndValidateQuotation,
  normalizeAndValidateQuotationConversion,
  normalizeAndValidateQuotationRejection,
  normalizeAndValidateStockMovement,
  type AccountSubmission,
  type AccountType,
  type BusinessPartyDeactivationSubmission,
  type BusinessPartyRelationship,
  type BusinessPartySubmission,
  type ExpenseSubmission,
  type JournalSubmission,
  type NormalizedQuotation,
  type ProjectSubmission,
  type QuotationRejectionSubmission,
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

type QuotationRecord = {
  id: string;
  organisation_id: string;
  branch_id: string | null;
  customer_party_id: string;
  quotation_number: string;
  currency: string;
  issue_date: string;
  valid_until: string;
  status: string;
  subtotal_cents: number;
  tax_cents: number;
  total_cents: number;
  notes: string | null;
  created_by: string;
  approved_by: string | null;
  accepted_at: string | null;
  converted_invoice_id: string | null;
  created_at: string;
  updated_at: string;
};

type QuotationLineRecord = {
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

type ConvertibleQuotation = {
  id: string;
  organisation_id: string;
  customer_party_id: string;
  quotation_number: string;
  currency: string;
  issue_date: string;
  valid_until: string;
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

function quotationSnapshot(quotation: NormalizedQuotation, status: string) {
  return {
    schema_version: quotation.schema_version,
    customer_party_id: quotation.customer_party_id,
    ...(quotation.branch_id ? { branch_id: quotation.branch_id } : {}),
    quotation_number: quotation.quotation_number,
    currency: quotation.currency,
    issue_date: quotation.issue_date,
    valid_until: quotation.valid_until,
    status,
    ...(quotation.notes ? { notes: quotation.notes } : {}),
    lines: quotation.lines,
    subtotal_cents: quotation.subtotal_cents,
    tax_cents: quotation.tax_cents,
    total_cents: quotation.total_cents,
  };
}

function storedQuotationSnapshot(quotation: QuotationRecord, lines: QuotationLineRecord[]) {
  return {
    schema_version: "1.0.0",
    customer_party_id: quotation.customer_party_id,
    ...(quotation.branch_id ? { branch_id: quotation.branch_id } : {}),
    quotation_number: quotation.quotation_number,
    currency: quotation.currency,
    issue_date: quotation.issue_date,
    valid_until: quotation.valid_until,
    status: quotation.status,
    ...(quotation.notes ? { notes: quotation.notes } : {}),
    lines,
    subtotal_cents: quotation.subtotal_cents,
    tax_cents: quotation.tax_cents,
    total_cents: quotation.total_cents,
  };
}

async function quotationRevisionRecord(
  db: D1Database,
  input: {
    quotationId: string;
    organisationId: string;
    revisionNumber: number;
    action: "ISSUE" | "EDIT";
    status: string;
    snapshot: Record<string, unknown>;
    previousHash: string | null;
    actorId: string;
    now: string;
  },
) {
  const snapshot = stableStringify({ previous_hash: input.previousHash, state: input.snapshot });
  const hash = await sha256Hex(snapshot);
  return {
    hash,
    statement: db.prepare(`INSERT INTO quotation_revisions
      (id,quotation_id,organisation_id,revision_number,action,status,snapshot_hash,snapshot,created_by,created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?)`).bind(
      crypto.randomUUID(), input.quotationId, input.organisationId, input.revisionNumber, input.action,
      input.status, hash, snapshot, input.actorId, input.now,
    ),
  };
}

async function requireOwnedReference(db: D1Database, table: string, id: string | undefined, organisationId: string, label: string) {
  if (!id) return;
  const allowedTables = new Set(["business_parties", "branches", "products", "warehouses", "projects", "expense_categories", "chart_of_accounts"]);
  if (!allowedTables.has(table)) throw new Error("Unsafe reference table.");
  const row = await db.prepare(`SELECT id FROM ${table} WHERE id=? AND organisation_id=?`).bind(id, organisationId).first<{ id: string }>();
  if (!row) throw new BusinessResourceError(`${label} does not exist in the authorised organisation.`);
}

async function requirePartyRelationship(
  db: D1Database,
  id: string | undefined,
  organisationId: string,
  relationship: BusinessPartyRelationship,
  label: string,
) {
  if (!id) return;
  const row = await db.prepare(`SELECT p.id FROM business_parties p
    JOIN party_relationships r ON r.party_id=p.id AND r.organisation_id=p.organisation_id
    WHERE p.id=? AND p.organisation_id=? AND p.status='ACTIVE' AND r.relationship=? AND r.status='ACTIVE'`)
    .bind(id, organisationId, relationship).first<{ id: string }>();
  if (!row) throw new BusinessResourceError(`${label} is not an active ${relationship.toLowerCase()} in the authorised organisation.`);
}

async function getBusinessParty(db: D1Database, id: string, organisationId: string) {
  return db.prepare(`SELECT p.*,GROUP_CONCAT(r.relationship, ',') AS relationships FROM business_parties p
    LEFT JOIN party_relationships r ON r.party_id=p.id AND r.organisation_id=p.organisation_id AND r.status='ACTIVE'
    WHERE p.id=? AND p.organisation_id=? GROUP BY p.id`).bind(id, organisationId).first<Record<string, unknown>>();
}

async function assertBusinessPartyIdentifiersAvailable(
  db: D1Database,
  organisationId: string,
  party: BusinessPartySubmission,
  excludedId?: string,
) {
  if (!party.vat_number && !party.tin) return;
  const duplicate = await db.prepare(`SELECT id,display_name FROM business_parties
    WHERE organisation_id=? AND status='ACTIVE' AND id<>COALESCE(?, '')
      AND ((? IS NOT NULL AND vat_number=?) OR (? IS NOT NULL AND tin=?)) LIMIT 1`)
    .bind(organisationId, excludedId ?? null, party.vat_number ?? null, party.vat_number ?? null, party.tin ?? null, party.tin ?? null)
    .first<{ id: string; display_name: string }>();
  if (duplicate) throw new RepositoryConflictError(`An active business party already uses that VAT number or TIN (${duplicate.display_name}, ${duplicate.id}).`);
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

export async function getQuotationForEdit(id: string, actor: UserContext, requestedOrganisationId?: string | null) {
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const quotation = await db.prepare(`SELECT q.*,p.display_name AS customer_name,
    (SELECT COUNT(*) FROM quotation_revisions r WHERE r.quotation_id=q.id) AS revision_count
    FROM quotations q JOIN business_parties p ON p.id=q.customer_party_id AND p.organisation_id=q.organisation_id
    WHERE q.id=? AND q.organisation_id=?`).bind(id, organisation.id).first<Record<string, string | number | null>>();
  if (!quotation) throw new BusinessResourceError("Quotation was not found in the authorised organisation.", 404);
  const lines = await db.prepare(`SELECT line_number,product_id,description,quantity_micros,unit_code,unit_price_cents,
    net_amount_cents,tax_category,tax_rate_bps,tax_amount_cents FROM quotation_lines
    WHERE quotation_id=? ORDER BY line_number`).bind(id).all<Record<string, string | number | null>>();
  return { organisation, quotation, lines: lines.results };
}

export async function createBusinessParty(
  payload: BusinessPartySubmission,
  actor: UserContext,
  idempotencyKey: string,
  correlationId: string,
  requestedOrganisationId?: string | null,
) {
  validateIdempotencyKey(idempotencyKey);
  const party = normalizeAndValidateBusinessParty(payload);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const requestHash = await sha256Hex(stableStringify({ organisation_id: organisation.id, party }));
  const prior = await priorCommand(db, actor.userId, "CREATE_BUSINESS_PARTY", idempotencyKey, requestHash);
  if (prior) return getBusinessParty(db, prior, organisation.id);
  await assertBusinessPartyIdentifiersAvailable(db, organisation.id, party);

  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  const audit = await auditEnvelope(db, actor, "BUSINESS_PARTY_CREATED", "BUSINESS_PARTY", id, {
    organisationId: organisation.id,
    relationships: party.relationships,
    correlationId,
  }, now);
  const statements: D1PreparedStatement[] = [
    db.prepare(`INSERT INTO business_parties
      (id,organisation_id,display_name,legal_name,vat_number,tin,email,phone,address,source_system,source_party_id,status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,'LOCAL',NULL,'ACTIVE',?,?)`).bind(
      id, organisation.id, party.display_name, party.legal_name ?? null, party.vat_number ?? null, party.tin ?? null,
      party.email ?? null, party.phone ?? null, party.address ?? null, now, now,
    ),
  ];
  for (const relationship of party.relationships) {
    statements.push(db.prepare(`INSERT INTO party_relationships
      (id,organisation_id,party_id,relationship,status,effective_from,effective_to,created_at)
      VALUES (?,?,?,?,'ACTIVE',?,NULL,?)`).bind(crypto.randomUUID(), organisation.id, id, relationship, now, now));
  }
  statements.push(commandRecord(db, actor.userId, "CREATE_BUSINESS_PARTY", idempotencyKey, requestHash, "BUSINESS_PARTY", id, now));
  statements.push(outboxRecord(db, "BUSINESS_PARTY", id, "BusinessPartyCreated", organisation.id, {
    party_id: id, organisation_id: organisation.id, relationships: party.relationships, correlation_id: correlationId,
  }, now));
  statements.push(auditRecord(db, actor, audit, now));
  await db.batch(statements);
  return getBusinessParty(db, id, organisation.id);
}

export async function updateBusinessParty(
  id: string,
  payload: BusinessPartySubmission,
  actor: UserContext,
  idempotencyKey: string,
  correlationId: string,
  requestedOrganisationId?: string | null,
) {
  validateIdempotencyKey(idempotencyKey);
  const party = normalizeAndValidateBusinessParty(payload);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const requestHash = await sha256Hex(stableStringify({ organisation_id: organisation.id, party_id: id, party }));
  const prior = await priorCommand(db, actor.userId, "UPDATE_BUSINESS_PARTY", idempotencyKey, requestHash);
  if (prior) return getBusinessParty(db, prior, organisation.id);
  const existing = await db.prepare("SELECT id,status FROM business_parties WHERE id=? AND organisation_id=?")
    .bind(id, organisation.id).first<{ id: string; status: string }>();
  if (!existing) throw new BusinessResourceError("Business party was not found in the authorised organisation.", 404);
  if (existing.status !== "ACTIVE") throw new RepositoryConflictError("An inactive business party cannot be edited. Create a new active relationship record if trading resumes.");
  await assertBusinessPartyIdentifiersAvailable(db, organisation.id, party, id);

  const now = new Date().toISOString();
  const audit = await auditEnvelope(db, actor, "BUSINESS_PARTY_UPDATED", "BUSINESS_PARTY", id, {
    organisationId: organisation.id,
    relationships: party.relationships,
    correlationId,
  }, now);
  const statements: D1PreparedStatement[] = [
    db.prepare(`UPDATE business_parties SET display_name=?,legal_name=?,vat_number=?,tin=?,email=?,phone=?,address=?,updated_at=?
      WHERE id=? AND organisation_id=? AND status='ACTIVE'`).bind(
      party.display_name, party.legal_name ?? null, party.vat_number ?? null, party.tin ?? null,
      party.email ?? null, party.phone ?? null, party.address ?? null, now, id, organisation.id,
    ),
  ];
  for (const relationship of ["CUSTOMER", "SUPPLIER"] as const) {
    if (party.relationships.includes(relationship)) {
      statements.push(db.prepare(`INSERT INTO party_relationships
        (id,organisation_id,party_id,relationship,status,effective_from,effective_to,created_at)
        VALUES (?,?,?,?,'ACTIVE',?,NULL,?)
        ON CONFLICT(organisation_id,party_id,relationship) DO UPDATE SET
          status='ACTIVE',
          effective_from=CASE WHEN party_relationships.status='ACTIVE' THEN party_relationships.effective_from ELSE excluded.effective_from END,
          effective_to=NULL`)
        .bind(crypto.randomUUID(), organisation.id, id, relationship, now, now));
    } else {
      statements.push(db.prepare(`UPDATE party_relationships SET status='INACTIVE',effective_to=?
        WHERE organisation_id=? AND party_id=? AND relationship=? AND status='ACTIVE'`).bind(now, organisation.id, id, relationship));
    }
  }
  statements.push(commandRecord(db, actor.userId, "UPDATE_BUSINESS_PARTY", idempotencyKey, requestHash, "BUSINESS_PARTY", id, now));
  statements.push(outboxRecord(db, "BUSINESS_PARTY", id, "BusinessPartyUpdated", organisation.id, {
    party_id: id, organisation_id: organisation.id, relationships: party.relationships, correlation_id: correlationId,
  }, now));
  statements.push(auditRecord(db, actor, audit, now));
  await db.batch(statements);
  return getBusinessParty(db, id, organisation.id);
}

export async function deactivateBusinessParty(
  id: string,
  payload: BusinessPartyDeactivationSubmission,
  actor: UserContext,
  idempotencyKey: string,
  correlationId: string,
  requestedOrganisationId?: string | null,
) {
  validateIdempotencyKey(idempotencyKey);
  const deactivation = normalizeAndValidateBusinessPartyDeactivation(payload);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const requestHash = await sha256Hex(stableStringify({ organisation_id: organisation.id, party_id: id, deactivation }));
  const prior = await priorCommand(db, actor.userId, "DEACTIVATE_BUSINESS_PARTY", idempotencyKey, requestHash);
  if (prior) return getBusinessParty(db, prior, organisation.id);
  const existing = await db.prepare("SELECT id,status FROM business_parties WHERE id=? AND organisation_id=?")
    .bind(id, organisation.id).first<{ id: string; status: string }>();
  if (!existing) throw new BusinessResourceError("Business party was not found in the authorised organisation.", 404);
  if (existing.status !== "ACTIVE") throw new RepositoryConflictError("Business party is already inactive.");

  const now = new Date().toISOString();
  const audit = await auditEnvelope(db, actor, "BUSINESS_PARTY_DEACTIVATED", "BUSINESS_PARTY", id, {
    organisationId: organisation.id,
    reason: deactivation.reason,
    correlationId,
    recordsPreserved: true,
  }, now);
  await db.batch([
    db.prepare("UPDATE business_parties SET status='INACTIVE',updated_at=? WHERE id=? AND organisation_id=? AND status='ACTIVE'").bind(now, id, organisation.id),
    db.prepare("UPDATE party_relationships SET status='INACTIVE',effective_to=? WHERE party_id=? AND organisation_id=? AND status='ACTIVE'").bind(now, id, organisation.id),
    commandRecord(db, actor.userId, "DEACTIVATE_BUSINESS_PARTY", idempotencyKey, requestHash, "BUSINESS_PARTY", id, now),
    outboxRecord(db, "BUSINESS_PARTY", id, "BusinessPartyDeactivated", organisation.id, {
      party_id: id, organisation_id: organisation.id, records_preserved: true, correlation_id: correlationId,
    }, now),
    auditRecord(db, actor, audit, now),
  ]);
  return getBusinessParty(db, id, organisation.id);
}

export async function createQuotation(payload: QuotationSubmission, actor: UserContext, idempotencyKey: string, correlationId: string, requestedOrganisationId?: string | null) {
  validateIdempotencyKey(idempotencyKey);
  const quotation = normalizeAndValidateQuotation(payload);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const requestHash = await sha256Hex(stableStringify({ organisation_id: organisation.id, quotation }));
  const prior = await priorCommand(db, actor.userId, "CREATE_QUOTATION", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM quotations WHERE id=? AND organisation_id=?").bind(prior, organisation.id).first<Record<string, unknown>>();
  await requirePartyRelationship(db, quotation.customer_party_id, organisation.id, "CUSTOMER", "Customer party");
  await requireOwnedReference(db, "branches", quotation.branch_id, organisation.id, "Branch");
  for (const line of quotation.lines) await requireOwnedReference(db, "products", line.product_id, organisation.id, "Product");
  const duplicate = await db.prepare("SELECT id FROM quotations WHERE organisation_id=? AND quotation_number=?").bind(organisation.id, quotation.quotation_number).first<{ id: string }>();
  if (duplicate) throw new RepositoryConflictError(`Quotation number already exists as ${duplicate.id}.`);
  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  const audit = await auditEnvelope(db, actor, "QUOTATION_ISSUED", "QUOTATION", id, { organisationId: organisation.id, quotationNumber: quotation.quotation_number, totalCents: quotation.total_cents, correlationId }, now);
  const revision = await quotationRevisionRecord(db, {
    quotationId: id,
    organisationId: organisation.id,
    revisionNumber: 1,
    action: "ISSUE",
    status: "ISSUED",
    snapshot: quotationSnapshot(quotation, "ISSUED"),
    previousHash: null,
    actorId: actor.userId,
    now,
  });
  const statements: D1PreparedStatement[] = [
    db.prepare(`INSERT INTO quotations
      (id,organisation_id,branch_id,customer_party_id,quotation_number,currency,issue_date,valid_until,status,subtotal_cents,tax_cents,total_cents,notes,created_by,approved_by,accepted_at,converted_invoice_id,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,NULL,NULL,?,?)`).bind(id, organisation.id, quotation.branch_id ?? null, quotation.customer_party_id, quotation.quotation_number, quotation.currency, quotation.issue_date, quotation.valid_until, "ISSUED", quotation.subtotal_cents, quotation.tax_cents, quotation.total_cents, quotation.notes ?? null, actor.userId, now, now),
  ];
  for (const line of quotation.lines) statements.push(db.prepare(`INSERT INTO quotation_lines
    (id,quotation_id,line_number,product_id,description,quantity_micros,unit_code,unit_price_cents,net_amount_cents,tax_category,tax_rate_bps,tax_amount_cents)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)`).bind(crypto.randomUUID(), id, line.line_number, line.product_id ?? null, line.description, line.quantity_micros, line.unit_code, line.unit_price_cents, line.net_amount_cents, line.tax_category, line.tax_rate_bps, line.tax_amount_cents));
  statements.push(revision.statement);
  statements.push(commandRecord(db, actor.userId, "CREATE_QUOTATION", idempotencyKey, requestHash, "QUOTATION", id, now));
  statements.push(outboxRecord(db, "QUOTATION", id, "QuotationIssued", organisation.id, { quotation_id: id, organisation_id: organisation.id, total_cents: quotation.total_cents, correlation_id: correlationId }, now));
  statements.push(auditRecord(db, actor, audit, now));
  await db.batch(statements);
  return db.prepare("SELECT * FROM quotations WHERE id=?").bind(id).first<Record<string, unknown>>();
}

export async function updateQuotation(
  id: string,
  payload: QuotationSubmission,
  actor: UserContext,
  idempotencyKey: string,
  correlationId: string,
  requestedOrganisationId?: string | null,
) {
  validateIdempotencyKey(idempotencyKey);
  const quotation = normalizeAndValidateQuotation(payload);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const requestHash = await sha256Hex(stableStringify({ organisation_id: organisation.id, quotation_id: id, quotation }));
  const prior = await priorCommand(db, actor.userId, "UPDATE_QUOTATION", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM quotations WHERE id=? AND organisation_id=?").bind(prior, organisation.id).first<Record<string, unknown>>();
  const existing = await db.prepare("SELECT * FROM quotations WHERE id=? AND organisation_id=?").bind(id, organisation.id).first<QuotationRecord>();
  if (!existing) throw new BusinessResourceError("Quotation was not found in the authorised organisation.", 404);
  const transition = evaluateQuotationLifecycle({ status: existing.status, action: "EDIT", validUntil: existing.valid_until, today: new Date().toISOString().slice(0, 10) });
  if (!transition.allowed) throw new RepositoryConflictError(transition.reason);
  if (quotation.quotation_number !== existing.quotation_number) throw new RepositoryConflictError("Quotation number is immutable after issue.");
  await requirePartyRelationship(db, quotation.customer_party_id, organisation.id, "CUSTOMER", "Customer party");
  await requireOwnedReference(db, "branches", quotation.branch_id, organisation.id, "Branch");
  for (const line of quotation.lines) await requireOwnedReference(db, "products", line.product_id, organisation.id, "Product");

  const existingLines = await db.prepare(`SELECT line_number,product_id,description,quantity_micros,unit_code,unit_price_cents,
    net_amount_cents,tax_category,tax_rate_bps,tax_amount_cents FROM quotation_lines WHERE quotation_id=? ORDER BY line_number`)
    .bind(id).all<QuotationLineRecord>();
  const priorRevision = await db.prepare(`SELECT revision_number,snapshot_hash FROM quotation_revisions
    WHERE quotation_id=? ORDER BY revision_number DESC LIMIT 1`).bind(id).first<{ revision_number: number; snapshot_hash: string }>();
  const now = new Date().toISOString();
  const statements: D1PreparedStatement[] = [];
  let revisionNumber = (priorRevision?.revision_number ?? 0) + 1;
  let previousHash = priorRevision?.snapshot_hash ?? null;
  if (!priorRevision) {
    const baseline = await quotationRevisionRecord(db, {
      quotationId: id,
      organisationId: organisation.id,
      revisionNumber: 1,
      action: "ISSUE",
      status: existing.status,
      snapshot: storedQuotationSnapshot(existing, existingLines.results),
      previousHash: null,
      actorId: existing.created_by,
      now: existing.created_at,
    });
    statements.push(baseline.statement);
    previousHash = baseline.hash;
    revisionNumber = 2;
  }
  const revision = await quotationRevisionRecord(db, {
    quotationId: id,
    organisationId: organisation.id,
    revisionNumber,
    action: "EDIT",
    status: "ISSUED",
    snapshot: quotationSnapshot(quotation, "ISSUED"),
    previousHash,
    actorId: actor.userId,
    now,
  });
  const audit = await auditEnvelope(db, actor, "QUOTATION_EDITED", "QUOTATION", id, {
    organisationId: organisation.id,
    revisionNumber,
    previousTotalCents: existing.total_cents,
    totalCents: quotation.total_cents,
    correlationId,
  }, now);
  statements.push(db.prepare(`UPDATE quotations SET branch_id=?,customer_party_id=?,currency=?,issue_date=?,valid_until=?,
    subtotal_cents=?,tax_cents=?,total_cents=?,notes=?,updated_at=? WHERE id=? AND organisation_id=? AND status='ISSUED'`).bind(
    quotation.branch_id ?? null, quotation.customer_party_id, quotation.currency, quotation.issue_date, quotation.valid_until,
    quotation.subtotal_cents, quotation.tax_cents, quotation.total_cents, quotation.notes ?? null, now, id, organisation.id,
  ));
  statements.push(db.prepare("DELETE FROM quotation_lines WHERE quotation_id=?").bind(id));
  for (const line of quotation.lines) statements.push(db.prepare(`INSERT INTO quotation_lines
    (id,quotation_id,line_number,product_id,description,quantity_micros,unit_code,unit_price_cents,net_amount_cents,tax_category,tax_rate_bps,tax_amount_cents)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?)`).bind(crypto.randomUUID(), id, line.line_number, line.product_id ?? null, line.description, line.quantity_micros, line.unit_code, line.unit_price_cents, line.net_amount_cents, line.tax_category, line.tax_rate_bps, line.tax_amount_cents));
  statements.push(revision.statement);
  statements.push(commandRecord(db, actor.userId, "UPDATE_QUOTATION", idempotencyKey, requestHash, "QUOTATION", id, now));
  statements.push(outboxRecord(db, "QUOTATION", id, "QuotationEdited", organisation.id, {
    quotation_id: id, organisation_id: organisation.id, revision_number: revisionNumber, total_cents: quotation.total_cents, correlation_id: correlationId,
  }, now));
  statements.push(auditRecord(db, actor, audit, now));
  await db.batch(statements);
  return db.prepare("SELECT * FROM quotations WHERE id=? AND organisation_id=?").bind(id, organisation.id).first<Record<string, unknown>>();
}

export async function rejectQuotation(
  id: string,
  payload: QuotationRejectionSubmission,
  actor: UserContext,
  idempotencyKey: string,
  correlationId: string,
  requestedOrganisationId?: string | null,
) {
  validateIdempotencyKey(idempotencyKey);
  const rejection = normalizeAndValidateQuotationRejection(payload);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const requestHash = await sha256Hex(stableStringify({ organisation_id: organisation.id, quotation_id: id, rejection }));
  const prior = await priorCommand(db, actor.userId, "REJECT_QUOTATION", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM quotations WHERE id=? AND organisation_id=?").bind(prior, organisation.id).first<Record<string, unknown>>();
  const existing = await db.prepare("SELECT id,status,valid_until FROM quotations WHERE id=? AND organisation_id=?")
    .bind(id, organisation.id).first<{ id: string; status: string; valid_until: string }>();
  if (!existing) throw new BusinessResourceError("Quotation was not found in the authorised organisation.", 404);
  const transition = evaluateQuotationLifecycle({ status: existing.status, action: "REJECT", validUntil: existing.valid_until, today: new Date().toISOString().slice(0, 10) });
  if (!transition.allowed) throw new RepositoryConflictError(transition.reason);
  const now = new Date().toISOString();
  const audit = await auditEnvelope(db, actor, "QUOTATION_REJECTED", "QUOTATION", id, { organisationId: organisation.id, reason: rejection.reason, correlationId }, now);
  await db.batch([
    db.prepare("UPDATE quotations SET status='REJECTED',updated_at=? WHERE id=? AND organisation_id=? AND status='ISSUED'").bind(now, id, organisation.id),
    commandRecord(db, actor.userId, "REJECT_QUOTATION", idempotencyKey, requestHash, "QUOTATION", id, now),
    outboxRecord(db, "QUOTATION", id, "QuotationRejected", organisation.id, { quotation_id: id, organisation_id: organisation.id, reason_recorded: true, correlation_id: correlationId }, now),
    auditRecord(db, actor, audit, now),
  ]);
  return db.prepare("SELECT * FROM quotations WHERE id=? AND organisation_id=?").bind(id, organisation.id).first<Record<string, unknown>>();
}

export async function expireQuotation(id: string, actor: UserContext, idempotencyKey: string, correlationId: string, requestedOrganisationId?: string | null) {
  validateIdempotencyKey(idempotencyKey);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const requestHash = await sha256Hex(stableStringify({ organisation_id: organisation.id, quotation_id: id, action: "EXPIRE" }));
  const prior = await priorCommand(db, actor.userId, "EXPIRE_QUOTATION", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM quotations WHERE id=? AND organisation_id=?").bind(prior, organisation.id).first<Record<string, unknown>>();
  const existing = await db.prepare("SELECT id,status,valid_until FROM quotations WHERE id=? AND organisation_id=?")
    .bind(id, organisation.id).first<{ id: string; status: string; valid_until: string }>();
  if (!existing) throw new BusinessResourceError("Quotation was not found in the authorised organisation.", 404);
  const transition = evaluateQuotationLifecycle({ status: existing.status, action: "EXPIRE", validUntil: existing.valid_until, today: new Date().toISOString().slice(0, 10) });
  if (!transition.allowed) throw new RepositoryConflictError(transition.reason);
  const now = new Date().toISOString();
  const audit = await auditEnvelope(db, actor, "QUOTATION_EXPIRED", "QUOTATION", id, { organisationId: organisation.id, validUntil: existing.valid_until, correlationId }, now);
  await db.batch([
    db.prepare("UPDATE quotations SET status='EXPIRED',updated_at=? WHERE id=? AND organisation_id=? AND status='ISSUED'").bind(now, id, organisation.id),
    commandRecord(db, actor.userId, "EXPIRE_QUOTATION", idempotencyKey, requestHash, "QUOTATION", id, now),
    outboxRecord(db, "QUOTATION", id, "QuotationExpired", organisation.id, { quotation_id: id, organisation_id: organisation.id, valid_until: existing.valid_until, correlation_id: correlationId }, now),
    auditRecord(db, actor, audit, now),
  ]);
  return db.prepare("SELECT * FROM quotations WHERE id=? AND organisation_id=?").bind(id, organisation.id).first<Record<string, unknown>>();
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
  const today = new Date().toISOString().slice(0, 10);
  const transition = evaluateQuotationLifecycle({ status: quotation.status, action: "ACCEPT", validUntil: quotation.valid_until, today });
  if (!transition.allowed) throw new RepositoryConflictError(transition.reason);
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

  const quotation = await db.prepare(`SELECT q.id,q.organisation_id,q.customer_party_id,q.quotation_number,q.currency,q.issue_date,q.valid_until,
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
  const transition = evaluateQuotationLifecycle({ status: quotation.status, action: "CONVERT", validUntil: quotation.valid_until, today: new Date().toISOString().slice(0, 10) });
  if (!transition.allowed) throw new RepositoryConflictError(transition.reason);
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

/**
 * Module 5 Phase C: accounting periods are implicit and open by default —
 * there is no separate CreateAccountingPeriod command in the playbook, only
 * ClosePeriod — so a period only exists as a row once it's been explicitly
 * closed. Posting (including a reversal) into a period that has been closed
 * is refused; this is the one piece of real teeth ClosePeriod has.
 */
async function assertPeriodOpen(db: D1Database, organisationId: string, date: string) {
  const periodCode = date.slice(0, 7);
  const period = await db.prepare("SELECT status FROM accounting_periods WHERE organisation_id=? AND period_code=?").bind(organisationId, periodCode).first<{ status: string }>();
  if (period?.status === "CLOSED") throw new RepositoryConflictError(`Accounting period ${periodCode} is closed to new postings.`);
}

export async function postJournal(payload: JournalSubmission, actor: UserContext, idempotencyKey: string, correlationId: string, requestedOrganisationId?: string | null) {
  validateIdempotencyKey(idempotencyKey);
  const journal = normalizeAndValidateJournal(payload);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const requestHash = await sha256Hex(stableStringify({ organisation_id: organisation.id, journal }));
  const prior = await priorCommand(db, actor.userId, "POST_JOURNAL", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM journal_entries WHERE id=? AND organisation_id=?").bind(prior, organisation.id).first<Record<string, unknown>>();
  await assertPeriodOpen(db, organisation.id, journal.journal_date);
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

/** Module 5 Phase C CreateAccount. Pre-checks the (organisation_id, code) uniqueness for a clean 409 message, backed by the table's own UNIQUE constraint as the real guarantee — the same pre-check-plus-constraint pattern used for business party VAT/TIN duplicates. */
export async function createAccount(payload: AccountSubmission, actor: UserContext, idempotencyKey: string, correlationId: string, requestedOrganisationId?: string | null) {
  validateIdempotencyKey(idempotencyKey);
  const account = normalizeAndValidateAccount(payload);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const requestHash = await sha256Hex(stableStringify({ organisation_id: organisation.id, account }));
  const prior = await priorCommand(db, actor.userId, "CREATE_ACCOUNT", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM chart_of_accounts WHERE id=? AND organisation_id=?").bind(prior, organisation.id).first<Record<string, unknown>>();
  const existing = await db.prepare("SELECT id,name FROM chart_of_accounts WHERE organisation_id=? AND code=?").bind(organisation.id, account.code).first<{ id: string; name: string }>();
  if (existing) throw new RepositoryConflictError(`Account code ${account.code} is already in use (${existing.name}, ${existing.id}).`);
  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  const audit = await auditEnvelope(db, actor, "ACCOUNT_CREATED", "ACCOUNT", id, { organisationId: organisation.id, code: account.code, accountType: account.account_type, correlationId }, now);
  await db.batch([
    db.prepare(`INSERT INTO chart_of_accounts (id,organisation_id,code,name,account_type,currency,control_type,status,created_at)
      VALUES (?,?,?,?,?,?,?,'ACTIVE',?)`).bind(id, organisation.id, account.code, account.name, account.account_type, account.currency, account.control_type ?? null, now),
    commandRecord(db, actor.userId, "CREATE_ACCOUNT", idempotencyKey, requestHash, "ACCOUNT", id, now),
    outboxRecord(db, "ACCOUNT", id, "AccountCreated", organisation.id, { account_id: id, organisation_id: organisation.id, code: account.code, correlation_id: correlationId }, now),
    auditRecord(db, actor, audit, now),
  ]);
  return db.prepare("SELECT * FROM chart_of_accounts WHERE id=?").bind(id).first<Record<string, unknown>>();
}

/**
 * Module 5 Phase C ReverseJournalEntry. A posted journal is never edited or
 * deleted — a reversal is a brand-new, equal-and-opposite entry (every
 * line's debit/credit swapped) posted as of today, with the original
 * flipped to status='REVERSED' as a traceability marker only. Both entries
 * remain in journal_lines and both count toward TrialBalance/Statements —
 * their opposite amounts net to zero naturally, which is what makes this a
 * real reversal rather than a deletion in disguise. A journal already
 * reversed once cannot be reversed again (checked via
 * reverses_journal_entry_id, not a second status value), and the reversal
 * itself is subject to the same closed-period check as any other posting.
 */
export async function reverseJournalEntry(journalEntryId: string, payload: unknown, actor: UserContext, idempotencyKey: string, correlationId: string, requestedOrganisationId?: string | null) {
  validateIdempotencyKey(idempotencyKey);
  const input = normalizeAndValidateJournalReversal(payload);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const original = await db.prepare("SELECT * FROM journal_entries WHERE id=? AND organisation_id=?").bind(journalEntryId, organisation.id).first<Record<string, string>>();
  if (!original) throw new BusinessResourceError("Journal entry was not found in the authorised organisation.", 404);
  const requestHash = await sha256Hex(stableStringify({ organisation_id: organisation.id, journal_entry_id: journalEntryId, input }));
  const prior = await priorCommand(db, actor.userId, "REVERSE_JOURNAL_ENTRY", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM journal_entries WHERE id=? AND organisation_id=?").bind(prior, organisation.id).first<Record<string, unknown>>();
  if (original.status !== "POSTED") throw new RepositoryConflictError(`Only a posted journal entry can be reversed; ${journalEntryId} is currently ${original.status}.`);
  const alreadyReversed = await db.prepare("SELECT id FROM journal_entries WHERE reverses_journal_entry_id=?").bind(journalEntryId).first<{ id: string }>();
  if (alreadyReversed) throw new RepositoryConflictError(`This journal entry was already reversed as ${alreadyReversed.id}.`);
  const now = new Date().toISOString();
  const journalDate = now.slice(0, 10);
  await assertPeriodOpen(db, organisation.id, journalDate);
  const originalLines = await db.prepare("SELECT * FROM journal_lines WHERE journal_entry_id=? ORDER BY line_number").bind(journalEntryId).all<Record<string, string | number | null>>();
  const id = crypto.randomUUID();
  const journalNumber = `${original.journal_number}-REV`;
  const audit = await auditEnvelope(db, actor, "JOURNAL_REVERSED", "JOURNAL", id, { organisationId: organisation.id, reversesJournalEntryId: journalEntryId, reason: input.reason, correlationId }, now);
  const statements: D1PreparedStatement[] = [
    db.prepare(`INSERT INTO journal_entries
      (id,organisation_id,journal_number,journal_date,reference,description,currency,status,source_type,source_id,created_by,posted_by,created_at,posted_at,reverses_journal_entry_id)
      VALUES (?,?,?,?,?,?,?,'POSTED','ADJUSTMENT',?,?,?,?,?,?)`)
      .bind(id, organisation.id, journalNumber, journalDate, original.journal_number, `Reversal of ${original.journal_number}: ${input.reason}`, original.currency, journalEntryId, actor.userId, actor.userId, now, now, journalEntryId),
    db.prepare("UPDATE journal_entries SET status='REVERSED' WHERE id=?").bind(journalEntryId),
    commandRecord(db, actor.userId, "REVERSE_JOURNAL_ENTRY", idempotencyKey, requestHash, "JOURNAL", id, now),
    outboxRecord(db, "JOURNAL", id, "JournalReversed", organisation.id, { journal_id: id, reverses_journal_entry_id: journalEntryId, organisation_id: organisation.id, correlation_id: correlationId }, now),
    auditRecord(db, actor, audit, now),
  ];
  originalLines.results.forEach((line, index) => statements.push(db.prepare(`INSERT INTO journal_lines
    (id,journal_entry_id,line_number,account_id,branch_id,project_id,description,debit_cents,credit_cents,tax_code)
    VALUES (?,?,?,?,?,?,?,?,?,?)`).bind(
    crypto.randomUUID(), id, index + 1, line.account_id, line.branch_id, line.project_id,
    `Reversal: ${line.description}`, line.credit_cents, line.debit_cents, line.tax_code,
  )));
  await db.batch(statements);
  return db.prepare("SELECT * FROM journal_entries WHERE id=?").bind(id).first<Record<string, unknown>>();
}

/**
 * Module 5 Phase C ClosePeriod. Idempotent on an already-closed period —
 * re-closing is a no-op success, not an error, matching this codebase's
 * established idempotent-on-already-satisfied pattern (e.g.
 * markObligationSatisfied). A period only gains a row once it's actually
 * closed; there is no separate "create/open a period" command, so an
 * un-closed period simply has no row and postings proceed unchecked.
 */
export async function closeAccountingPeriod(payload: unknown, actor: UserContext, idempotencyKey: string, correlationId: string, requestedOrganisationId?: string | null) {
  validateIdempotencyKey(idempotencyKey);
  const input = normalizeAndValidatePeriodClose(payload);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const requestHash = await sha256Hex(stableStringify({ organisation_id: organisation.id, input }));
  const prior = await priorCommand(db, actor.userId, "CLOSE_ACCOUNTING_PERIOD", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM accounting_periods WHERE id=? AND organisation_id=?").bind(prior, organisation.id).first<Record<string, unknown>>();
  const [year, month] = input.period_code.split("-").map(Number);
  const periodStart = `${input.period_code}-01`;
  const periodEndDate = new Date(Date.UTC(year, month, 0));
  const periodEnd = periodEndDate.toISOString().slice(0, 10);
  const today = new Date().toISOString().slice(0, 10);
  if (periodEnd > today) throw new RepositoryConflictError("A period cannot be closed before it has ended.");
  const existing = await db.prepare("SELECT * FROM accounting_periods WHERE organisation_id=? AND period_code=?").bind(organisation.id, input.period_code).first<Record<string, unknown>>();
  if (existing?.status === "CLOSED") return existing;
  const now = new Date().toISOString();
  const id = (existing?.id as string | undefined) ?? crypto.randomUUID();
  const audit = await auditEnvelope(db, actor, "ACCOUNTING_PERIOD_CLOSED", "ACCOUNTING_PERIOD", id, { organisationId: organisation.id, periodCode: input.period_code, correlationId }, now);
  await db.batch([
    existing
      ? db.prepare("UPDATE accounting_periods SET status='CLOSED', closed_by=?, closed_at=? WHERE id=?").bind(actor.userId, now, id)
      : db.prepare(`INSERT INTO accounting_periods (id,organisation_id,period_code,period_start,period_end,status,closed_by,closed_at,created_at)
          VALUES (?,?,?,?,?,'CLOSED',?,?,?)`).bind(id, organisation.id, input.period_code, periodStart, periodEnd, actor.userId, now, now),
    commandRecord(db, actor.userId, "CLOSE_ACCOUNTING_PERIOD", idempotencyKey, requestHash, "ACCOUNTING_PERIOD", id, now),
    outboxRecord(db, "ACCOUNTING_PERIOD", id, "AccountingPeriodClosed", organisation.id, { period_id: id, period_code: input.period_code, organisation_id: organisation.id, correlation_id: correlationId }, now),
    auditRecord(db, actor, audit, now),
  ]);
  return db.prepare("SELECT * FROM accounting_periods WHERE id=?").bind(id).first<Record<string, unknown>>();
}

/**
 * Module 5 Phase C TrialBalance. Sums every journal_lines row regardless of
 * its parent journal_entries.status — a REVERSED original's lines are real
 * historical postings, and the reversal's opposite lines net them to zero
 * arithmetically; excluding REVERSED entries would double-count the
 * reversal's own correcting effect. as_of bounds journal_date, not
 * created_at, matching how a trial balance is normally read "as of" a date.
 */
export async function getTrialBalance(actor: UserContext, requestedOrganisationId?: string | null, asOf?: string) {
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const asOfDate = asOf ?? new Date().toISOString().slice(0, 10);
  const rows = await db.prepare(`SELECT a.id AS account_id, a.code, a.name, a.account_type,
      COALESCE(SUM(l.debit_cents),0) AS total_debit_cents, COALESCE(SUM(l.credit_cents),0) AS total_credit_cents
    FROM chart_of_accounts a
    LEFT JOIN journal_lines l ON l.account_id=a.id
    LEFT JOIN journal_entries j ON j.id=l.journal_entry_id AND j.journal_date<=?
    WHERE a.organisation_id=? AND a.status='ACTIVE'
    GROUP BY a.id ORDER BY a.code`).bind(asOfDate, organisation.id).all<{
    account_id: string; code: string; name: string; account_type: AccountType; total_debit_cents: number; total_credit_cents: number;
  }>();
  const accounts = rows.results.map((row) => ({ ...row, balance_cents: row.total_debit_cents - row.total_credit_cents }));
  const totalDebitCents = accounts.reduce((sum, row) => sum + row.total_debit_cents, 0);
  const totalCreditCents = accounts.reduce((sum, row) => sum + row.total_credit_cents, 0);
  return { organisation_id: organisation.id, as_of: asOfDate, accounts, total_debit_cents: totalDebitCents, total_credit_cents: totalCreditCents, balanced: totalDebitCents === totalCreditCents };
}

/**
 * Module 5 Phase C Statements. A deliberately simplified pair of reports,
 * proportionate to this module's "lighter CRUD standard" watch-out — not a
 * full general-ledger closing cycle:
 *  - Income statement: revenue minus expense, summed over [from, to] by
 *    journal_date.
 *  - Balance sheet: asset/liability/equity account balances as of `to`,
 *    plus the same-range net income folded in as a computed "retained
 *    earnings" line — there is no period-end closing journal that actually
 *    zeroes revenue/expense into equity, so this stays a live computed
 *    view rather than a posted closing entry. `balanced` should always be
 *    true given the underlying double-entry invariant; it's surfaced
 *    explicitly so a caller can see the check was made, not just assume it.
 */
export async function getFinancialStatements(actor: UserContext, requestedOrganisationId: string | null | undefined, from: string, to: string) {
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const rows = await db.prepare(`SELECT a.account_type, COALESCE(SUM(l.debit_cents),0) AS total_debit_cents, COALESCE(SUM(l.credit_cents),0) AS total_credit_cents
    FROM chart_of_accounts a
    LEFT JOIN journal_lines l ON l.account_id=a.id
    LEFT JOIN journal_entries j ON j.id=l.journal_entry_id AND j.journal_date BETWEEN ? AND ?
    WHERE a.organisation_id=? AND a.status='ACTIVE'
    GROUP BY a.account_type`).bind(from, to, organisation.id).all<{ account_type: AccountType; total_debit_cents: number; total_credit_cents: number }>();
  const byType = Object.fromEntries(rows.results.map((row) => [row.account_type, row]));
  const revenueCents = (byType.REVENUE?.total_credit_cents ?? 0) - (byType.REVENUE?.total_debit_cents ?? 0);
  const expenseCents = (byType.EXPENSE?.total_debit_cents ?? 0) - (byType.EXPENSE?.total_credit_cents ?? 0);
  const netIncomeCents = revenueCents - expenseCents;
  const assetCents = (byType.ASSET?.total_debit_cents ?? 0) - (byType.ASSET?.total_credit_cents ?? 0);
  const liabilityCents = (byType.LIABILITY?.total_credit_cents ?? 0) - (byType.LIABILITY?.total_debit_cents ?? 0);
  const equityCents = (byType.EQUITY?.total_credit_cents ?? 0) - (byType.EQUITY?.total_debit_cents ?? 0);
  return {
    organisation_id: organisation.id,
    from,
    to,
    income_statement: { revenue_cents: revenueCents, expense_cents: expenseCents, net_income_cents: netIncomeCents },
    balance_sheet: {
      assets_cents: assetCents,
      liabilities_cents: liabilityCents,
      equity_cents: equityCents,
      retained_earnings_cents: netIncomeCents,
      total_liabilities_and_equity_cents: liabilityCents + equityCents + netIncomeCents,
      balanced: assetCents === liabilityCents + equityCents + netIncomeCents,
    },
  };
}

/** Module 5 Phase E CreateExpenseCategory. expense_categories was previously seed-only, mirroring chart_of_accounts before Phase C's CreateAccount — same fix, same reasoning. */
export async function createExpenseCategory(payload: unknown, actor: UserContext, idempotencyKey: string, correlationId: string, requestedOrganisationId?: string | null) {
  validateIdempotencyKey(idempotencyKey);
  const category = normalizeAndValidateExpenseCategory(payload);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const requestHash = await sha256Hex(stableStringify({ organisation_id: organisation.id, category }));
  const prior = await priorCommand(db, actor.userId, "CREATE_EXPENSE_CATEGORY", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM expense_categories WHERE id=? AND organisation_id=?").bind(prior, organisation.id).first<Record<string, unknown>>();
  const existing = await db.prepare("SELECT id,name FROM expense_categories WHERE organisation_id=? AND code=?").bind(organisation.id, category.code).first<{ id: string; name: string }>();
  if (existing) throw new RepositoryConflictError(`Category code ${category.code} is already in use (${existing.name}, ${existing.id}).`);
  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  const audit = await auditEnvelope(db, actor, "EXPENSE_CATEGORY_CREATED", "EXPENSE_CATEGORY", id, { organisationId: organisation.id, code: category.code, correlationId }, now);
  await db.batch([
    db.prepare(`INSERT INTO expense_categories (id,organisation_id,code,name,default_tax_category,requires_receipt,status,created_at)
      VALUES (?,?,?,?,?,?,'ACTIVE',?)`).bind(id, organisation.id, category.code, category.name, category.default_tax_category, category.requires_receipt ? 1 : 0, now),
    commandRecord(db, actor.userId, "CREATE_EXPENSE_CATEGORY", idempotencyKey, requestHash, "EXPENSE_CATEGORY", id, now),
    outboxRecord(db, "EXPENSE_CATEGORY", id, "ExpenseCategoryCreated", organisation.id, { category_id: id, organisation_id: organisation.id, code: category.code, correlation_id: correlationId }, now),
    auditRecord(db, actor, audit, now),
  ]);
  return db.prepare("SELECT * FROM expense_categories WHERE id=?").bind(id).first<Record<string, unknown>>();
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
  await requirePartyRelationship(db, expense.supplier_party_id, organisation.id, "SUPPLIER", "Supplier party");
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

type ExpenseRow = { id: string; organisation_id: string; status: string; created_by: string; total_cents: number };

async function loadExpenseForTransition(db: D1Database, expenseId: string, organisationId: string): Promise<ExpenseRow> {
  const expense = await db.prepare("SELECT id,organisation_id,status,created_by,total_cents FROM expenses WHERE id=? AND organisation_id=?").bind(expenseId, organisationId).first<ExpenseRow>();
  if (!expense) throw new BusinessResourceError("Expense was not found in the authorised organisation.", 404);
  return expense;
}

/** Module 5 Phase E SubmitExpense: DRAFT -> SUBMITTED, the maker-checker gate's starting line. */
export async function submitExpense(expenseId: string, actor: UserContext, idempotencyKey: string, correlationId: string, requestedOrganisationId?: string | null) {
  validateIdempotencyKey(idempotencyKey);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const expense = await loadExpenseForTransition(db, expenseId, organisation.id);
  const requestHash = await sha256Hex(stableStringify({ organisation_id: organisation.id, expense_id: expenseId, action: "SUBMIT" }));
  const prior = await priorCommand(db, actor.userId, "SUBMIT_EXPENSE", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM expenses WHERE id=? AND organisation_id=?").bind(prior, organisation.id).first<Record<string, unknown>>();
  if (expense.status !== "DRAFT") throw new RepositoryConflictError(`Only a draft expense can be submitted; ${expenseId} is currently ${expense.status}.`);
  const now = new Date().toISOString();
  const audit = await auditEnvelope(db, actor, "EXPENSE_SUBMITTED", "EXPENSE", expenseId, { organisationId: organisation.id, correlationId }, now);
  await db.batch([
    db.prepare("UPDATE expenses SET status='SUBMITTED' WHERE id=?").bind(expenseId),
    commandRecord(db, actor.userId, "SUBMIT_EXPENSE", idempotencyKey, requestHash, "EXPENSE", expenseId, now),
    outboxRecord(db, "EXPENSE", expenseId, "ExpenseSubmitted", organisation.id, { expense_id: expenseId, organisation_id: organisation.id, correlation_id: correlationId }, now),
    auditRecord(db, actor, audit, now),
  ]);
  return db.prepare("SELECT * FROM expenses WHERE id=?").bind(expenseId).first<Record<string, unknown>>();
}

/**
 * Module 5 Phase E ApproveExpense/RejectExpense share this: maker-checker
 * separation denies the expense's own creator from reviewing it, the same
 * "cannot review your own request" rule Module 3's reviewRefund already
 * established — enforced by an actor check, not a separate permission
 * tier, matching this module's lighter CRUD standard.
 */
function assertNotSelfReview(actor: UserContext, createdBy: string, action: string) {
  if (actor.userId === createdBy) throw new AccessDeniedError(`Maker-checker separation prevents ${action} an expense you created yourself.`);
}

export async function approveExpense(expenseId: string, actor: UserContext, idempotencyKey: string, correlationId: string, requestedOrganisationId?: string | null) {
  validateIdempotencyKey(idempotencyKey);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const expense = await loadExpenseForTransition(db, expenseId, organisation.id);
  const requestHash = await sha256Hex(stableStringify({ organisation_id: organisation.id, expense_id: expenseId, action: "APPROVE" }));
  const prior = await priorCommand(db, actor.userId, "APPROVE_EXPENSE", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM expenses WHERE id=? AND organisation_id=?").bind(prior, organisation.id).first<Record<string, unknown>>();
  if (expense.status !== "SUBMITTED") throw new RepositoryConflictError(`Only a submitted expense can be approved; ${expenseId} is currently ${expense.status}.`);
  assertNotSelfReview(actor, expense.created_by, "approving");
  const now = new Date().toISOString();
  const audit = await auditEnvelope(db, actor, "EXPENSE_APPROVED", "EXPENSE", expenseId, { organisationId: organisation.id, totalCents: expense.total_cents, correlationId }, now);
  await db.batch([
    db.prepare("UPDATE expenses SET status='APPROVED', approved_by=?, approved_at=? WHERE id=?").bind(actor.userId, now, expenseId),
    commandRecord(db, actor.userId, "APPROVE_EXPENSE", idempotencyKey, requestHash, "EXPENSE", expenseId, now),
    outboxRecord(db, "EXPENSE", expenseId, "ExpenseApproved", organisation.id, { expense_id: expenseId, organisation_id: organisation.id, correlation_id: correlationId }, now),
    auditRecord(db, actor, audit, now),
  ]);
  return db.prepare("SELECT * FROM expenses WHERE id=?").bind(expenseId).first<Record<string, unknown>>();
}

export async function rejectExpense(expenseId: string, payload: unknown, actor: UserContext, idempotencyKey: string, correlationId: string, requestedOrganisationId?: string | null) {
  validateIdempotencyKey(idempotencyKey);
  const input = normalizeAndValidateExpenseRejection(payload);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const expense = await loadExpenseForTransition(db, expenseId, organisation.id);
  const requestHash = await sha256Hex(stableStringify({ organisation_id: organisation.id, expense_id: expenseId, input }));
  const prior = await priorCommand(db, actor.userId, "REJECT_EXPENSE", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM expenses WHERE id=? AND organisation_id=?").bind(prior, organisation.id).first<Record<string, unknown>>();
  if (expense.status !== "SUBMITTED") throw new RepositoryConflictError(`Only a submitted expense can be rejected; ${expenseId} is currently ${expense.status}.`);
  assertNotSelfReview(actor, expense.created_by, "rejecting");
  const now = new Date().toISOString();
  const audit = await auditEnvelope(db, actor, "EXPENSE_REJECTED", "EXPENSE", expenseId, { organisationId: organisation.id, reason: input.reason, correlationId }, now);
  await db.batch([
    db.prepare("UPDATE expenses SET status='REJECTED', approved_by=?, approved_at=?, rejection_reason=? WHERE id=?").bind(actor.userId, now, input.reason, expenseId),
    commandRecord(db, actor.userId, "REJECT_EXPENSE", idempotencyKey, requestHash, "EXPENSE", expenseId, now),
    outboxRecord(db, "EXPENSE", expenseId, "ExpenseRejected", organisation.id, { expense_id: expenseId, organisation_id: organisation.id, reason: input.reason, correlation_id: correlationId }, now),
    auditRecord(db, actor, audit, now),
  ]);
  return db.prepare("SELECT * FROM expenses WHERE id=?").bind(expenseId).first<Record<string, unknown>>();
}

/** Module 5 Phase E ExpenseReport: totals by status and by category over [from, to], plus the matching line items. */
export async function getExpenseReport(actor: UserContext, requestedOrganisationId: string | null | undefined, from: string, to: string) {
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const [byStatus, byCategory, items] = await Promise.all([
    db.prepare(`SELECT status, COUNT(*) AS count, COALESCE(SUM(total_cents),0) AS total_cents FROM expenses
      WHERE organisation_id=? AND expense_date BETWEEN ? AND ? GROUP BY status`).bind(organisation.id, from, to).all<{ status: string; count: number; total_cents: number }>(),
    db.prepare(`SELECT c.id AS category_id, c.name AS category_name, COUNT(*) AS count, COALESCE(SUM(e.total_cents),0) AS total_cents
      FROM expenses e JOIN expense_categories c ON c.id=e.category_id
      WHERE e.organisation_id=? AND e.expense_date BETWEEN ? AND ? GROUP BY c.id ORDER BY total_cents DESC`).bind(organisation.id, from, to).all<{ category_id: string; category_name: string; count: number; total_cents: number }>(),
    db.prepare(`SELECT e.*, c.name AS category_name FROM expenses e JOIN expense_categories c ON c.id=e.category_id
      WHERE e.organisation_id=? AND e.expense_date BETWEEN ? AND ? ORDER BY e.expense_date DESC LIMIT 500`).bind(organisation.id, from, to).all<Record<string, unknown>>(),
  ]);
  const totalCents = byStatus.results.reduce((sum, row) => sum + row.total_cents, 0);
  return { organisation_id: organisation.id, from, to, total_cents: totalCents, by_status: byStatus.results, by_category: byCategory.results, items: items.results };
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
  await requirePartyRelationship(db, project.customer_party_id, organisation.id, "CUSTOMER", "Customer party");
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

type ProjectRow = { id: string; organisation_id: string; manager_user_id: string | null; currency: string };

async function loadProject(db: D1Database, projectId: string, organisationId: string): Promise<ProjectRow> {
  const project = await db.prepare("SELECT id,organisation_id,manager_user_id,currency FROM projects WHERE id=? AND organisation_id=?").bind(projectId, organisationId).first<ProjectRow>();
  if (!project) throw new BusinessResourceError("Project was not found in the authorised organisation.", 404);
  return project;
}

/**
 * Module 5 Phase E ApproveBudget. Acts on the project's one 'TOTAL' budget
 * row — the only category CreateProject ever inserts, so this doesn't
 * invent a multi-category budget-management surface the rest of the
 * codebase has no other support for. Maker-checker: the project's own
 * manager (set to whoever called CreateProject) cannot approve their own
 * project's budget, the same self-review rule Expense's Approve/Reject use.
 */
export async function approveProjectBudget(projectId: string, payload: unknown, actor: UserContext, idempotencyKey: string, correlationId: string, requestedOrganisationId?: string | null) {
  validateIdempotencyKey(idempotencyKey);
  const input = normalizeAndValidateProjectBudgetApproval(payload);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const project = await loadProject(db, projectId, organisation.id);
  const budget = await db.prepare("SELECT * FROM project_budgets WHERE project_id=? AND category='TOTAL'").bind(projectId).first<{ id: string; status: string }>();
  if (!budget) throw new BusinessResourceError("This project has no proposed budget to approve.", 404);
  const requestHash = await sha256Hex(stableStringify({ organisation_id: organisation.id, project_id: projectId, input }));
  const prior = await priorCommand(db, actor.userId, "APPROVE_PROJECT_BUDGET", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM project_budgets WHERE id=?").bind(prior).first<Record<string, unknown>>();
  if (budget.status !== "PROPOSED") throw new RepositoryConflictError(`Only a proposed budget can be approved; this project's budget is currently ${budget.status}.`);
  if (project.manager_user_id && actor.userId === project.manager_user_id) throw new AccessDeniedError("Maker-checker separation prevents the project's own manager from approving its budget.");
  const now = new Date().toISOString();
  const audit = await auditEnvelope(db, actor, "PROJECT_BUDGET_APPROVED", "PROJECT_BUDGET", budget.id, { organisationId: organisation.id, projectId, approvedAmountCents: input.approved_amount_cents, correlationId }, now);
  await db.batch([
    db.prepare("UPDATE project_budgets SET status='APPROVED', approved_amount_cents=?, approved_by=?, approved_at=? WHERE id=?").bind(input.approved_amount_cents, actor.userId, now, budget.id),
    commandRecord(db, actor.userId, "APPROVE_PROJECT_BUDGET", idempotencyKey, requestHash, "PROJECT_BUDGET", budget.id, now),
    outboxRecord(db, "PROJECT_BUDGET", budget.id, "ProjectBudgetApproved", organisation.id, { project_budget_id: budget.id, project_id: projectId, organisation_id: organisation.id, correlation_id: correlationId }, now),
    auditRecord(db, actor, audit, now),
  ]);
  return db.prepare("SELECT * FROM project_budgets WHERE id=?").bind(budget.id).first<Record<string, unknown>>();
}

/**
 * Module 5 Phase E PostCost. project_costs.UNIQUE(project_id, cost_type,
 * source_id) is what actually prevents the same EXPENSE from ever being
 * posted as a cost twice — this function's own pre-check exists only for a
 * clean 409 message ahead of that constraint, the same pre-check-plus-
 * constraint pattern used throughout this file.
 */
export async function postProjectCost(projectId: string, payload: unknown, actor: UserContext, idempotencyKey: string, correlationId: string, requestedOrganisationId?: string | null) {
  validateIdempotencyKey(idempotencyKey);
  const input = normalizeAndValidateProjectCost(payload);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  await loadProject(db, projectId, organisation.id);
  const requestHash = await sha256Hex(stableStringify({ organisation_id: organisation.id, project_id: projectId, input }));
  const prior = await priorCommand(db, actor.userId, "POST_PROJECT_COST", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM project_costs WHERE id=? AND project_id=?").bind(prior, projectId).first<Record<string, unknown>>();

  let amountCents: number;
  let currency: string;
  let occurredAt: string;
  let description: string | null;
  if (input.cost_type === "EXPENSE") {
    const expense = await db.prepare("SELECT id,status,total_cents,currency,expense_date,description,project_id FROM expenses WHERE id=? AND organisation_id=?")
      .bind(input.source_id, organisation.id).first<{ id: string; status: string; total_cents: number; currency: string; expense_date: string; description: string; project_id: string | null }>();
    if (!expense) throw new BusinessResourceError("The cited expense was not found in the authorised organisation.", 404);
    if (expense.status !== "APPROVED") throw new RepositoryConflictError("Only an approved expense can be posted as a project cost.");
    if (expense.project_id !== projectId) throw new RepositoryConflictError("This expense is not tagged to the project it's being posted against.");
    const alreadyPosted = await db.prepare("SELECT id FROM project_costs WHERE project_id=? AND cost_type='EXPENSE' AND source_id=?").bind(projectId, input.source_id).first<{ id: string }>();
    if (alreadyPosted) throw new RepositoryConflictError(`This expense was already posted as project cost ${alreadyPosted.id}.`);
    amountCents = expense.total_cents;
    currency = expense.currency;
    occurredAt = expense.expense_date;
    description = expense.description;
  } else {
    amountCents = input.amount_cents;
    currency = input.currency;
    occurredAt = input.occurred_at;
    description = input.description;
  }

  const id = crypto.randomUUID();
  const now = new Date().toISOString();
  const audit = await auditEnvelope(db, actor, "PROJECT_COST_POSTED", "PROJECT_COST", id, { organisationId: organisation.id, projectId, costType: input.cost_type, amountCents, correlationId }, now);
  try {
    await db.batch([
      db.prepare(`INSERT INTO project_costs (id,project_id,cost_type,source_id,amount_cents,currency,description,occurred_at,created_by,created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?)`).bind(id, projectId, input.cost_type, input.source_id, amountCents, currency, description, occurredAt, actor.userId, now),
      commandRecord(db, actor.userId, "POST_PROJECT_COST", idempotencyKey, requestHash, "PROJECT_COST", id, now),
      outboxRecord(db, "PROJECT_COST", id, "ProjectCostPosted", organisation.id, { project_cost_id: id, project_id: projectId, cost_type: input.cost_type, correlation_id: correlationId }, now),
      auditRecord(db, actor, audit, now),
    ]);
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    if (!/unique constraint failed/i.test(message)) throw error;
    throw new RepositoryConflictError("This source was already posted as a project cost — supersession is not supported, only a single posting per source.");
  }
  return db.prepare("SELECT * FROM project_costs WHERE id=?").bind(id).first<Record<string, unknown>>();
}

/**
 * Module 5 Phase E ProfitabilityReport. Cost and budget were already
 * available (getBusinessPlatformSnapshot's dashboard rollup computed
 * both); revenue is new here, reusing Module 5 Phase C's accounting
 * infrastructure rather than inventing a second revenue concept — REVENUE-
 * type journal_lines already carry project_id, so this sums exactly the
 * postings an accountant tagged to this project, nothing re-derived.
 */
export async function getProjectProfitability(projectId: string, actor: UserContext, requestedOrganisationId?: string | null) {
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const project = await loadProject(db, projectId, organisation.id);
  const [budget, costs, revenue] = await Promise.all([
    db.prepare("SELECT * FROM project_budgets WHERE project_id=? AND category='TOTAL'").bind(projectId).first<Record<string, unknown>>(),
    db.prepare("SELECT COALESCE(SUM(amount_cents),0) AS total_cents FROM project_costs WHERE project_id=?").bind(projectId).first<{ total_cents: number }>(),
    db.prepare(`SELECT COALESCE(SUM(l.credit_cents),0) - COALESCE(SUM(l.debit_cents),0) AS net_cents
      FROM journal_lines l JOIN chart_of_accounts a ON a.id=l.account_id WHERE l.project_id=? AND a.account_type='REVENUE'`).bind(projectId).first<{ net_cents: number }>(),
  ]);
  const costCents = costs?.total_cents ?? 0;
  const revenueCents = revenue?.net_cents ?? 0;
  return {
    project_id: projectId,
    currency: project.currency,
    budget,
    revenue_cents: revenueCents,
    cost_cents: costCents,
    profit_cents: revenueCents - costCents,
  };
}
