import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";
import { DatabaseSync } from "node:sqlite";
import { describe, expect, it } from "vitest";

function applyMigration(db: DatabaseSync, fileName: string) {
  const sql = readFileSync(join(process.cwd(), "drizzle", fileName), "utf8");
  for (const statement of sql.split("--> statement-breakpoint").map((part) => part.trim()).filter(Boolean)) db.exec(statement);
}

type SignupFixture = {
  id?: string;
  reference?: string;
  idempotency?: string;
  email?: string;
  planId?: string;
  vat?: string;
  tin?: string;
};

function insertSignup(db: DatabaseSync, fixture: SignupFixture = {}) {
  const timestamp = "2026-08-23T12:00:00Z";
  db.prepare(`INSERT INTO self_serve_signup_applications
    (id,public_reference,idempotency_key,request_hash,applicant_name,applicant_role,contact_email,
     identity_provider,identity_subject_hash,country_code,requested_plan_id,vat_number,tin,
     company_registration_number,legal_name,trading_name,taxpayer_type,return_frequency,address,
     terms_version,privacy_notice_version,authority_attested_at,terms_accepted_at,privacy_notice_accepted_at,
     status,identity_status,taxpayer_verification_status,licence_status,promoted_registration_application_id,submitted_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`).run(
    fixture.id ?? "signup-1",
    fixture.reference ?? "VMS-2026-0000000001",
    fixture.idempotency ?? "00000000-0000-4000-8000-000000000001",
    "a".repeat(64),
    "Ndeshi Amutenya",
    "OWNER",
    fixture.email ?? "ndeshi@example.test",
    null,
    null,
    "NA",
    fixture.planId ?? "plan-signup",
    fixture.vat ?? "VAT-SIGNUP-001",
    fixture.tin ?? "TIN-SIGNUP-001",
    "BIPA-SIGNUP-001",
    "Omatako Digital Services (Pty) Ltd",
    "Omatako Digital",
    "PRIVATE_COMPANY",
    "BIMONTHLY",
    "17 Mandume Ndemufayo Avenue, Windhoek",
    "2026-08-23",
    "2026-08-23",
    timestamp,
    timestamp,
    timestamp,
    "PENDING_VERIFICATION",
    "VERIFICATION_REQUIRED",
    "AWAITING_PROVIDER_CONTRACT",
    "NOT_ACTIVATED",
    null,
    timestamp,
  );
}

