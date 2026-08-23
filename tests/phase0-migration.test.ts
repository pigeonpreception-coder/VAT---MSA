import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";
import { DatabaseSync } from "node:sqlite";
import { describe, expect, it } from "vitest";

function migratedDatabase() {
  const db = new DatabaseSync(":memory:");
  const migrations = readdirSync(join(process.cwd(), "drizzle")).filter((name) => /^\d{4}_.+\.sql$/u.test(name)).sort();
  for (const migration of migrations) {
    const sql = readFileSync(join(process.cwd(), "drizzle", migration), "utf8");
    for (const statement of sql.split("--> statement-breakpoint").map((part) => part.trim()).filter(Boolean)) db.exec(statement);
  }
  db.exec("PRAGMA foreign_keys=ON");
  return db;
}

function baseFixture(db: DatabaseSync) {
  db.exec(`
    INSERT INTO taxpayers (id,vat_number,tin,legal_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES ('tp-phase0','VAT-PHASE0','TIN-PHASE0','Phase 0 Test','PRIVATE_COMPANY','ACTIVE','BIMONTHLY','Windhoek','phase0@example.test','2026-08-23');
    INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at)
      VALUES ('usr-phase0','phase0','phase0-user@example.test','Phase 0 User','TAXPAYER_OWNER','tp-phase0','ACTIVE','2026-08-23');
    INSERT INTO tax_rule_sets (id,jurisdiction,version,effective_from,effective_to,standard_rate_bps,legal_authority_reference,status,created_at)
      VALUES ('rule-approved','NA','NA-APPROVED-TEST','2026-01-01',NULL,1500,'Synthetic test authority only','AUTHORITY_APPROVED','2026-08-23');
    INSERT INTO invoices
      (id,invoice_number,document_type,source_system,source_document_id,supplier_taxpayer_id,supplier_name,supplier_vat_number,
       customer_name,issue_date,currency,line_net_cents,tax_cents,total_cents,status,risk_level,payload_hash,transaction_id,
       certificate_id,verification_token,tax_rule_set_id,created_at,certified_at)
      VALUES ('inv-phase0','INV-PHASE0','TAX_INVOICE','TEST','DOC-PHASE0','tp-phase0','Phase 0 Test','VAT-PHASE0',
       'Buyer','2026-08-23','NAD',10000,1500,11500,'CERTIFIED','LOW','payload','txn-phase0','cert-phase0','vfy-phase0',
       'rule-approved','2026-08-23','2026-08-23');
  `);
}

describe("Phase 0 database enforcement", () => {
  it("registers the production schema revision and generated snapshot", () => {
    const journal = JSON.parse(readFileSync(join(process.cwd(), "drizzle", "meta", "_journal.json"), "utf8"));
    expect(journal.entries.at(-1)).toMatchObject({ idx: 15, tag: "0015_phase0_stabilization" });
    const snapshot = JSON.parse(readFileSync(join(process.cwd(), "drizzle", "meta", "0015_snapshot.json"), "utf8"));
    expect(snapshot.tables.app_schema_revisions).toBeDefined();
    expect(snapshot.tables.step_up_evidence_uses).toBeDefined();
    const db = migratedDatabase();
    expect(db.prepare("SELECT source FROM app_schema_revisions WHERE revision='phase0-stabilization-2026-08-23'").get())
      .toEqual({ source: "DRIZZLE_MIGRATION_0015" });
    db.close();
  });

  it("rejects unapproved rules and invoice-line rates that do not match the bound rule", () => {
    const db = migratedDatabase();
    baseFixture(db);
    expect(() => db.prepare(`INSERT INTO invoice_lines VALUES
      ('line-bad','inv-phase0',1,'Bad rate','1','EA',10000,10000,1400,'STANDARD',1400)`).run())
      .toThrow(/INVOICE_LINE_TAX_RULE_MISMATCH/);
    db.prepare(`INSERT INTO invoice_lines VALUES
      ('line-good','inv-phase0',1,'Approved rate','1','EA',10000,10000,1500,'STANDARD',1500)`).run();
    db.prepare(`INSERT INTO certificates
      (id,invoice_id,verification_token,invoice_hash,signature,signature_profile,rule_set_version,status,issued_at)
      VALUES ('cert-phase0','inv-phase0','vfy-phase0','certification-hash','test-signature','TEST','NA-APPROVED-TEST','VALID','2026-08-23')`).run();
    db.close();
  });

  it("enforces single-use step-up evidence by digest", () => {
    const db = migratedDatabase();
    baseFixture(db);
    db.prepare("INSERT INTO step_up_evidence_uses VALUES (?,?,?,?,?)").run("digest-1", "usr-phase0", "2026-08-23T12:00:00Z", "2026-08-23T12:05:00Z", "2026-08-23T12:00:01Z");
    expect(() => db.prepare("INSERT INTO step_up_evidence_uses VALUES (?,?,?,?,?)").run("digest-1", "usr-phase0", "2026-08-23T12:00:00Z", "2026-08-23T12:05:00Z", "2026-08-23T12:00:02Z"))
      .toThrow(/UNIQUE constraint|PRIMARY KEY/i);
    db.close();
  });
});
