import { ensureDatabase } from "@/db/runtime";
import { AccessDeniedError, isNationalScope } from "@/lib/auth";
import {
  evaluateExpenseDecision,
  evaluateQuotationLifecycle,
  normalizeAndValidateBusinessParty,
  normalizeAndValidateBusinessPartyDeactivation,
  normalizeAndValidateExpense,
  normalizeAndValidateExpenseDecision,
  normalizeAndValidateExpenseReceiptLink,
  normalizeAndValidateJournal,
  normalizeAndValidateProject,
  normalizeAndValidateQuotation,
  normalizeAndValidateQuotationConversion,
  normalizeAndValidateQuotationRejection,
  normalizeAndValidateStockMovement,
  type BusinessPartyDeactivationSubmission,
  type BusinessPartyRelationship,
  type BusinessPartySubmission,
  type ExpenseSubmission,
  type ExpenseDecisionSubmission,
  type ExpenseReceiptLinkSubmission,
  type JournalSubmission,
  type NormalizedQuotation,
  type ProjectSubmission,
  type QuotationRejectionSubmission,
  type QuotationSubmission,
  type QuotationConversionSubmission,
  type StockMovementSubmission,
} from "@/lib/domain/business";
import { centsToDecimal, sha256Hex, stableStringify } from "@/lib/domain/invoice";
import {
  evaluateCounterpartyTrust,
  normalizeSyntheticCounterpartyVerification,
  type SyntheticCounterpartyVerificationSubmission,
} from "@/lib/domain/counterparty-trust";
import type { InvoiceSubmission, UserContext } from "@/lib/domain/types";
import type { RequestContext } from "@/lib/security/request";
import { getInvoiceById, RepositoryConflictError, submitInvoice } from "./repository";

type OrganisationContext = { id: string; taxpayer_id: string; legal_name: string; vat_number: string };
type IdempotencyRow = { request_hash: string; resource_id: string };

type ExpenseRecord = {
  id: string;
  organisation_id: string;
  expense_number: string;
  status: string;
  total_cents: number;
  created_by: string;
  requires_receipt: number;
  receipt_document_id: string | null;
  receipt_scan_status: string | null;
  receipt_status: string | null;
};

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
  requireActiveTaxRegistration = false,
) {
  if (!id) return;
  const row = await db.prepare(`SELECT p.id,t.trust_status,t.provider_environment,t.tax_registration_status,t.expires_at FROM business_parties p
    JOIN party_relationships r ON r.party_id=p.id AND r.organisation_id=p.organisation_id
    LEFT JOIN counterparty_trust_profiles t ON t.business_party_id=p.id
    WHERE p.id=? AND p.organisation_id=? AND p.status='ACTIVE' AND r.relationship=? AND r.status='ACTIVE'`)
    .bind(id, organisationId, relationship).first<{ id: string; trust_status: string | null; provider_environment: string | null; tax_registration_status: string | null; expires_at: string | null }>();
  if (!row) throw new BusinessResourceError(`${label} is not an active ${relationship.toLowerCase()} in the authorised organisation.`);
  const current = Boolean(row.expires_at && Date.parse(row.expires_at) > Date.now());
  const authorityTrusted = row.trust_status === "AUTHORITY_VERIFIED" && current;
  const deployment = (process.env.VAT_MSA_ENVIRONMENT ?? "local").trim().toLowerCase();
  const syntheticEnabled = deployment !== "production" && (process.env.NODE_ENV !== "production" || (deployment === "staging" && process.env.VAT_MSA_ENABLE_SYNTHETIC_COUNTERPARTY_TRUST === "true"));
  const syntheticTrusted = syntheticEnabled && row.trust_status === "SYNTHETIC_VALID" && row.provider_environment === "SYNTHETIC_TEST" && current;
  if (!authorityTrusted && !syntheticTrusted) throw new BusinessResourceError(`${label} is not currently trusted for new transactions. Complete an approved counterparty verification first.`);
  if (requireActiveTaxRegistration && row.tax_registration_status !== "ACTIVE") throw new BusinessResourceError(`${label} does not have current ACTIVE tax-registration evidence for a tax-bearing transaction.`);
}

