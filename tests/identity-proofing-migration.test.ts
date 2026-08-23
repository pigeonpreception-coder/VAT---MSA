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

function identityFixture(db: DatabaseSync, suffix = "one") {
  db.prepare(`INSERT INTO taxpayers
    (id,vat_number,tin,legal_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
    VALUES (?,?,?,?,?,'ACTIVE','BIMONTHLY','Windhoek',?,?)`).run(
    `tp-identity-${suffix}`, `VAT-IDENTITY-${suffix}`, `TIN-IDENTITY-${suffix}`, `Identity ${suffix}`, "PRIVATE_COMPANY",
    `identity-${suffix}@example.test`, "2026-08-23T08:00:00Z",
  );
  db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at)
    VALUES (?,?,?,?,?,?,'ACTIVE',?)`).run(
    `usr-requester-${suffix}`, `requester-${suffix}`, `requester-${suffix}@example.test`, `Requester ${suffix}`,
    "TAXPAYER_OWNER", `tp-identity-${suffix}`, "2026-08-23T08:00:00Z",
  );
  db.prepare(`INSERT INTO app_users (id,external_user_id,email,display_name,role,taxpayer_id,status,created_at)
    VALUES (?,?,?,?,?,?,'ACTIVE',?)`).run(
    `usr-reviewer-${suffix}`, `reviewer-${suffix}`, `reviewer-${suffix}@example.test`, `Reviewer ${suffix}`,
    "TAXPAYER_REVIEWER", `tp-identity-${suffix}`, "2026-08-23T08:00:00Z",
  );
  db.prepare(`INSERT INTO registration_applications
    (id,idempotency_key,request_hash,vat_number,tin,company_registration_number,legal_name,trading_name,taxpayer_type,
     return_frequency,address,email,status,verification_source,submitted_by,submitted_at,reviewed_at,review_reason)
    VALUES (?,?,?,?,?,NULL,?,NULL,'PRIVATE_COMPANY','BIMONTHLY','Windhoek',?,'PENDING_VERIFICATION','ITAS',?,?,NULL,NULL)`).run(
    `reg-identity-${suffix}`, `idempotency-identity-${suffix}`, `hash-${suffix}`, `VAT-NEW-${suffix}`, `TIN-NEW-${suffix}`,
    `New Identity ${suffix}`, `new-identity-${suffix}@example.test`, `usr-requester-${suffix}`, "2026-08-23T08:01:00Z",
  );
}

function insertPendingProofingCase(db: DatabaseSync, suffix = "one") {
  db.prepare(`INSERT INTO identity_proofing_cases
    (id,subject_type,subject_reference,registration_application_id,provider,provider_environment,provider_reference,status,
     confidence_bps,matched_taxpayer_id,evidence_hash,reason_code,requested_by,reviewed_by,created_at,updated_at,reviewed_at)
    VALUES (?,'TAXPAYER_REGISTRATION',?,?,'ITAS','CONTRACT_PENDING',?,'PENDING_PROVIDER',0,NULL,NULL,
      'AUTHORITATIVE_PROVIDER_CONTRACT_REQUIRED',?,NULL,?,?,NULL)`).run(
    `proof-identity-${suffix}`, `reg-identity-${suffix}`, `reg-identity-${suffix}`, `itas-pending-${suffix}`,
    `usr-requester-${suffix}`, "2026-08-23T08:02:00Z", "2026-08-23T08:02:00Z",
  );
}

describe("Issue 2 identity-proofing database enforcement", () => {
  it("registers the generated proofing schema and required revision", () => {
    const journal = JSON.parse(readFileSync(join(process.cwd(), "drizzle", "meta", "_journal.json"), "utf8"));
    expect(journal.entries.find((entry: { idx: number }) => entry.idx === 17)).toMatchObject({ idx: 17, tag: "0017_identity_proofing_enforcement" });
    const snapshot = JSON.parse(readFileSync(join(process.cwd(), "drizzle", "meta", "0017_snapshot.json"), "utf8"));
    expect(snapshot.tables.identity_proofing_cases).toBeDefined();
    expect(snapshot.tables.identity_reconciliation_candidates).toBeDefined();
    expect(snapshot.tables.identity_mismatch_cases).toBeDefined();
    expect(snapshot.tables.identity_proofing_events).toBeDefined();
    const db = migratedDatabase();
    expect(db.prepare("SELECT source FROM app_schema_revisions WHERE revision='issue2-identity-proofing-2026-08-23'").get())
      .toEqual({ source: "DRIZZLE_MIGRATION_0017" });
    db.close();
  });

  it("keeps synthetic evidence distinguishable and unable to imply authority verification", () => {
    const db = migratedDatabase();
    identityFixture(db);
    insertPendingProofingCase(db);
    const evidenceHash = "a".repeat(64);
    expect(() => db.prepare(`UPDATE identity_proofing_cases SET status='AUTHORITY_VERIFIED',provider_environment='SYNTHETIC_TEST',
      matched_taxpayer_id='tp-identity-one',evidence_hash=?,reviewed_by='usr-reviewer-one',reviewed_at='2026-08-23T08:03:00Z'
      WHERE id='proof-identity-one'`).run(evidenceHash)).toThrow(/IDENTITY_AUTHORITY_EVIDENCE_REQUIRED/);
    expect(() => db.prepare(`UPDATE identity_proofing_cases SET status='SYNTHETIC_MATCHED',evidence_hash=?
      WHERE id='proof-identity-one'`).run(evidenceHash)).toThrow(/IDENTITY_SYNTHETIC_EVIDENCE_INVALID/);
    db.prepare(`UPDATE identity_proofing_cases SET status='SYNTHETIC_MATCHED',provider_environment='SYNTHETIC_TEST',
      confidence_bps=10000,matched_taxpayer_id='tp-identity-one',evidence_hash=?,reason_code='SYNTHETIC_EXACT_IDENTIFIER_MATCH',
      updated_at='2026-08-23T08:03:00Z' WHERE id='proof-identity-one'`).run(evidenceHash);
    expect(db.prepare("SELECT status,provider_environment FROM identity_proofing_cases WHERE id='proof-identity-one'").get())
      .toEqual({ status: "SYNTHETIC_MATCHED", provider_environment: "SYNTHETIC_TEST" });
    expect(db.prepare("SELECT status FROM registration_applications WHERE id='reg-identity-one'").get())
      .toEqual({ status: "PENDING_VERIFICATION" });
    expect(db.prepare("SELECT id FROM organisations WHERE taxpayer_id='tp-identity-one'").get()).toBeUndefined();
    db.close();
  });

  it("enforces identifier uniqueness, one proofing case per registration and append-only evidence", () => {
    const db = migratedDatabase();
    identityFixture(db);
    insertPendingProofingCase(db);
    expect(() => db.prepare(`INSERT INTO taxpayers
      (id,vat_number,tin,legal_name,taxpayer_type,vat_status,return_frequency,address,email,created_at)
      VALUES ('tp-duplicate-tin','VAT-OTHER','TIN-IDENTITY-one','Duplicate','PRIVATE_COMPANY','ACTIVE','BIMONTHLY','Windhoek','duplicate@example.test','2026-08-23')`).run())
      .toThrow(/UNIQUE constraint/i);
    expect(() => db.prepare(`INSERT INTO identity_proofing_cases
      (id,subject_type,subject_reference,registration_application_id,provider,provider_environment,status,confidence_bps,reason_code,requested_by,created_at,updated_at)
      VALUES ('proof-duplicate','TAXPAYER_REGISTRATION','reg-identity-one','reg-identity-one','ITAS','CONTRACT_PENDING','PENDING_PROVIDER',0,
        'DUPLICATE_TEST','usr-requester-one','2026-08-23','2026-08-23')`).run()).toThrow(/UNIQUE constraint/i);
    db.prepare(`INSERT INTO identity_reconciliation_candidates
      (id,proofing_case_id,candidate_taxpayer_id,outcome,confidence_bps,matched_fields,conflicting_fields,evidence_hash,created_at)
      VALUES ('candidate-one','proof-identity-one','tp-identity-one','MANUAL_REVIEW',4000,'["vat_number"]','[]',?,'2026-08-23')`).run("b".repeat(64));
    expect(() => db.prepare("UPDATE identity_reconciliation_candidates SET confidence_bps=5000 WHERE id='candidate-one'").run())
      .toThrow(/IDENTITY_RECONCILIATION_EVIDENCE_IMMUTABLE/);
    expect(() => db.prepare("DELETE FROM identity_reconciliation_candidates WHERE id='candidate-one'").run())
      .toThrow(/IDENTITY_RECONCILIATION_EVIDENCE_IMMUTABLE/);
    db.prepare(`INSERT INTO identity_proofing_events
      (id,proofing_case_id,event_type,from_status,to_status,confidence_bps,reason_code,evidence_hash,actor_id,occurred_at)
      VALUES ('proof-event-one','proof-identity-one','CandidateReconciled','PENDING_PROVIDER','MANUAL_REVIEW',4000,'ONE_IDENTIFIER_MATCH',?,
        'usr-requester-one','2026-08-23T08:03:00Z')`).run("c".repeat(64));
    expect(() => db.prepare("UPDATE identity_proofing_events SET reason_code='CHANGED' WHERE id='proof-event-one'").run())
      .toThrow(/IDENTITY_PROOFING_EVENT_IMMUTABLE/);
    expect(() => db.prepare("DELETE FROM identity_proofing_events WHERE id='proof-event-one'").run())
      .toThrow(/IDENTITY_PROOFING_EVENT_IMMUTABLE/);
    db.close();
  });

  it("requires an independent mismatch resolver and makes authority decisions immutable", () => {
    const db = migratedDatabase();
    identityFixture(db);
    insertPendingProofingCase(db);
    db.prepare(`INSERT INTO identity_mismatch_cases
      (id,proofing_case_id,mismatch_type,conflicting_fields,details_hash,status,resolution_code,assigned_to,resolved_by,opened_at,resolved_at)
      VALUES ('mismatch-one','proof-identity-one','IDENTIFIER_CONFLICT','["tin"]',?,'OPEN',NULL,NULL,NULL,'2026-08-23',NULL)`).run("d".repeat(64));
    expect(() => db.prepare(`UPDATE identity_mismatch_cases SET status='RESOLVED',resolution_code='FALSE_POSITIVE',
      resolved_by='usr-requester-one',resolved_at='2026-08-23T08:04:00Z' WHERE id='mismatch-one'`).run())
      .toThrow(/IDENTITY_MISMATCH_INDEPENDENT_REVIEW_REQUIRED/);
    db.prepare(`UPDATE identity_mismatch_cases SET status='RESOLVED',resolution_code='FALSE_POSITIVE',
      resolved_by='usr-reviewer-one',resolved_at='2026-08-23T08:04:00Z' WHERE id='mismatch-one'`).run();
    expect(db.prepare("SELECT status,resolved_by FROM identity_mismatch_cases WHERE id='mismatch-one'").get())
      .toEqual({ status: "RESOLVED", resolved_by: "usr-reviewer-one" });

    identityFixture(db, "verified");
    db.prepare(`INSERT INTO identity_proofing_cases
      (id,subject_type,subject_reference,registration_application_id,provider,provider_environment,provider_reference,status,
       confidence_bps,matched_taxpayer_id,evidence_hash,reason_code,requested_by,reviewed_by,created_at,updated_at,reviewed_at)
      VALUES ('proof-authority','TAXPAYER_REGISTRATION','reg-identity-verified','reg-identity-verified','ITAS','PRODUCTION_EQUIVALENT',
       'authority-test-reference','AUTHORITY_VERIFIED',10000,'tp-identity-verified',?,'AUTHORITY_EXACT_MATCH','usr-requester-verified',
       'usr-reviewer-verified','2026-08-23','2026-08-23','2026-08-23')`).run("e".repeat(64));
    expect(() => db.prepare("UPDATE identity_proofing_cases SET status='REJECTED' WHERE id='proof-authority'").run())
      .toThrow(/IDENTITY_AUTHORITY_DECISION_IMMUTABLE/);
    expect(() => db.prepare("DELETE FROM identity_proofing_cases WHERE id='proof-authority'").run())
      .toThrow(/IDENTITY_PROOFING_HISTORY_IMMUTABLE/);
    db.close();
  });
});
