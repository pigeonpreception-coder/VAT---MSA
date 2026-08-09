import { env } from "cloudflare:workers";

const SCHEMA_STATEMENTS = [
  `CREATE TABLE IF NOT EXISTS taxpayers (
    id TEXT PRIMARY KEY, vat_number TEXT NOT NULL UNIQUE, tin TEXT NOT NULL,
    legal_name TEXT NOT NULL, trading_name TEXT, taxpayer_type TEXT NOT NULL,
    vat_status TEXT NOT NULL, return_frequency TEXT NOT NULL, address TEXT NOT NULL,
    email TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
  )`,
  `CREATE TABLE IF NOT EXISTS invoices (
    id TEXT PRIMARY KEY, invoice_number TEXT NOT NULL, document_type TEXT NOT NULL,
    source_system TEXT NOT NULL, source_document_id TEXT NOT NULL,
    supplier_taxpayer_id TEXT NOT NULL REFERENCES taxpayers(id), supplier_name TEXT NOT NULL,
    supplier_vat_number TEXT NOT NULL, customer_taxpayer_id TEXT REFERENCES taxpayers(id),
    customer_name TEXT NOT NULL, customer_vat_number TEXT, issue_date TEXT NOT NULL,
    currency TEXT NOT NULL, line_net_cents INTEGER NOT NULL, tax_cents INTEGER NOT NULL,
    total_cents INTEGER NOT NULL, status TEXT NOT NULL, risk_level TEXT NOT NULL,
    payload_hash TEXT NOT NULL, transaction_id TEXT NOT NULL, certificate_id TEXT NOT NULL UNIQUE,
    verification_token TEXT NOT NULL UNIQUE, created_at TEXT NOT NULL, certified_at TEXT NOT NULL,
    UNIQUE (supplier_taxpayer_id, source_system, source_document_id)
  )`,
  `CREATE TABLE IF NOT EXISTS app_users (
    id TEXT PRIMARY KEY, external_user_id TEXT NOT NULL UNIQUE, email TEXT NOT NULL UNIQUE,
    display_name TEXT NOT NULL, role TEXT NOT NULL, taxpayer_id TEXT REFERENCES taxpayers(id),
    status TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
  )`,
  `CREATE TABLE IF NOT EXISTS invoice_lines (
    id TEXT PRIMARY KEY, invoice_id TEXT NOT NULL REFERENCES invoices(id), line_number INTEGER NOT NULL,
    description TEXT NOT NULL, quantity TEXT NOT NULL, unit_code TEXT NOT NULL,
    unit_price_cents INTEGER NOT NULL, net_amount_cents INTEGER NOT NULL,
    tax_rate_bps INTEGER NOT NULL, tax_category TEXT NOT NULL, tax_amount_cents INTEGER NOT NULL,
    UNIQUE (invoice_id, line_number)
  )`,
  `CREATE TABLE IF NOT EXISTS certificates (
    id TEXT PRIMARY KEY, invoice_id TEXT NOT NULL UNIQUE REFERENCES invoices(id),
    verification_token TEXT NOT NULL UNIQUE, invoice_hash TEXT NOT NULL,
    signature TEXT NOT NULL, signature_profile TEXT NOT NULL, status TEXT NOT NULL, issued_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS ledger_entries (
    id TEXT PRIMARY KEY, transaction_id TEXT NOT NULL, invoice_id TEXT NOT NULL REFERENCES invoices(id),
    taxpayer_id TEXT NOT NULL REFERENCES taxpayers(id), entry_type TEXT NOT NULL,
    direction TEXT NOT NULL, amount_cents INTEGER NOT NULL, period TEXT NOT NULL, created_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS reconciliation_exceptions (
    id TEXT PRIMARY KEY, invoice_id TEXT NOT NULL REFERENCES invoices(id),
    taxpayer_id TEXT REFERENCES taxpayers(id), exception_type TEXT NOT NULL,
    severity TEXT NOT NULL, status TEXT NOT NULL, summary TEXT NOT NULL,
    created_at TEXT NOT NULL, resolved_at TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS vat_returns (
    id TEXT PRIMARY KEY, taxpayer_id TEXT NOT NULL REFERENCES taxpayers(id), period TEXT NOT NULL,
    output_tax_cents INTEGER NOT NULL, input_tax_cents INTEGER NOT NULL,
    net_payable_cents INTEGER NOT NULL, status TEXT NOT NULL, last_calculated_at TEXT NOT NULL,
    UNIQUE (taxpayer_id, period)
  )`,
  `CREATE TABLE IF NOT EXISTS audit_events (
    id TEXT PRIMARY KEY, actor_id TEXT NOT NULL, actor_role TEXT NOT NULL, action TEXT NOT NULL,
    resource_type TEXT NOT NULL, resource_id TEXT NOT NULL, outcome TEXT NOT NULL,
    details TEXT NOT NULL, previous_hash TEXT, event_hash TEXT NOT NULL, occurred_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS idempotency_records (
    id TEXT PRIMARY KEY, actor_id TEXT NOT NULL, idempotency_key TEXT NOT NULL,
    request_hash TEXT NOT NULL, response_invoice_id TEXT NOT NULL REFERENCES invoices(id),
    created_at TEXT NOT NULL, UNIQUE (actor_id, idempotency_key)
  )`,
  `CREATE TABLE IF NOT EXISTS rate_limit_windows (
    bucket_key TEXT NOT NULL, window_start INTEGER NOT NULL, request_count INTEGER NOT NULL,
    expires_at INTEGER NOT NULL, UNIQUE (bucket_key, window_start)
  )`,
  `CREATE TABLE IF NOT EXISTS security_events (
    id TEXT PRIMARY KEY, event_type TEXT NOT NULL, severity TEXT NOT NULL, actor_id TEXT,
    source_token TEXT NOT NULL, correlation_id TEXT NOT NULL, action TEXT NOT NULL,
    outcome TEXT NOT NULL, details TEXT NOT NULL, occurred_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS security_incidents (
    id TEXT PRIMARY KEY, title TEXT NOT NULL, severity TEXT NOT NULL, status TEXT NOT NULL,
    source_event_id TEXT REFERENCES security_events(id), automated_action TEXT,
    owner TEXT, opened_at TEXT NOT NULL, updated_at TEXT NOT NULL
  )`,
  `CREATE TABLE IF NOT EXISTS outbox_events (
    id TEXT PRIMARY KEY, aggregate_type TEXT NOT NULL, aggregate_id TEXT NOT NULL,
    event_type TEXT NOT NULL, event_version INTEGER NOT NULL, partition_key TEXT NOT NULL,
    payload TEXT NOT NULL, status TEXT NOT NULL, publish_attempts INTEGER NOT NULL DEFAULT 0,
    occurred_at TEXT NOT NULL, available_at TEXT NOT NULL, published_at TEXT, last_error TEXT
  )`,
  `CREATE TABLE IF NOT EXISTS seed_state (key TEXT PRIMARY KEY, applied_at TEXT NOT NULL)`,
  `CREATE INDEX IF NOT EXISTS idx_invoices_status_issue_date ON invoices(status, issue_date)`,
  `CREATE INDEX IF NOT EXISTS idx_invoices_supplier_issue_date ON invoices(supplier_taxpayer_id, issue_date)`,
  `CREATE INDEX IF NOT EXISTS idx_invoices_customer_issue_date ON invoices(customer_taxpayer_id, issue_date)`,
  `CREATE INDEX IF NOT EXISTS idx_ledger_taxpayer_period ON ledger_entries(taxpayer_id, period)`,
  `CREATE INDEX IF NOT EXISTS idx_ledger_transaction ON ledger_entries(transaction_id)`,
  `CREATE INDEX IF NOT EXISTS idx_exceptions_status_created ON reconciliation_exceptions(status, created_at)`,
  `CREATE INDEX IF NOT EXISTS idx_audit_resource ON audit_events(resource_type, resource_id, occurred_at)`,
  `CREATE INDEX IF NOT EXISTS idx_audit_occurred ON audit_events(occurred_at)`,
  `CREATE INDEX IF NOT EXISTS idx_rate_limit_expiry ON rate_limit_windows(expires_at)`,
  `CREATE INDEX IF NOT EXISTS idx_security_events_severity_time ON security_events(severity, occurred_at)`,
  `CREATE INDEX IF NOT EXISTS idx_security_events_actor_time ON security_events(actor_id, occurred_at)`,
  `CREATE INDEX IF NOT EXISTS idx_security_incidents_status_severity ON security_incidents(status, severity)`,
  `CREATE INDEX IF NOT EXISTS idx_outbox_status_available ON outbox_events(status, available_at)`,
  `CREATE INDEX IF NOT EXISTS idx_outbox_aggregate ON outbox_events(aggregate_type, aggregate_id)`,
];