async function getBusinessParty(db: D1Database, id: string, organisationId: string) {
  return db.prepare(`SELECT p.*,GROUP_CONCAT(r.relationship, ',') AS relationships,
    t.trust_status,t.tax_registration_status,t.vat_verification_status,t.tin_verification_status,
    t.company_verification_status,t.confidence_bps,t.provider_environment,t.checked_at,t.expires_at
    FROM business_parties p
    LEFT JOIN party_relationships r ON r.party_id=p.id AND r.organisation_id=p.organisation_id AND r.status='ACTIVE'
    LEFT JOIN counterparty_trust_profiles t ON t.business_party_id=p.id
    WHERE p.id=? AND p.organisation_id=? GROUP BY p.id`).bind(id, organisationId).first<Record<string, unknown>>();
}

async function assertBusinessPartyIdentifiersAvailable(
  db: D1Database,
  organisationId: string,
  party: BusinessPartySubmission,
  excludedId?: string,
) {
  if (!party.vat_number && !party.tin && !party.company_registration_number) return;
  const duplicate = await db.prepare(`SELECT id,display_name FROM business_parties
    WHERE organisation_id=? AND status='ACTIVE' AND id<>COALESCE(?, '')
      AND ((? IS NOT NULL AND vat_number=?) OR (? IS NOT NULL AND tin=?)
        OR (? IS NOT NULL AND company_registration_number=?)) LIMIT 1`)
    .bind(organisationId, excludedId ?? null, party.vat_number ?? null, party.vat_number ?? null, party.tin ?? null, party.tin ?? null,
      party.company_registration_number ?? null, party.company_registration_number ?? null)
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
      (SELECT COALESCE(SUM(total_cents),0) FROM expenses WHERE organisation_id=? AND status='APPROVED') AS expense_value_cents`)
      .bind(org, org, org, org, org, org).first<Record<string, number>>(),
    db.prepare(`SELECT p.*,GROUP_CONCAT(r.relationship, ',') AS relationships,
      t.trust_status,t.tax_registration_status,t.vat_verification_status,t.tin_verification_status,
      t.company_verification_status,t.confidence_bps,t.provider_environment,t.checked_at,t.expires_at
      FROM business_parties p
      LEFT JOIN party_relationships r ON r.party_id=p.id AND r.status='ACTIVE'
      LEFT JOIN counterparty_trust_profiles t ON t.business_party_id=p.id
      WHERE p.organisation_id=? GROUP BY p.id ORDER BY p.display_name LIMIT 100`).bind(org).all<Record<string, string | null>>(),
    db.prepare("SELECT * FROM products WHERE organisation_id=? ORDER BY name LIMIT 100").bind(org).all<Record<string, string | number | null>>(),
    db.prepare(`SELECT q.*,p.display_name AS customer_name FROM quotations q JOIN business_parties p ON p.id=q.customer_party_id
      WHERE q.organisation_id=? ORDER BY q.issue_date DESC,q.created_at DESC LIMIT 100`).bind(org).all<Record<string, string | number | null>>(),
    db.prepare("SELECT * FROM chart_of_accounts WHERE organisation_id=? ORDER BY code LIMIT 200").bind(org).all<Record<string, string | null>>(),
    db.prepare("SELECT * FROM journal_entries WHERE organisation_id=? ORDER BY journal_date DESC,created_at DESC LIMIT 100").bind(org).all<Record<string, string | null>>(),
    db.prepare(`SELECT e.*,c.name AS category_name,c.requires_receipt,p.display_name AS supplier_name,
      d.file_name AS receipt_file_name,d.scan_status AS receipt_scan_status,d.status AS receipt_status,
      (SELECT candidate.id FROM document_metadata candidate
        WHERE candidate.organisation_id=e.organisation_id AND candidate.owner_domain='EXPENSE'
          AND candidate.owner_resource_id=e.id AND candidate.scan_status='CLEAN' AND candidate.status='AVAILABLE'
          AND NOT EXISTS (SELECT 1 FROM expense_receipt_links linked WHERE linked.document_id=candidate.id)
        ORDER BY candidate.uploaded_at DESC LIMIT 1) AS available_receipt_document_id,
      (SELECT candidate.file_name FROM document_metadata candidate
        WHERE candidate.organisation_id=e.organisation_id AND candidate.owner_domain='EXPENSE'
          AND candidate.owner_resource_id=e.id AND candidate.scan_status='CLEAN' AND candidate.status='AVAILABLE'
          AND NOT EXISTS (SELECT 1 FROM expense_receipt_links linked WHERE linked.document_id=candidate.id)
        ORDER BY candidate.uploaded_at DESC LIMIT 1) AS available_receipt_file_name
      FROM expenses e JOIN expense_categories c ON c.id=e.category_id
      LEFT JOIN business_parties p ON p.id=e.supplier_party_id
      LEFT JOIN document_metadata d ON d.id=e.receipt_document_id
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
  const trustProfileId = crypto.randomUUID();
  const now = new Date().toISOString();
  const audit = await auditEnvelope(db, actor, "BUSINESS_PARTY_CREATED", "BUSINESS_PARTY", id, {
    organisationId: organisation.id,
    relationships: party.relationships,
    correlationId,
  }, now);
  const statements: D1PreparedStatement[] = [
    db.prepare(`INSERT INTO business_parties
      (id,organisation_id,display_name,legal_name,vat_number,tin,company_registration_number,email,phone,address,source_system,source_party_id,status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,'LOCAL',NULL,'ACTIVE',?,?)`).bind(
      id, organisation.id, party.display_name, party.legal_name ?? null, party.vat_number ?? null, party.tin ?? null, party.company_registration_number ?? null,
      party.email ?? null, party.phone ?? null, party.address ?? null, now, now,
    ),
    db.prepare(`INSERT INTO counterparty_trust_profiles
      (id,business_party_id,provider,provider_environment,trust_status,tax_registration_status,vat_verification_status,
       tin_verification_status,company_verification_status,confidence_bps,evidence_hash,source_reference,requested_by,reviewed_by,
       checked_at,expires_at,created_at,updated_at)
      VALUES (?,?,'ITAS_BIPA','CONTRACT_PENDING','PENDING_PROVIDER','UNKNOWN',?,?,?,0,NULL,NULL,?,NULL,NULL,NULL,?,?)`).bind(
      trustProfileId, id, party.vat_number ? "PENDING" : "NOT_PROVIDED", party.tin ? "PENDING" : "NOT_PROVIDED",
      party.company_registration_number ? "PENDING" : "NOT_PROVIDED", actor.userId, now, now,
    ),
    db.prepare(`INSERT INTO counterparty_trust_events
      (id,trust_profile_id,event_type,from_status,to_status,reason_code,evidence_hash,actor_id,occurred_at)
      VALUES (?,?,'CounterpartyVerificationRequested',NULL,'PENDING_PROVIDER','AUTHORITY_PROVIDER_CONTRACT_REQUIRED',NULL,?,?)`)
      .bind(crypto.randomUUID(), trustProfileId, actor.userId, now),
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
  statements.push(outboxRecord(db, "COUNTERPARTY_TRUST", trustProfileId, "CounterpartyVerificationRequested", organisation.id, {
    business_party_id: id, trust_profile_id: trustProfileId, status: "PENDING_PROVIDER", provider_environment: "CONTRACT_PENDING", correlation_id: correlationId,
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
  const existing = await db.prepare(`SELECT p.id,p.status,p.legal_name,p.vat_number,p.tin,p.company_registration_number,
    t.id AS trust_profile_id,t.trust_status FROM business_parties p LEFT JOIN counterparty_trust_profiles t ON t.business_party_id=p.id
    WHERE p.id=? AND p.organisation_id=?`)
    .bind(id, organisation.id).first<{ id: string; status: string; legal_name: string | null; vat_number: string | null; tin: string | null; company_registration_number: string | null; trust_profile_id: string | null; trust_status: string | null }>();
  if (!existing) throw new BusinessResourceError("Business party was not found in the authorised organisation.", 404);
  if (existing.status !== "ACTIVE") throw new RepositoryConflictError("An inactive business party cannot be edited. Create a new active relationship record if trading resumes.");
  await assertBusinessPartyIdentifiersAvailable(db, organisation.id, party, id);
  const identityChanged = (existing.legal_name ?? "") !== (party.legal_name ?? "")
    || (existing.vat_number ?? "") !== (party.vat_number ?? "")
    || (existing.tin ?? "") !== (party.tin ?? "")
    || (existing.company_registration_number ?? "") !== (party.company_registration_number ?? "");

  const now = new Date().toISOString();
  const audit = await auditEnvelope(db, actor, "BUSINESS_PARTY_UPDATED", "BUSINESS_PARTY", id, {
    organisationId: organisation.id,
    relationships: party.relationships,
    correlationId,
  }, now);
  const statements: D1PreparedStatement[] = [
    db.prepare(`UPDATE business_parties SET display_name=?,legal_name=?,vat_number=?,tin=?,company_registration_number=?,email=?,phone=?,address=?,updated_at=?
      WHERE id=? AND organisation_id=? AND status='ACTIVE'`).bind(
      party.display_name, party.legal_name ?? null, party.vat_number ?? null, party.tin ?? null, party.company_registration_number ?? null,
      party.email ?? null, party.phone ?? null, party.address ?? null, now, id, organisation.id,
    ),
  ];
  if (identityChanged && existing.trust_profile_id) {
    statements.unshift(db.prepare(`UPDATE counterparty_trust_profiles SET provider='ITAS_BIPA',provider_environment='CONTRACT_PENDING',
      trust_status='PENDING_PROVIDER',tax_registration_status='UNKNOWN',vat_verification_status=?,tin_verification_status=?,
      company_verification_status=?,confidence_bps=0,evidence_hash=NULL,source_reference=NULL,reviewed_by=NULL,checked_at=NULL,
      expires_at=NULL,updated_at=? WHERE id=?`).bind(
      party.vat_number ? "PENDING" : "NOT_PROVIDED", party.tin ? "PENDING" : "NOT_PROVIDED",
      party.company_registration_number ? "PENDING" : "NOT_PROVIDED", now, existing.trust_profile_id,
    ));
    statements.push(db.prepare(`INSERT INTO counterparty_trust_events
      (id,trust_profile_id,event_type,from_status,to_status,reason_code,evidence_hash,actor_id,occurred_at)
      VALUES (?,?,'CounterpartyIdentityChanged',?,'PENDING_PROVIDER','IDENTITY_CHANGE_REQUIRES_REVERIFICATION',NULL,?,?)`)
      .bind(crypto.randomUUID(), existing.trust_profile_id, existing.trust_status ?? "PENDING_PROVIDER", actor.userId, now));
    statements.push(outboxRecord(db, "COUNTERPARTY_TRUST", existing.trust_profile_id, "CounterpartyVerificationRequested", organisation.id, {
      business_party_id: id, trust_profile_id: existing.trust_profile_id, status: "PENDING_PROVIDER", reason: "IDENTITY_CHANGED", correlation_id: correlationId,
    }, now));
  }
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

export async function syntheticallyVerifyBusinessParty(
  id: string,
  payload: SyntheticCounterpartyVerificationSubmission,
  actor: UserContext,
  idempotencyKey: string,
  correlationId: string,
  requestedOrganisationId?: string | null,
) {
  validateIdempotencyKey(idempotencyKey);
  const deployment = (process.env.VAT_MSA_ENVIRONMENT ?? "local").trim().toLowerCase();
  const enabled = deployment !== "production" && (process.env.NODE_ENV !== "production" || (deployment === "staging" && process.env.VAT_MSA_ENABLE_SYNTHETIC_COUNTERPARTY_TRUST === "true"));
  if (!enabled) throw new BusinessResourceError("Synthetic counterparty verification is disabled in this environment.", 403);
  const submission = normalizeSyntheticCounterpartyVerification(payload);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const requestHash = await sha256Hex(stableStringify({ organisation_id: organisation.id, party_id: id, submission }));
  const prior = await priorCommand(db, actor.userId, "SYNTHETIC_VERIFY_BUSINESS_PARTY", idempotencyKey, requestHash);
  if (prior) return getBusinessParty(db, prior, organisation.id);
  const party = await db.prepare(`SELECT p.id,p.status,p.legal_name,p.display_name,p.vat_number,p.tin,p.company_registration_number,
    t.id AS trust_profile_id,t.trust_status FROM business_parties p
    JOIN counterparty_trust_profiles t ON t.business_party_id=p.id
    WHERE p.id=? AND p.organisation_id=?`).bind(id, organisation.id).first<{
      id: string; status: string; legal_name: string | null; display_name: string; vat_number: string | null; tin: string | null;
      company_registration_number: string | null; trust_profile_id: string; trust_status: string;
    }>();
  if (!party) throw new BusinessResourceError("Business party was not found in the authorised organisation.", 404);
  if (party.status !== "ACTIVE") throw new RepositoryConflictError("Only an active business party can enter synthetic verification.");
  const evaluation = evaluateCounterpartyTrust({
    legalName: party.legal_name ?? party.display_name,
    vatNumber: party.vat_number,
    tin: party.tin,
    companyRegistrationNumber: party.company_registration_number,
  }, submission.authority_record);
  const now = new Date();
  const checkedAt = now.toISOString();
  const expiresAt = new Date(now.getTime() + 24 * 60 * 60 * 1_000).toISOString();
  const sourceReference = `synthetic-counterparty:${crypto.randomUUID()}`;
  const evidenceHash = await sha256Hex(stableStringify({ sourceReference, businessPartyId: id, authority: submission.authority_record, evaluation, checkedAt, expiresAt }));
  const audit = await auditEnvelope(db, actor, "COUNTERPARTY_SYNTHETIC_VERIFICATION_RECORDED", "BUSINESS_PARTY", id, {
    organisationId: organisation.id,
    trustStatus: evaluation.trustStatus,
    reasonCode: evaluation.reasonCode,
    correlationId,
    nonAuthoritative: true,
  }, checkedAt);
  await db.batch([
    db.prepare(`UPDATE counterparty_trust_profiles SET provider='SYNTHETIC_AUTHORITY',provider_environment='SYNTHETIC_TEST',
      trust_status=?,tax_registration_status=?,vat_verification_status=?,tin_verification_status=?,company_verification_status=?,
      confidence_bps=?,evidence_hash=?,source_reference=?,reviewed_by=NULL,checked_at=?,expires_at=?,updated_at=? WHERE id=?`).bind(
      evaluation.trustStatus, evaluation.taxRegistrationStatus, evaluation.vatVerificationStatus, evaluation.tinVerificationStatus,
      evaluation.companyVerificationStatus, evaluation.confidenceBps, evidenceHash, sourceReference, checkedAt, expiresAt, checkedAt, party.trust_profile_id,
    ),
    db.prepare(`INSERT INTO counterparty_verification_snapshots
      (id,trust_profile_id,provider,provider_environment,source_reference,observed_vat_number,observed_tin,
       observed_company_registration_number,tax_registration_status,trust_status,confidence_bps,matched_fields,conflicting_fields,
       evidence_hash,checked_at,expires_at,recorded_by)
      VALUES (?,?,'SYNTHETIC_AUTHORITY','SYNTHETIC_TEST',?,?,?,?,?,?,?,?,?,?,?,?,?)`).bind(
      crypto.randomUUID(), party.trust_profile_id, sourceReference, submission.authority_record.vat_number ?? null,
      submission.authority_record.tin ?? null, submission.authority_record.company_registration_number ?? null,
      evaluation.taxRegistrationStatus, evaluation.trustStatus, evaluation.confidenceBps, JSON.stringify(evaluation.matchedFields),
      JSON.stringify(evaluation.conflictingFields), evidenceHash, checkedAt, expiresAt, actor.userId,
    ),
    db.prepare(`INSERT INTO counterparty_trust_events
      (id,trust_profile_id,event_type,from_status,to_status,reason_code,evidence_hash,actor_id,occurred_at)
      VALUES (?,?,'CounterpartyTrustEvaluated',?,?,?,?,?,?)`).bind(
      crypto.randomUUID(), party.trust_profile_id, party.trust_status, evaluation.trustStatus, evaluation.reasonCode, evidenceHash, actor.userId, checkedAt,
    ),
    commandRecord(db, actor.userId, "SYNTHETIC_VERIFY_BUSINESS_PARTY", idempotencyKey, requestHash, "BUSINESS_PARTY", id, checkedAt),
    outboxRecord(db, "COUNTERPARTY_TRUST", party.trust_profile_id, "CounterpartyTrustEvaluated", organisation.id, {
      business_party_id: id, trust_profile_id: party.trust_profile_id, trust_status: evaluation.trustStatus,
      provider_environment: "SYNTHETIC_TEST", correlation_id: correlationId,
    }, checkedAt),
    auditRecord(db, actor, audit, checkedAt),
  ]);
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
  await requirePartyRelationship(db, expense.supplier_party_id, organisation.id, "SUPPLIER", "Supplier party", expense.tax_cents > 0);
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

export async function decideExpense(
  id: string,
  payload: ExpenseDecisionSubmission,
  actor: UserContext,
  idempotencyKey: string,
  correlationId: string,
  requestedOrganisationId?: string | null,
) {
  validateIdempotencyKey(idempotencyKey);
  const decision = normalizeAndValidateExpenseDecision(payload);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const requestHash = await sha256Hex(stableStringify({ organisation_id: organisation.id, expense_id: id, decision }));
  const prior = await priorCommand(db, actor.userId, "DECIDE_EXPENSE", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM expenses WHERE id=? AND organisation_id=?").bind(prior, organisation.id).first<Record<string, unknown>>();
  const expense = await db.prepare(`SELECT e.id,e.organisation_id,e.expense_number,e.status,e.total_cents,e.created_by,
      c.requires_receipt,e.receipt_document_id,d.scan_status AS receipt_scan_status,d.status AS receipt_status
      FROM expenses e JOIN expense_categories c ON c.id=e.category_id
      LEFT JOIN document_metadata d ON d.id=e.receipt_document_id AND d.organisation_id=e.organisation_id
      WHERE e.id=? AND e.organisation_id=?`)
    .bind(id, organisation.id).first<ExpenseRecord>();
  if (!expense) throw new BusinessResourceError("Expense was not found in the authorised organisation.", 404);
  const policy = evaluateExpenseDecision({
    status: expense.status,
    createdBy: expense.created_by,
    actorId: actor.userId,
    decision: decision.decision,
    receiptRequired: expense.requires_receipt === 1,
    receiptDocumentId: expense.receipt_document_id,
    receiptScanStatus: expense.receipt_scan_status,
    receiptStatus: expense.receipt_status,
  });
  if (!policy.allowed) throw new RepositoryConflictError(policy.reason);
  const now = new Date().toISOString();
  const eventType = decision.decision === "APPROVE" ? "ExpenseApproved" : "ExpenseRejected";
  const audit = await auditEnvelope(db, actor, `EXPENSE_${decision.decision}D`, "EXPENSE", id, {
    organisationId: organisation.id,
    expenseNumber: expense.expense_number,
    totalCents: expense.total_cents,
    reason: decision.reason,
    correlationId,
  }, now);
  try {
    await db.batch([
      db.prepare(`INSERT INTO expense_decisions
        (id,expense_id,organisation_id,decision,reason,decided_by,decided_at) VALUES (?,?,?,?,?,?,?)`)
        .bind(crypto.randomUUID(), id, organisation.id, decision.decision, decision.reason, actor.userId, now),
      commandRecord(db, actor.userId, "DECIDE_EXPENSE", idempotencyKey, requestHash, "EXPENSE", id, now),
      outboxRecord(db, "EXPENSE", id, eventType, organisation.id, {
        expense_id: id,
        organisation_id: organisation.id,
        decision: decision.decision,
        total_cents: expense.total_cents,
        correlation_id: correlationId,
      }, now),
      auditRecord(db, actor, audit, now),
    ]);
  } catch (error) {
    if (error instanceof Error && error.message.includes("EXPENSE_")) {
      throw new RepositoryConflictError("The expense is no longer eligible for this independent decision.");
    }
    throw error;
  }
  return db.prepare("SELECT * FROM expenses WHERE id=? AND organisation_id=?").bind(id, organisation.id).first<Record<string, unknown>>();
}

export async function linkExpenseReceipt(
  id: string,
  payload: ExpenseReceiptLinkSubmission,
  actor: UserContext,
  idempotencyKey: string,
  correlationId: string,
  requestedOrganisationId?: string | null,
) {
  validateIdempotencyKey(idempotencyKey);
  const link = normalizeAndValidateExpenseReceiptLink(payload);
  const db = await ensureDatabase();
  const organisation = await resolveOrganisation(actor, requestedOrganisationId);
  const requestHash = await sha256Hex(stableStringify({ organisation_id: organisation.id, expense_id: id, link }));
  const prior = await priorCommand(db, actor.userId, "LINK_EXPENSE_RECEIPT", idempotencyKey, requestHash);
  if (prior) return db.prepare("SELECT * FROM expenses WHERE id=? AND organisation_id=?").bind(prior, organisation.id).first<Record<string, unknown>>();

  const expense = await db.prepare("SELECT id,status,receipt_document_id FROM expenses WHERE id=? AND organisation_id=?")
    .bind(id, organisation.id).first<{ id: string; status: string; receipt_document_id: string | null }>();
  if (!expense) throw new BusinessResourceError("Expense was not found in the authorised organisation.", 404);
  if (expense.status !== "DRAFT") throw new RepositoryConflictError("Receipt evidence can only be linked while an expense is in DRAFT status.");
  if (expense.receipt_document_id) throw new RepositoryConflictError("The expense already has immutable linked receipt evidence.");

  const document = await db.prepare(`SELECT id,file_name,checksum_sha256,scan_status,status FROM document_metadata
      WHERE id=? AND organisation_id=? AND owner_domain='EXPENSE' AND owner_resource_id=?`)
    .bind(link.receipt_document_id, organisation.id, id)
    .first<{ id: string; file_name: string; checksum_sha256: string; scan_status: string; status: string }>();
  if (!document) throw new BusinessResourceError("Receipt document was not found in the authorised expense scope.", 404);
  if (document.scan_status !== "CLEAN" || document.status !== "AVAILABLE") {
    throw new RepositoryConflictError("Quarantined, pending, rejected or otherwise unavailable receipt evidence cannot be linked to an expense.");
  }

  const now = new Date().toISOString();
  const audit = await auditEnvelope(db, actor, "EXPENSE_RECEIPT_LINKED", "EXPENSE", id, {
    organisationId: organisation.id,
    receiptDocumentId: document.id,
    checksumSha256: document.checksum_sha256,
    correlationId,
  }, now);
  try {
    await db.batch([
      db.prepare(`INSERT INTO expense_receipt_links
        (id,expense_id,organisation_id,document_id,linked_by,linked_at) VALUES (?,?,?,?,?,?)`)
        .bind(crypto.randomUUID(), id, organisation.id, document.id, actor.userId, now),
      commandRecord(db, actor.userId, "LINK_EXPENSE_RECEIPT", idempotencyKey, requestHash, "EXPENSE", id, now),
      outboxRecord(db, "EXPENSE", id, "ExpenseReceiptLinked", organisation.id, {
        expense_id: id,
        organisation_id: organisation.id,
        receipt_document_id: document.id,
        checksum_sha256: document.checksum_sha256,
        correlation_id: correlationId,
      }, now),
      auditRecord(db, actor, audit, now),
    ]);
  } catch (error) {
    if (error instanceof Error && (error.message.includes("EXPENSE_RECEIPT") || error.message.includes("expense_receipt_links"))) {
      throw new RepositoryConflictError("The receipt is no longer eligible to be linked to this draft expense.");
    }
    throw error;
  }
  return db.prepare("SELECT * FROM expenses WHERE id=? AND organisation_id=?").bind(id, organisation.id).first<Record<string, unknown>>();
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
