import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";
import { DatabaseSync } from "node:sqlite";
import { describe, expect, it } from "vitest";

function applyMigrations() {
  const db = new DatabaseSync(":memory:");
  const migrations = readdirSync(join(process.cwd(), "drizzle")).filter((name) => /^\d{4}_.+\.sql$/.test(name)).sort();
  for (const migration of migrations) {
    const sql = readFileSync(join(process.cwd(), "drizzle", migration), "utf8");
    for (const statement of sql.split("--> statement-breakpoint").map((part) => part.trim()).filter(Boolean)) db.exec(statement);
  }
  db.exec("PRAGMA foreign_keys=ON");
  return db;
}

function baseFixture(db: DatabaseSync) {
  db.exec(`
    INSERT INTO taxpayers (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES ('tp-dual','VAT-DUAL','TIN-DUAL','Dual Authority Test Ltd',NULL,'PRIVATE_COMPANY','ACTIVE','MONTHLY','Windhoek','dual@example.test','2026-08-23T12:00:00Z');
    INSERT INTO organisations (id,taxpayer_id,legal_name,trading_name,status,created_at,updated_at)
      VALUES ('org-dual','tp-dual','Dual Authority Test Ltd',NULL,'ACTIVE','2026-08-23T12:00:00Z','2026-08-23T12:00:00Z');
    INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at)
      VALUES ('usr-requester','requester','requester@example.test','Requester','TAXPAYER_OWNER','tp-dual','ACTIVE','2026-08-23T12:00:00Z'),
             ('usr-decider','decider','decider@example.test','Decider','NAMRA_SYSTEM_ADMIN',NULL,'ACTIVE','2026-08-23T12:00:00Z');
    INSERT INTO license_plans (id,code,name,version,status,effective_from,effective_to,created_at,plan_domain)
      VALUES ('plan-commercial-test','COMMERCIAL_TEST','Commercial Test',1,'ACTIVE','2026-01-01',NULL,'2026-08-23','COMMERCIAL_SAAS'),
             ('plan-tax-test','TAX_TEST','Tax Test',1,'ACTIVE','2026-01-01',NULL,'2026-08-23','GOVERNMENT_TAX');
    INSERT INTO license_features (feature_key,name,description,metric_key,protected,created_at,authority_domain)
      VALUES ('TEST_TAX','Test tax','Government test feature',NULL,1,'2026-08-23','GOVERNMENT_TAX'),
             ('TEST_COMMERCIAL','Test commercial','Commercial test feature',NULL,1,'2026-08-23','COMMERCIAL_SAAS'),
             ('USER_SEATS','Test seats','Commercial seats','USER_SEATS',1,'2026-08-23','COMMERCIAL_SAAS');
  `);
}

