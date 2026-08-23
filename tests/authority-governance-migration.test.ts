import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";
import { DatabaseSync } from "node:sqlite";
import { describe, expect, it } from "vitest";

const migrationNames = () => readdirSync(join(process.cwd(), "drizzle")).filter((name) => /^\d{4}_.+\.sql$/.test(name)).sort();

function applyMigration(db: DatabaseSync, name: string) {
  const sql = readFileSync(join(process.cwd(), "drizzle", name), "utf8");
  for (const statement of sql.split("--> statement-breakpoint").map((part) => part.trim()).filter(Boolean)) db.exec(statement);
}

function applyThrough(lastIndex = 19) {
  const db = new DatabaseSync(":memory:");
  for (const name of migrationNames().filter((candidate) => Number(candidate.slice(0, 4)) <= lastIndex)) applyMigration(db, name);
  db.exec("PRAGMA foreign_keys=ON");
  return db;
}

function insertAuthorityFixture(db: DatabaseSync) {
  db.exec(`
    INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at) VALUES
      ('authority-maker','authority-maker','authority-maker@example.test','Authority Maker','NAMRA_SYSTEM_ADMIN',NULL,'ACTIVE',CURRENT_TIMESTAMP),
      ('authority-approver','authority-approver','authority-approver@example.test','Authority Approver','NAMRA_SYSTEM_ADMIN',NULL,'ACTIVE',CURRENT_TIMESTAMP);
    INSERT INTO identity_providers (id,provider_key,display_name,provider_type,authority_level,issuer,status,configuration_status,created_at,updated_at)
      VALUES ('provider-itas-test','ITAS','ITAS Test Boundary','GOVERNMENT','NATIONAL',NULL,'DISABLED','AWAITING_AUTHORITY_CONTRACT',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP);
    INSERT INTO countries (code,iso3_code,name,currency_code,status,created_at)
      VALUES ('NA','NAM','Namibia','NAD','ACTIVE',CURRENT_TIMESTAMP);
    INSERT INTO tax_jurisdictions (id,country_code,code,name,status,created_at)
      VALUES ('authority-jurisdiction-na','NA','NA','Namibia','ACTIVE',CURRENT_TIMESTAMP);
    INSERT INTO tax_authorities (id,jurisdiction_id,code,name,status,created_at)
      VALUES ('authority-na','authority-jurisdiction-na','NAMRA','Namibia Revenue Agency','ACTIVE',CURRENT_TIMESTAMP);
    INSERT INTO tax_authority_administrators
      (id,tax_authority_id,user_id,status,effective_from,effective_to,appointed_by,approval_reference) VALUES
      ('authority-admin-maker','authority-na','authority-maker','ACTIVE',datetime('now','-1 day'),NULL,'TEST_GOVERNANCE','TEST-MAKER'),
      ('authority-admin-approver','authority-na','authority-approver','ACTIVE',datetime('now','-1 day'),NULL,'TEST_GOVERNANCE','TEST-APPROVER');
  `);
}