describe("self-serve signup database boundary", () => {
  it("enforces immutable pending intake without creating activation records", () => {
    const db = new DatabaseSync(":memory:");
    const migrations = readdirSync(join(process.cwd(), "drizzle")).filter((name) => /^\d{4}_.+\.sql$/.test(name)).sort();
    expect(migrations.at(-1)).toBe("0013_self_serve_signup.sql");
    for (const migration of migrations) applyMigration(db, migration);
    db.exec("PRAGMA foreign_keys=ON");
    db.prepare(`INSERT INTO license_plans VALUES
      ('plan-signup','PILOT_PROFESSIONAL','Professional Pilot',1,'ACTIVE','2026-01-01T00:00:00Z',NULL,'2026-01-01T00:00:00Z')`).run();

    const before = {
      users: db.prepare("SELECT COUNT(*) AS count FROM app_users").get(),
      taxpayers: db.prepare("SELECT COUNT(*) AS count FROM taxpayers").get(),
      organisations: db.prepare("SELECT COUNT(*) AS count FROM organisations").get(),
      subscriptions: db.prepare("SELECT COUNT(*) AS count FROM subscriptions").get(),
      licences: db.prepare("SELECT COUNT(*) AS count FROM organisation_licenses").get(),
    };
    insertSignup(db);
    expect(db.prepare("SELECT status,identity_status,taxpayer_verification_status,licence_status FROM self_serve_signup_applications").get()).toEqual({
      status: "PENDING_VERIFICATION",
      identity_status: "VERIFICATION_REQUIRED",
      taxpayer_verification_status: "AWAITING_PROVIDER_CONTRACT",
      licence_status: "NOT_ACTIVATED",
    });
    expect({
      users: db.prepare("SELECT COUNT(*) AS count FROM app_users").get(),
      taxpayers: db.prepare("SELECT COUNT(*) AS count FROM taxpayers").get(),
      organisations: db.prepare("SELECT COUNT(*) AS count FROM organisations").get(),
      subscriptions: db.prepare("SELECT COUNT(*) AS count FROM subscriptions").get(),
      licences: db.prepare("SELECT COUNT(*) AS count FROM organisation_licenses").get(),
    }).toEqual(before);

    expect(() => db.prepare("UPDATE self_serve_signup_applications SET legal_name='Changed' WHERE id='signup-1'").run())
      .toThrow(/SELF_SERVE_SIGNUP_INPUT_IMMUTABLE/);
    expect(() => db.prepare("UPDATE self_serve_signup_applications SET licence_status='ACTIVATED' WHERE id='signup-1'").run())
      .toThrow(/SELF_SERVE_SIGNUP_INPUT_IMMUTABLE/);
    expect(() => db.prepare("UPDATE self_serve_signup_applications SET status='APPROVED_FOR_PROVISIONING' WHERE id='signup-1'").run())
      .toThrow(/SELF_SERVE_SIGNUP_TRANSITION_INVALID/);
    db.prepare("UPDATE self_serve_signup_applications SET status='UNDER_REVIEW' WHERE id='signup-1'").run();
    db.prepare("UPDATE self_serve_signup_applications SET status='APPROVED_FOR_PROVISIONING' WHERE id='signup-1'").run();
    expect(() => db.prepare("UPDATE self_serve_signup_applications SET promoted_registration_application_id='reg-missing' WHERE id='signup-1'").run())
      .toThrow(/SELF_SERVE_SIGNUP_PROMOTION_NOT_APPROVED/);
    expect(() => db.prepare("DELETE FROM self_serve_signup_applications WHERE id='signup-1'").run())
      .toThrow(/SELF_SERVE_SIGNUP_IMMUTABLE_HISTORY/);
    db.close();
  });

  it("rejects inactive plans and active duplicate VAT or TIN identities", () => {
    const db = new DatabaseSync(":memory:");
    const migrations = readdirSync(join(process.cwd(), "drizzle")).filter((name) => /^\d{4}_.+\.sql$/.test(name)).sort();
    for (const migration of migrations) applyMigration(db, migration);
    db.prepare(`INSERT INTO license_plans VALUES
      ('plan-signup','PILOT_PROFESSIONAL','Professional Pilot',1,'ACTIVE','2026-01-01T00:00:00Z',NULL,'2026-01-01T00:00:00Z'),
      ('plan-inactive','INACTIVE','Inactive',1,'RETIRED','2026-01-01T00:00:00Z',NULL,'2026-01-01T00:00:00Z')`).run();
    expect(() => insertSignup(db, { planId: "plan-inactive" })).toThrow(/SELF_SERVE_SIGNUP_PLAN_UNAVAILABLE/);
    insertSignup(db);
    expect(() => insertSignup(db, {
      id: "signup-2",
      reference: "VMS-2026-0000000002",
      idempotency: "00000000-0000-4000-8000-000000000002",
      email: "second@example.test",
      tin: "TIN-SIGNUP-002",
    })).toThrow(/UNIQUE constraint failed/i);
    db.close();
  });

  it("rejects identifiers already owned by a canonical taxpayer", () => {
    const db = new DatabaseSync(":memory:");
    const migrations = readdirSync(join(process.cwd(), "drizzle")).filter((name) => /^\d{4}_.+\.sql$/.test(name)).sort();
    for (const migration of migrations) applyMigration(db, migration);
    db.prepare(`INSERT INTO license_plans VALUES
      ('plan-signup','PILOT_PROFESSIONAL','Professional Pilot',1,'ACTIVE','2026-01-01T00:00:00Z',NULL,'2026-01-01T00:00:00Z')`).run();
    db.prepare(`INSERT INTO taxpayers
      (id,vat_number,tin,legal_name,trading_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES ('tp-existing','VAT-EXISTING','TIN-EXISTING','Existing Taxpayer',NULL,'PRIVATE_COMPANY','ACTIVE','BIMONTHLY','Windhoek','existing@example.test','2026-01-01T00:00:00Z')`).run();
    expect(() => insertSignup(db, { vat: "VAT-EXISTING", tin: "TIN-NEW" }))
      .toThrow(/SELF_SERVE_SIGNUP_CANONICAL_TAXPAYER_EXISTS/);
    db.close();
  });
});