describe("dual-subscription database boundary", () => {
  it("registers migration 0014 and its generated schema snapshot", () => {
    const journal = JSON.parse(readFileSync(join(process.cwd(), "drizzle", "meta", "_journal.json"), "utf8"));
    expect(journal.entries.at(-1)).toMatchObject({ idx: 14, tag: "0014_dual_subscription_authority" });
    const snapshot = JSON.parse(readFileSync(join(process.cwd(), "drizzle", "meta", "0014_snapshot.json"), "utf8"));
    expect(snapshot.tables["tax_subscriptions"]).toBeDefined();
    expect(snapshot.tables["license_capacity_exceptions"]).toBeDefined();
  });

  it("rejects every plan-feature authority mismatch and ambiguous capacity", () => {
    const db = applyMigrations();
    baseFixture(db);
    expect(() => db.prepare(`INSERT INTO license_plan_entitlements (id,license_plan_id,feature_key,enabled,limit_value,configuration,capacity_mode)
      VALUES ('bad-tax','plan-commercial-test','TEST_TAX',1,NULL,'{}','NOT_APPLICABLE')`).run()).toThrow(/PLAN_FEATURE_AUTHORITY_DOMAIN_MISMATCH/);
    expect(() => db.prepare(`INSERT INTO license_plan_entitlements (id,license_plan_id,feature_key,enabled,limit_value,configuration,capacity_mode)
      VALUES ('bad-commercial','plan-tax-test','TEST_COMMERCIAL',1,NULL,'{}','NOT_APPLICABLE')`).run()).toThrow(/PLAN_FEATURE_AUTHORITY_DOMAIN_MISMATCH/);
    expect(() => db.prepare(`INSERT INTO license_plan_entitlements (id,license_plan_id,feature_key,enabled,limit_value,configuration,capacity_mode)
      VALUES ('ambiguous-unlimited','plan-commercial-test','USER_SEATS',1,999999,'{}','UNLIMITED')`).run()).toThrow(/ENTITLEMENT_CAPACITY_CONFIGURATION_INVALID/);
    expect(() => db.prepare(`INSERT INTO license_plan_entitlements (id,license_plan_id,feature_key,enabled,limit_value,configuration,capacity_mode)
      VALUES ('seat-not-applicable','plan-commercial-test','USER_SEATS',1,NULL,'{}','NOT_APPLICABLE')`).run()).toThrow(/USER_SEATS_CAPACITY_MODE_INVALID/);
    db.close();
  });

  it("enforces finite seats atomically and supports explicit unlimited capacity", () => {
    const db = applyMigrations();
    baseFixture(db);
    db.exec(`
      INSERT INTO license_plan_entitlements (id,license_plan_id,feature_key,enabled,limit_value,configuration,capacity_mode)
        VALUES ('seat-entitlement','plan-commercial-test','USER_SEATS',1,1,'{}','FINITE');
      INSERT INTO subscriptions (id,organisation_id,provider,provider_reference,status,activated_at,current_period_start,current_period_end,created_at,updated_at,subscription_domain,payment_mode)
        VALUES ('sub-commercial','org-dual','LOCAL_SYNTHETIC','sub-commercial','ACTIVE','2026-08-23','2026-08-01','2026-10-31','2026-08-23','2026-08-23','COMMERCIAL_SAAS','DISABLED');
      INSERT INTO organisation_licenses (id,organisation_id,subscription_id,license_plan_id,state,state_version,effective_from,effective_to,grace_ends_at,retention_policy,updated_at)
        VALUES ('olic-commercial','org-dual','sub-commercial','plan-commercial-test','ACTIVE',1,'2026-08-23',NULL,NULL,'NON_DESTRUCTIVE','2026-08-23');
      INSERT INTO employees (id,organisation_id,employee_number,full_name,email,status,created_at,updated_at)
        VALUES ('emp-1','org-dual','EMP-1','Employee One','one@example.test','ACTIVE','2026-08-23','2026-08-23');
    `);
    expect(() => db.prepare(`INSERT INTO employees (id,organisation_id,employee_number,full_name,email,status,created_at,updated_at)
      VALUES ('emp-2','org-dual','EMP-2','Employee Two','two@example.test','INVITED','2026-08-23','2026-08-23')`).run()).toThrow(/USER_LICENSE_LIMIT_REACHED/);
    db.prepare("UPDATE license_plan_entitlements SET capacity_mode='UNLIMITED',limit_value=NULL WHERE id='seat-entitlement'").run();
    db.prepare(`INSERT INTO employees (id,organisation_id,employee_number,full_name,email,status,created_at,updated_at)
      VALUES ('emp-2','org-dual','EMP-2','Employee Two','two@example.test','INVITED','2026-08-23','2026-08-23')`).run();
    expect(db.prepare("SELECT COUNT(*) AS count FROM employees WHERE organisation_id='org-dual' AND status IN ('ACTIVE','INVITED')").get()).toEqual({ count: 2 });
    db.close();
  });

  it("opens a non-destructive exception when capacity is reduced below active use", () => {
    const db = applyMigrations();
    baseFixture(db);
    db.exec(`
      INSERT INTO license_plan_entitlements (id,license_plan_id,feature_key,enabled,limit_value,configuration,capacity_mode)
        VALUES ('seat-entitlement','plan-commercial-test','USER_SEATS',1,3,'{}','FINITE');
      INSERT INTO subscriptions (id,organisation_id,provider,provider_reference,status,activated_at,current_period_start,current_period_end,created_at,updated_at,subscription_domain,payment_mode)
        VALUES ('sub-commercial','org-dual','LOCAL_SYNTHETIC','sub-commercial','ACTIVE','2026-08-23','2026-08-01','2026-10-31','2026-08-23','2026-08-23','COMMERCIAL_SAAS','DISABLED');
      INSERT INTO organisation_licenses (id,organisation_id,subscription_id,license_plan_id,state,state_version,effective_from,effective_to,grace_ends_at,retention_policy,updated_at)
        VALUES ('olic-commercial','org-dual','sub-commercial','plan-commercial-test','ACTIVE',1,'2026-08-23',NULL,NULL,'NON_DESTRUCTIVE','2026-08-23');
      INSERT INTO employees (id,organisation_id,employee_number,full_name,email,status,created_at,updated_at) VALUES
        ('emp-1','org-dual','EMP-1','Employee One','one@example.test','ACTIVE','2026-08-23','2026-08-23'),
        ('emp-2','org-dual','EMP-2','Employee Two','two@example.test','ACTIVE','2026-08-23','2026-08-23');
      UPDATE license_plan_entitlements SET limit_value=1 WHERE id='seat-entitlement';
    `);
    expect(db.prepare("SELECT active_users,licensed_capacity,status FROM license_capacity_exceptions WHERE organisation_id='org-dual'").get())
      .toEqual({ active_users: 2, licensed_capacity: 1, status: "OPEN" });
    expect(db.prepare("SELECT COUNT(*) AS count FROM employees WHERE organisation_id='org-dual'").get()).toEqual({ count: 2 });
    db.close();
  });

  it("requires no-self-approval and immutable taxpayer authorization decisions", () => {
    const db = applyMigrations();
    baseFixture(db);
    db.exec(`
      INSERT INTO countries VALUES ('NA','NAM','Namibia','NAD','ACTIVE','2026-08-23');
      INSERT INTO tax_jurisdictions VALUES ('jur-na','NA','NA','Namibia','ACTIVE','2026-08-23');
      INSERT INTO tax_authorities VALUES ('authority-na','jur-na','NAMRA','NamRA','ACTIVE','2026-08-23');
      INSERT INTO tax_subscriptions VALUES ('tax-sub','authority-na','plan-tax-test','ACTIVE','LOCAL_STAGING','2026-08-23',NULL,'SYNTHETIC','2026-08-23');
      INSERT INTO tax_subscription_features VALUES ('tax-feature','tax-sub','TEST_TAX','ACTIVE','2026-08-23');
      INSERT INTO taxpayer_authorizations VALUES ('tax-authz','tax-sub','authority-na','jur-na','org-dual','tp-dual','ACTIVE','ACTIVE','2026-08-23',NULL,'AUTHZ-1','usr-decider','2026-08-23');
    `);
    expect(() => db.prepare(`INSERT INTO taxpayer_authorization_decisions VALUES
      ('decision-self','tax-authz','AUTHORIZE','Self decision is forbidden','usr-decider','usr-decider','step-up','2026-08-23')`).run()).toThrow(/CHECK constraint/i);
    db.prepare(`INSERT INTO taxpayer_authorization_decisions VALUES
      ('decision-1','tax-authz','AUTHORIZE','Independent synthetic decision','usr-requester','usr-decider','step-up','2026-08-23')`).run();
    expect(() => db.prepare("UPDATE taxpayer_authorization_decisions SET reason='Changed' WHERE id='decision-1'").run()).toThrow(/IMMUTABLE/);
    expect(() => db.prepare("DELETE FROM taxpayer_authorization_decisions WHERE id='decision-1'").run()).toThrow(/IMMUTABLE/);
    db.close();
  });
});
