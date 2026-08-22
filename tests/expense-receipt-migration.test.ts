import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";
import { DatabaseSync } from "node:sqlite";
import { describe, expect, it } from "vitest";

function applyMigration(db: DatabaseSync, fileName: string) {
  const sql = readFileSync(join(process.cwd(), "drizzle", fileName), "utf8");
  for (const statement of sql.split("--> statement-breakpoint").map((part) => part.trim()).filter(Boolean)) db.exec(statement);
}

function insertExpense(db: DatabaseSync, id: string, status = "DRAFT", receiptDocumentId: string | null = null) {
  db.prepare(`INSERT INTO expenses
    (id,organisation_id,branch_id,category_id,supplier_party_id,project_id,expense_number,expense_date,description,currency,net_cents,tax_cents,total_cents,status,receipt_document_id,created_by,approved_by,created_at,approved_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`).run(
    id, "org-0001", null, "expcat-test-required", null, null, `EXP-${id}`, "2026-08-16", "Synthetic receipt governance test", "NAD",
    10_000, 1_500, 11_500, status, receiptDocumentId, "maker", status === "APPROVED" ? "reviewer" : null, "2026-08-16T08:00:00Z", status === "APPROVED" ? "2026-08-16T08:05:00Z" : null,
  );
}

function insertDocument(db: DatabaseSync, id: string, ownerResourceId: string, scanStatus: string, status: string) {
  db.prepare(`INSERT INTO document_metadata
    (id,organisation_id,owner_domain,owner_resource_id,object_key,file_name,content_type,size_bytes,checksum_sha256,classification,scan_status,status,uploaded_by,uploaded_at,retained_until,legal_hold)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`).run(
    id, "org-0001", "EXPENSE", ownerResourceId, `synthetic/${id}.pdf`, `${id}.pdf`, "application/pdf", 128,
    "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", "TAX_CONFIDENTIAL", scanStatus, status, "maker", "2026-08-16T08:01:00Z", null, 0,
  );
}

describe("expense receipt migration", () => {
  it("backfills the synthetic approved record and enforces clean immutable receipt evidence", () => {
    const db = new DatabaseSync(":memory:");
    const migrations = readdirSync(join(process.cwd(), "drizzle")).filter((name) => /^\d{4}_.+\.sql$/.test(name)).sort();
    const receiptMigration = migrations.at(-1);
    expect(receiptMigration).toBe("0011_melted_weapon_omega.sql");
    for (const migration of migrations.slice(0, -1)) applyMigration(db, migration);
    db.exec("PRAGMA foreign_keys=OFF");

    db.prepare(`INSERT INTO expense_categories
      (id,organisation_id,code,name,default_tax_category,requires_receipt,status,created_at)
      VALUES (?,?,?,?,?,?,?,?)`).run("expcat-test-required", "org-0001", "TEST", "Synthetic required receipt", "STANDARD", 1, "ACTIVE", "2026-08-16T08:00:00Z");
    insertExpense(db, "expense-0001", "APPROVED");
    applyMigration(db, receiptMigration!);

    expect(db.prepare("SELECT receipt_document_id FROM expenses WHERE id='expense-0001'").get()).toEqual({ receipt_document_id: "doc-expense-0001-receipt" });
    expect(db.prepare("SELECT scan_status,status FROM document_metadata WHERE id='doc-expense-0001-receipt'").get()).toEqual({ scan_status: "CLEAN", status: "AVAILABLE" });
    expect(db.prepare("SELECT document_id FROM expense_receipt_links WHERE expense_id='expense-0001'").get()).toEqual({ document_id: "doc-expense-0001-receipt" });

    insertExpense(db, "expense-pending");
    insertDocument(db, "doc-pending", "expense-pending", "PENDING_EXTERNAL_SCANNER", "QUARANTINED");
    expect(() => db.prepare(`INSERT INTO expense_receipt_links VALUES ('link-pending','expense-pending','org-0001','doc-pending','reviewer','2026-08-16T08:02:00Z')`).run()).toThrow(/EXPENSE_RECEIPT_LINK_INVALID/);
    expect(() => db.prepare(`INSERT INTO expense_decisions VALUES ('decision-pending','expense-pending','org-0001','APPROVE','Receipt not ready','reviewer','2026-08-16T08:03:00Z')`).run()).toThrow(/EXPENSE_CLEAN_RECEIPT_REQUIRED/);

    db.prepare("UPDATE document_metadata SET scan_status='CLEAN',status='AVAILABLE' WHERE id='doc-pending'").run();
    db.prepare(`INSERT INTO expense_receipt_links VALUES ('link-clean','expense-pending','org-0001','doc-pending','reviewer','2026-08-16T08:04:00Z')`).run();
    expect(db.prepare("SELECT receipt_document_id FROM expenses WHERE id='expense-pending'").get()).toEqual({ receipt_document_id: "doc-pending" });
    db.prepare(`INSERT INTO expense_decisions VALUES ('decision-clean','expense-pending','org-0001','APPROVE','Clean evidence reviewed','reviewer','2026-08-16T08:05:00Z')`).run();
    expect(db.prepare("SELECT status,approved_by FROM expenses WHERE id='expense-pending'").get()).toEqual({ status: "APPROVED", approved_by: "reviewer" });
    expect(() => db.prepare("DELETE FROM expense_receipt_links WHERE id='link-clean'").run()).toThrow(/EXPENSE_RECEIPT_LINK_IMMUTABLE/);

    insertExpense(db, "expense-cross-owner");
    insertDocument(db, "doc-wrong-owner", "some-other-expense", "CLEAN", "AVAILABLE");
    expect(() => db.prepare(`INSERT INTO expense_receipt_links VALUES ('link-wrong','expense-cross-owner','org-0001','doc-wrong-owner','reviewer','2026-08-16T08:06:00Z')`).run()).toThrow(/EXPENSE_RECEIPT_LINK_INVALID/);
    db.close();
  });
});