const SEED_STATEMENTS = [
  `INSERT OR IGNORE INTO taxpayers VALUES ('tp-0001','VAT1000123','TIN-1000123','Namib Office Supplies (Pty) Ltd','Namib Office','PRIVATE_COMPANY','ACTIVE','BIMONTHLY','12 Independence Avenue, Windhoek','finance@namiboffice.example','2026-01-12T08:00:00Z')`,
  `INSERT OR IGNORE INTO taxpayers VALUES ('tp-0002','VAT1000456','TIN-1000456','Desert Logistics CC','Desert Logistics','CLOSE_CORPORATION','ACTIVE','MONTHLY','44 Sam Nujoma Drive, Walvis Bay','accounts@desertlogistics.example','2026-02-04T09:30:00Z')`,
  `INSERT OR IGNORE INTO taxpayers VALUES ('tp-0003','VAT1000789','TIN-1000789','Atlantic Retail Group (Pty) Ltd','Atlantic Retail','PRIVATE_COMPANY','ACTIVE','MONTHLY','8 Theo-Ben Gurirab Street, Swakopmund','tax@atlanticretail.example','2026-02-18T11:15:00Z')`,
  `INSERT OR IGNORE INTO taxpayers VALUES ('tp-0004','VAT1000987','TIN-1000987','Kalahari Consulting (Pty) Ltd','Kalahari Consulting','PRIVATE_COMPANY','ACTIVE','BIMONTHLY','19 Robert Mugabe Avenue, Windhoek','admin@kalahariconsulting.example','2026-03-01T07:45:00Z')`,
  `INSERT OR IGNORE INTO app_users VALUES ('usr-local-admin','local-demo-user','admin@vat-msa.local','Pilot Administrator','PILOT_ADMIN',NULL,'ACTIVE','2026-08-01T08:00:00Z')`,

  `INSERT OR IGNORE INTO invoices VALUES ('inv-0001','INV-2026-0182','TAX_INVOICE','ERP-NAMIB-01','ERP-182','tp-0001','Namib Office Supplies (Pty) Ltd','VAT1000123','tp-0003','Atlantic Retail Group (Pty) Ltd','VAT1000789','2026-08-08','NAD',11450000,1717500,13167500,'MATCHED','LOW','13a5e7b5d4c8f1a0123456789012345678901234567890123456789012345678','txn-0001','cert-0001','vfy_1a92c57e41f84b89a601d982be634a81','2026-08-08T08:12:44Z','2026-08-08T08:12:45Z')`,
  `INSERT OR IGNORE INTO invoices VALUES ('inv-0002','DL-8842','TAX_INVOICE','API-DL-01','DL-8842','tp-0002','Desert Logistics CC','VAT1000456','tp-0001','Namib Office Supplies (Pty) Ltd','VAT1000123','2026-08-07','NAD',5200000,780000,5980000,'MATCHED','LOW','23a5e7b5d4c8f1a0123456789012345678901234567890123456789012345678','txn-0002','cert-0002','vfy_2b13d68f52a94c90b712e093cf745b92','2026-08-07T14:21:19Z','2026-08-07T14:21:20Z')`,
  `INSERT OR IGNORE INTO invoices VALUES ('inv-0003','AR-7719','SIMPLIFIED_TAX_INVOICE','POS-ATL-22','POS-7719','tp-0003','Atlantic Retail Group (Pty) Ltd','VAT1000789',NULL,'Walk-in customer',NULL,'2026-08-07','NAD',850000,127500,977500,'CERTIFIED','LOW','33a5e7b5d4c8f1a0123456789012345678901234567890123456789012345678','txn-0003','cert-0003','vfy_3c24e79a63ba4da1c823f1a4d0856ca3','2026-08-07T12:04:03Z','2026-08-07T12:04:04Z')`,
  `INSERT OR IGNORE INTO invoices VALUES ('inv-0004','KC-1041','TAX_INVOICE','PORTAL','PORTAL-KC-1041','tp-0004','Kalahari Consulting (Pty) Ltd','VAT1000987','tp-0001','Namib Office Supplies (Pty) Ltd','VAT1000123','2026-08-06','NAD',120000000,18000000,138000000,'EXCEPTION','CRITICAL','43a5e7b5d4c8f1a0123456789012345678901234567890123456789012345678','txn-0004','cert-0004','vfy_4d35f80b74cb4eb2d93402b5e1967db4','2026-08-06T09:32:10Z','2026-08-06T09:32:11Z')`,

  `INSERT OR IGNORE INTO invoice_lines VALUES ('line-0001','inv-0001',1,'Office equipment and consumables','1','EA',11450000,11450000,1500,'STANDARD',1717500)`,
  `INSERT OR IGNORE INTO invoice_lines VALUES ('line-0002','inv-0002',1,'Regional freight services','1','EA',5200000,5200000,1500,'STANDARD',780000)`,
  `INSERT OR IGNORE INTO invoice_lines VALUES ('line-0003','inv-0003',1,'Retail merchandise','1','EA',850000,850000,1500,'STANDARD',127500)`,
  `INSERT OR IGNORE INTO invoice_lines VALUES ('line-0004','inv-0004',1,'Enterprise transformation advisory','1','EA',120000000,120000000,1500,'STANDARD',18000000)`,

  `INSERT OR IGNORE INTO certificates VALUES ('cert-0001','inv-0001','vfy_1a92c57e41f84b89a601d982be634a81','13a5e7b5d4c8f1a0123456789012345678901234567890123456789012345678','DEV.13a5e7b5d4c8f1a0','DEV-SHA256','VALID','2026-08-08T08:12:45Z')`,
  `INSERT OR IGNORE INTO certificates VALUES ('cert-0002','inv-0002','vfy_2b13d68f52a94c90b712e093cf745b92','23a5e7b5d4c8f1a0123456789012345678901234567890123456789012345678','DEV.23a5e7b5d4c8f1a0','DEV-SHA256','VALID','2026-08-07T14:21:20Z')`,
  `INSERT OR IGNORE INTO certificates VALUES ('cert-0003','inv-0003','vfy_3c24e79a63ba4da1c823f1a4d0856ca3','33a5e7b5d4c8f1a0123456789012345678901234567890123456789012345678','DEV.33a5e7b5d4c8f1a0','DEV-SHA256','VALID','2026-08-07T12:04:04Z')`,
  `INSERT OR IGNORE INTO certificates VALUES ('cert-0004','inv-0004','vfy_4d35f80b74cb4eb2d93402b5e1967db4','43a5e7b5d4c8f1a0123456789012345678901234567890123456789012345678','DEV.43a5e7b5d4c8f1a0','DEV-SHA256','VALID','2026-08-06T09:32:11Z')`,

  `INSERT OR IGNORE INTO ledger_entries VALUES ('led-0001a','txn-0001','inv-0001','tp-0001','OUTPUT_VAT','CREDIT',1717500,'2026-08','2026-08-08T08:12:45Z')`,
  `INSERT OR IGNORE INTO ledger_entries VALUES ('led-0001b','txn-0001','inv-0001','tp-0003','INPUT_VAT','DEBIT',1717500,'2026-08','2026-08-08T08:12:45Z')`,
  `INSERT OR IGNORE INTO ledger_entries VALUES ('led-0002a','txn-0002','inv-0002','tp-0002','OUTPUT_VAT','CREDIT',780000,'2026-08','2026-08-07T14:21:20Z')`,
  `INSERT OR IGNORE INTO ledger_entries VALUES ('led-0002b','txn-0002','inv-0002','tp-0001','INPUT_VAT','DEBIT',780000,'2026-08','2026-08-07T14:21:20Z')`,
  `INSERT OR IGNORE INTO ledger_entries VALUES ('led-0003a','txn-0003','inv-0003','tp-0003','OUTPUT_VAT','CREDIT',127500,'2026-08','2026-08-07T12:04:04Z')`,
  `INSERT OR IGNORE INTO ledger_entries VALUES ('led-0004a','txn-0004','inv-0004','tp-0004','OUTPUT_VAT','CREDIT',18000000,'2026-08','2026-08-06T09:32:11Z')`,
  `INSERT OR IGNORE INTO ledger_entries VALUES ('led-0004b','txn-0004','inv-0004','tp-0001','INPUT_VAT','DEBIT',18000000,'2026-08','2026-08-06T09:32:11Z')`,

  `INSERT OR IGNORE INTO reconciliation_exceptions VALUES ('exc-0001','inv-0004','tp-0004','HIGH_VALUE_TRANSACTION','CRITICAL','OPEN','Transaction value exceeds the pilot high-value threshold and requires officer review.','2026-08-06T09:32:11Z',NULL)`,
  `INSERT OR IGNORE INTO reconciliation_exceptions VALUES ('exc-0002','inv-0003','tp-0003','UNREGISTERED_BUYER','MEDIUM','OPEN','Buyer does not have a VAT registration in the pilot registry; input VAT was not posted.','2026-08-07T12:04:04Z',NULL)`,

  `INSERT OR IGNORE INTO vat_returns VALUES ('ret-0001','tp-0001','2026-08',1717500,18780000,-17062500,'DRAFT','2026-08-08T18:00:00Z')`,
  `INSERT OR IGNORE INTO vat_returns VALUES ('ret-0002','tp-0002','2026-08',780000,0,780000,'DRAFT','2026-08-08T18:00:00Z')`,
  `INSERT OR IGNORE INTO vat_returns VALUES ('ret-0003','tp-0003','2026-08',127500,1717500,-1590000,'DRAFT','2026-08-08T18:00:00Z')`,
  `INSERT OR IGNORE INTO vat_returns VALUES ('ret-0004','tp-0004','2026-08',18000000,0,18000000,'UNDER_REVIEW','2026-08-08T18:00:00Z')`,

  `INSERT OR IGNORE INTO audit_events VALUES ('aud-0001','system','SYSTEM','INVOICE_CERTIFIED','INVOICE','inv-0001','SUCCESS','{"invoice_number":"INV-2026-0182","transaction_id":"txn-0001"}',NULL,'a000000000000000000000000000000000000000000000000000000000000001','2026-08-08T08:12:45Z')`,
  `INSERT OR IGNORE INTO audit_events VALUES ('aud-0002','system','SYSTEM','RECONCILIATION_EXCEPTION_OPENED','EXCEPTION','exc-0001','SUCCESS','{"severity":"CRITICAL","invoice_id":"inv-0004"}','a000000000000000000000000000000000000000000000000000000000000001','a000000000000000000000000000000000000000000000000000000000000002','2026-08-06T09:32:11Z')`,
  `INSERT OR IGNORE INTO seed_state VALUES ('pilot-v1','2026-08-09T00:00:00Z')`,
];

