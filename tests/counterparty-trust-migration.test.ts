import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";
import { DatabaseSync } from "node:sqlite";
import { describe, expect, it } from "vitest";

function migratedDatabase() {
  const db = new DatabaseSync(":memory:");
  const migrations = readdirSync(join(process.cwd(), "drizzle")).filter((name) => /^\d{4}_.+\.sql$/u.test(name)).sort();
  for (const migration of migrations) {
    if (migration === "0018_counterparty_trust.sql") {
      db.exec(`
        INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
          VALUES ('tp-trust-org','VAT-TRUST-ORG','TIN-TRUST-ORG','Trust Test Organisation',NULL,'PRIVATE_COMPANY','ACTIVE','BIMONTHLY','Windhoek','trust-org@example.test','2026-08-23');
        INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at)
          VALUES ('org-0001','tp-trust-org','Trust Test Organisation',NULL,'ACTIVE','2026-08-23','2026-08-23');
        INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at)
          VALUES ('usr-local-admin','trust-local-admin','trust-admin@example.test','Trust Admin','PILOT_ADMIN','tp-trust-org','ACTIVE','2026-08-23');
        INSERT INTO business_parties
          (id,organisation_id,display_name,legal_name,vat_number,tin,email,phone,address,source_system,source_party_id,status,created_at,updated_at)
          VALUES ('party-0001-customer','org-0001','Trust Customer','Trust Customer (Pty) Ltd','VAT1000789','TIN-1000789',NULL,NULL,NULL,'LOCAL','TRUST-CUSTOMER','ACTIVE','2026-08-23','2026-08-23');
        INSERT INTO party_relationships (id,organisation_id,party_id,relationship,status,effective_from,effective_to,created_at)
          VALUES ('rel-trust-customer','org-0001','party-0001-customer','CUSTOMER','ACTIVE','2026-08-23',NULL,'2026-08-23');
      `);
    }
    const sql = readFileSync(join(process.cwd(), "drizzle", migration), "utf8");
    for (const statement of sql.split("--> statement-breakpoint").map((part) => part.trim()).filter(Boolean)) db.exec(statement);
  }
  db.exec("PRAGMA foreign_keys=ON");
  return db;
}

function trustCustomer(db: DatabaseSync, taxStatus = "ACTIVE") {
  db.prepare(`UPDATE counterparty_trust_profiles SET provider='SYNTHETIC_AUTHORITY',provider_environment='SYNTHETIC_TEST',
    trust_status='SYNTHETIC_VALID',tax_registration_status=?,vat_verification_status='MATCHED',tin_verification_status='MATCHED',
    confidence_bps=8500,evidence_hash=?,source_reference='synthetic-migration-customer',checked_at='2026-08-23T08:00:00Z',
    expires_at='2099-08-23T08:00:00Z',updated_at='2026-08-23T08:00:00Z' WHERE business_party_id='party-0001-customer'`)
    .run(taxStatus, "a".repeat(64));
}