describe("Issue 4 authority-governance migration", () => {
  it("retains migration 0019 and its generated schema snapshot", () => {
    const journal = JSON.parse(readFileSync(join(process.cwd(), "drizzle", "meta", "_journal.json"), "utf8"));
    expect(journal.entries).toContainEqual(expect.objectContaining({ idx: 19, tag: "0019_authority_governance" }));
    const snapshot = JSON.parse(readFileSync(join(process.cwd(), "drizzle", "meta", "0019_snapshot.json"), "utf8"));
    expect(snapshot.tables.tax_authority_units).toBeDefined();
    expect(snapshot.tables.tax_authority_federation_connections).toBeDefined();
    expect(snapshot.tables.tax_authority_onboarding_cases).toBeDefined();
    expect(snapshot.tables.tax_authority_onboarding_decisions).toBeDefined();
    expect(snapshot.tables.tax_authority_access_reviews).toBeDefined();
  });

  it("backfills an existing authority as production-blocked with contract-pending federation and a review obligation", () => {
    const db = applyThrough(18);
    insertAuthorityFixture(db);
    applyMigration(db, "0019_authority_governance.sql");
    expect(db.prepare("SELECT status,target_environment,readiness_reference FROM tax_authority_onboarding_cases WHERE tax_authority_id='authority-na'").get())
      .toEqual({ status: "BLOCKED_EXTERNAL", target_environment: "PRODUCTION", readiness_reference: "PR-013-REQUIRED" });
    expect(db.prepare("SELECT environment,protocol,status FROM tax_authority_federation_connections WHERE tax_authority_id='authority-na'").get())
      .toEqual({ environment: "CONTRACT_PENDING", protocol: "UNCONFIRMED", status: "CONTRACT_PENDING" });
    expect(db.prepare("SELECT review_type,status FROM tax_authority_access_reviews WHERE tax_authority_id='authority-na'").get())
      .toEqual({ review_type: "QUARTERLY", status: "OPEN" });
    expect(db.prepare("SELECT source FROM app_schema_revisions WHERE revision='issue4-authority-governance-2026-08-23'").get())
      .toEqual({ source: "DRIZZLE_MIGRATION_0019" });
    db.close();
  });

  it("requires an independent, scoped, step-up and review-bound local-staging decision", () => {
    const db = applyThrough();
    insertAuthorityFixture(db);
    db.exec(`
      INSERT INTO tax_authority_units VALUES ('authority-unit-hq','authority-na',NULL,'HQ','Head Office','HEAD_OFFICE','ACTIVE',CURRENT_TIMESTAMP);
      INSERT INTO tax_authority_role_assignments
        (id,tax_authority_id,authority_unit_id,user_id,role_code,scope,status,effective_from,effective_to,requested_by,approved_by,approval_reference,created_at)
        VALUES ('authority-role-approver','authority-na','authority-unit-hq','authority-approver','AUTHORITY_ACTIVATION_APPROVER','{}','ACTIVE',
          datetime('now','-1 day'),NULL,'authority-approver','authority-maker','TEST-APPROVAL',CURRENT_TIMESTAMP);
      INSERT INTO tax_authority_access_reviews
        (id,tax_authority_id,review_type,period_start,due_at,status,owner_id,completed_by,completed_at,created_at)
        VALUES ('authority-review-current','authority-na','QUARTERLY',date('now','-1 month'),datetime('now','+2 months'),'OPEN','authority-maker',NULL,NULL,CURRENT_TIMESTAMP);
      INSERT INTO tax_authority_onboarding_cases
        (id,tax_authority_id,target_environment,status,purpose,evidence_bundle_hash,readiness_reference,requested_by,submitted_at,approved_at,activated_at,created_at,updated_at)
        VALUES ('authority-case-local','authority-na','LOCAL_STAGING','SUBMITTED','Exercise independent local staging governance without production activation.',NULL,
          'TEST-LOCAL-STAGING','authority-maker',CURRENT_TIMESTAMP,NULL,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP);
      INSERT INTO tax_authority_onboarding_cases
        (id,tax_authority_id,target_environment,status,purpose,evidence_bundle_hash,readiness_reference,requested_by,submitted_at,approved_at,activated_at,created_at,updated_at)
        VALUES ('authority-case-self','authority-na','LOCAL_STAGING','SUBMITTED','Prove that even an eligible activation approver cannot decide their own onboarding case.',NULL,
          'TEST-SELF-APPROVAL','authority-approver',CURRENT_TIMESTAMP,NULL,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP);
    `);
    expect(() => db.prepare(`INSERT INTO tax_authority_onboarding_decisions
      VALUES ('authority-self','authority-case-self','LOCAL_STAGING_APPROVAL','APPROVE','Self approval is not allowed for authority onboarding.',
        'authority-approver','authority-approver','${"a".repeat(64)}','verified-step-up:self-test',CURRENT_TIMESTAMP)`).run()).toThrow(/CHECK constraint/i);
    expect(() => db.prepare("UPDATE tax_authority_onboarding_cases SET status='LOCAL_STAGING_READY' WHERE id='authority-case-local'").run())
      .toThrow(/LOCAL_STAGING_APPROVAL_REQUIRED/);
    db.prepare(`INSERT INTO tax_authority_onboarding_decisions
      VALUES ('authority-decision-local','authority-case-local','LOCAL_STAGING_APPROVAL','APPROVE','Independent local staging governance review completed.',
        'authority-maker','authority-approver','${"b".repeat(64)}','verified-step-up:independent-test',CURRENT_TIMESTAMP)`).run();
    db.prepare("UPDATE tax_authority_onboarding_cases SET status='LOCAL_STAGING_READY',approved_at=CURRENT_TIMESTAMP WHERE id='authority-case-local'").run();
    expect(db.prepare("SELECT status FROM tax_authority_onboarding_cases WHERE id='authority-case-local'").get()).toEqual({ status: "LOCAL_STAGING_READY" });
    expect(() => db.prepare("UPDATE tax_authority_onboarding_decisions SET reason='Changed evidence' WHERE id='authority-decision-local'").run())
      .toThrow(/DECISION_IMMUTABLE/);
    expect(() => db.prepare("DELETE FROM tax_authority_onboarding_decisions WHERE id='authority-decision-local'").run())
      .toThrow(/DECISION_IMMUTABLE/);
    db.close();
  });

  it("rejects cross-authority hierarchy, maker/approver conflicts and unsupported production activation", () => {
    const db = applyThrough();
    insertAuthorityFixture(db);
    db.exec(`
      INSERT INTO tax_authorities VALUES ('authority-na-second','authority-jurisdiction-na','NAMRA-2','Second Test Authority','ACTIVE',CURRENT_TIMESTAMP);
      INSERT INTO tax_authority_units VALUES ('authority-unit-first','authority-na',NULL,'HQ','Head Office','HEAD_OFFICE','ACTIVE',CURRENT_TIMESTAMP);
      INSERT INTO tax_authority_units VALUES ('authority-unit-second','authority-na-second',NULL,'HQ','Second Head Office','HEAD_OFFICE','ACTIVE',CURRENT_TIMESTAMP);
      INSERT INTO tax_authority_role_assignments
        (id,tax_authority_id,authority_unit_id,user_id,role_code,scope,status,effective_from,effective_to,requested_by,approved_by,approval_reference,created_at)
        VALUES ('authority-role-maker','authority-na','authority-unit-first','authority-maker','AUTHORITY_ONBOARDING_MAKER','{}','ACTIVE',
          datetime('now','-1 day'),NULL,'authority-maker','authority-approver','TEST-MAKER',CURRENT_TIMESTAMP);
      INSERT INTO tax_authority_onboarding_cases
        (id,tax_authority_id,target_environment,status,purpose,evidence_bundle_hash,readiness_reference,requested_by,submitted_at,approved_at,activated_at,created_at,updated_at)
        VALUES ('authority-case-production','authority-na','PRODUCTION','BLOCKED_EXTERNAL','Production onboarding remains blocked without accepted external evidence.',
          '${"c".repeat(64)}','PR-013-TEST','authority-maker',CURRENT_TIMESTAMP,NULL,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP);
    `);
    expect(() => db.prepare(`INSERT INTO tax_authority_units
      VALUES ('authority-unit-invalid','authority-na-second','authority-unit-first','INVALID','Invalid Cross Scope','DIVISION','ACTIVE',CURRENT_TIMESTAMP)`).run())
      .toThrow(/PARENT_SCOPE_MISMATCH/);
    expect(() => db.prepare(`INSERT INTO tax_authority_role_assignments
      (id,tax_authority_id,authority_unit_id,user_id,role_code,scope,status,effective_from,effective_to,requested_by,approved_by,approval_reference,created_at)
      VALUES ('authority-role-conflict','authority-na','authority-unit-first','authority-maker','AUTHORITY_ACTIVATION_APPROVER','{}','ACTIVE',
        CURRENT_TIMESTAMP,NULL,'authority-maker','authority-approver','TEST-CONFLICT',CURRENT_TIMESTAMP)`).run()).toThrow(/SOD_CONFLICT/);
    expect(() => db.prepare(`INSERT INTO tax_authority_federation_connections
      (id,tax_authority_id,identity_provider_id,environment,protocol,issuer,audience,metadata_hash,claims_contract_hash,assurance_profile,status,
       requested_by,reviewed_by,checked_at,expires_at,created_at,updated_at)
      VALUES ('authority-fed-invalid','authority-na','provider-itas-test','SYNTHETIC_TEST','OIDC','https://synthetic.invalid','vat-msa',
        '${"d".repeat(64)}','${"e".repeat(64)}','MFA','PRODUCTION_APPROVED','authority-maker','authority-approver',CURRENT_TIMESTAMP,datetime('now','+1 day'),CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)`).run())
      .toThrow(/FEDERATION_PRODUCTION_EVIDENCE_REQUIRED/);
    expect(() => db.prepare("UPDATE tax_authority_onboarding_cases SET status='PRODUCTION_ACTIVATED',activated_at=CURRENT_TIMESTAMP WHERE id='authority-case-production'").run())
      .toThrow(/PRODUCTION_ACTIVATION_EVIDENCE_REQUIRED/);
    db.close();
  });
});