const SECURITY_SEED_STATEMENTS = [
  `INSERT OR IGNORE INTO security_events VALUES ('sec-0001','API_RATE_ANOMALY','MEDIUM','usr-local-admin','src:pilot','a1000000-0000-4000-8000-000000000001','INVOICE_SUBMISSION','THROTTLED','{"bucket":"actor","threshold":120}','2026-08-09T06:45:00Z')`,
  `INSERT OR IGNORE INTO security_events VALUES ('sec-0002','AUTHORISATION_DENIED','HIGH','unknown','src:external','a1000000-0000-4000-8000-000000000002','INVOICE_READ','DENIED','{"reason":"taxpayer_scope_mismatch"}','2026-08-09T07:10:00Z')`,
  `INSERT OR IGNORE INTO security_events VALUES ('sec-0003','PAYLOAD_REJECTED','LOW','usr-local-admin','src:pilot','a1000000-0000-4000-8000-000000000003','INVOICE_SUBMISSION','REJECTED','{"reason":"payload_limit"}','2026-08-09T07:18:00Z')`,
  `INSERT OR IGNORE INTO security_incidents VALUES ('inc-0001','Repeated cross-taxpayer access attempts','HIGH','INVESTIGATING','sec-0002','SESSION_CHALLENGE','SOC Tier 2','2026-08-09T07:11:00Z','2026-08-09T07:20:00Z')`,
  `INSERT OR IGNORE INTO outbox_events VALUES ('out-0001','INVOICE','inv-0001','InvoiceCertified',1,'tp-0001','{"invoice_id":"inv-0001","transaction_id":"txn-0001"}','PUBLISHED',1,'2026-08-08T08:12:45Z','2026-08-08T08:12:45Z','2026-08-08T08:12:46Z',NULL)`,
  `INSERT OR IGNORE INTO seed_state VALUES ('security-v1','2026-08-09T08:00:00Z')`,
];

let initialization: Promise<void> | null = null;

export function getD1(): D1Database {
  if (!env.DB) throw new Error("VAT-MSA database binding DB is unavailable.");
  return env.DB;
}

export async function ensureDatabase(): Promise<D1Database> {
  const db = getD1();
  initialization ??= initialize(db).catch((error) => {
    initialization = null;
    throw error;
  });
  await initialization;
  return db;
}

async function initialize(db: D1Database): Promise<void> {
  await db.batch(SCHEMA_STATEMENTS.map((statement) => db.prepare(statement)));
  const existing = await db.prepare("SELECT key FROM seed_state WHERE key = ?").bind("pilot-v1").first();
  if (!existing) await db.batch(SEED_STATEMENTS.map((statement) => db.prepare(statement)));
  const securitySeed = await db.prepare("SELECT key FROM seed_state WHERE key = ?").bind("security-v1").first();
  if (!securitySeed) await db.batch(SECURITY_SEED_STATEMENTS.map((statement) => db.prepare(statement)));
  await db.prepare("PRAGMA optimize").run();
}