describe("Issue 3 counterparty-trust database enforcement", () => {
  it("registers the generated schema, pending backfill and required revision", () => {
    const journal = JSON.parse(readFileSync(join(process.cwd(), "drizzle", "meta", "_journal.json"), "utf8"));
    expect(journal.entries.find((entry: { idx: number }) => entry.idx === 18))
      .toMatchObject({ idx: 18, tag: "0018_counterparty_trust" });
    const snapshot = JSON.parse(readFileSync(join(process.cwd(), "drizzle", "meta", "0018_snapshot.json"), "utf8"));
    expect(snapshot.tables.counterparty_trust_profiles).toBeDefined();
    expect(snapshot.tables.counterparty_verification_snapshots).toBeDefined();
    const db = migratedDatabase();
    expect(db.prepare("SELECT source FROM app_schema_revisions WHERE revision='issue3-counterparty-trust-2026-08-23'").get())
      .toEqual({ source: "DRIZZLE_MIGRATION_0018" });
    expect(db.prepare("SELECT trust_status,provider_environment FROM counterparty_trust_profiles WHERE business_party_id='party-0001-customer'").get())
      .toEqual({ trust_status: "PENDING_PROVIDER", provider_environment: "CONTRACT_PENDING" });
    db.close();
  });

  it("rejects untrusted transactional use and accepts only current labelled evidence", () => {
    const db = migratedDatabase();
    expect(() => db.prepare(`INSERT INTO quotations
      (id,organisation_id,branch_id,customer_party_id,quotation_number,currency,issue_date,valid_until,status,subtotal_cents,tax_cents,total_cents,notes,created_by,approved_by,accepted_at,converted_invoice_id,created_at,updated_at)
      VALUES ('quote-trust-block','org-0001',NULL,'party-0001-customer','TRUST-BLOCK','NAD','2026-08-23','2026-09-23','ISSUED',10000,1500,11500,NULL,'usr-local-admin',NULL,NULL,NULL,'2026-08-23','2026-08-23')`).run())
      .toThrow(/COUNTERPARTY_TRUST_REQUIRED/);
    trustCustomer(db);
    db.prepare(`INSERT INTO quotations
      (id,organisation_id,branch_id,customer_party_id,quotation_number,currency,issue_date,valid_until,status,subtotal_cents,tax_cents,total_cents,notes,created_by,approved_by,accepted_at,converted_invoice_id,created_at,updated_at)
      VALUES ('quote-trust-pass','org-0001',NULL,'party-0001-customer','TRUST-PASS','NAD','2026-08-23','2026-09-23','ISSUED',10000,1500,11500,NULL,'usr-local-admin',NULL,NULL,NULL,'2026-08-23','2026-08-23')`).run();
    expect(db.prepare("SELECT id FROM quotations WHERE id='quote-trust-pass'").get()).toEqual({ id: "quote-trust-pass" });
    db.close();
  });

  it("enforces authority/synthetic separation, identifier uniqueness and reverification", () => {
    const db = migratedDatabase();
    expect(() => db.prepare(`UPDATE counterparty_trust_profiles SET trust_status='AUTHORITY_VERIFIED',provider_environment='SYNTHETIC_TEST',
      evidence_hash=?,source_reference='bad-authority',checked_at='2026-08-23',expires_at='2099-08-23',reviewed_by='usr-local-admin'
      WHERE business_party_id='party-0001-customer'`).run("b".repeat(64))).toThrow(/COUNTERPARTY_AUTHORITY_EVIDENCE_REQUIRED|CHECK constraint/);
    db.prepare("UPDATE business_parties SET company_registration_number='CC-TRUST-001' WHERE id='party-0001-customer'").run();
    expect(() => db.prepare(`INSERT INTO business_parties
      (id,organisation_id,display_name,legal_name,vat_number,tin,company_registration_number,email,phone,address,source_system,source_party_id,status,created_at,updated_at)
      VALUES ('party-company-duplicate','org-0001','Duplicate','Duplicate',NULL,NULL,'CC-TRUST-001',NULL,NULL,NULL,'LOCAL','DUP-COMPANY','ACTIVE','2026-08-23','2026-08-23')`).run())
      .toThrow(/UNIQUE constraint/i);
    trustCustomer(db);
    expect(() => db.prepare("UPDATE business_parties SET vat_number='VAT-CHANGED' WHERE id='party-0001-customer'").run())
      .toThrow(/COUNTERPARTY_REVERIFICATION_REQUIRED/);
    db.prepare(`UPDATE counterparty_trust_profiles SET provider='ITAS_BIPA',provider_environment='CONTRACT_PENDING',trust_status='PENDING_PROVIDER',
      tax_registration_status='UNKNOWN',vat_verification_status='PENDING',tin_verification_status='PENDING',confidence_bps=0,
      evidence_hash=NULL,source_reference=NULL,checked_at=NULL,expires_at=NULL,updated_at='2026-08-23' WHERE business_party_id='party-0001-customer'`).run();
    db.prepare("UPDATE business_parties SET vat_number='VAT-CHANGED' WHERE id='party-0001-customer'").run();
    db.close();
  });

  it("keeps verification snapshots and trust events append-only", () => {
    const db = migratedDatabase();
    trustCustomer(db);
    const profile = db.prepare("SELECT id FROM counterparty_trust_profiles WHERE business_party_id='party-0001-customer'").get() as { id: string };
    db.prepare(`INSERT INTO counterparty_verification_snapshots
      (id,trust_profile_id,provider,provider_environment,source_reference,observed_vat_number,observed_tin,observed_company_registration_number,
       tax_registration_status,trust_status,confidence_bps,matched_fields,conflicting_fields,evidence_hash,checked_at,expires_at,recorded_by)
      VALUES ('snapshot-trust',?,'SYNTHETIC_AUTHORITY','SYNTHETIC_TEST','snapshot-ref','VAT1000789','TIN-1000789',NULL,
       'ACTIVE','SYNTHETIC_VALID',8500,'["vat_number","tin"]','[]',?,'2026-08-23','2099-08-23','usr-local-admin')`).run(profile.id, "c".repeat(64));
    expect(() => db.prepare("UPDATE counterparty_verification_snapshots SET confidence_bps=0 WHERE id='snapshot-trust'").run())
      .toThrow(/COUNTERPARTY_SNAPSHOT_IMMUTABLE/);
    expect(() => db.prepare("DELETE FROM counterparty_trust_events WHERE trust_profile_id=?").run(profile.id))
      .toThrow(/COUNTERPARTY_TRUST_EVENT_IMMUTABLE/);
    db.close();
  });
});
