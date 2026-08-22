CREATE TABLE `expense_receipt_links` (
	`id` text PRIMARY KEY NOT NULL,
	`expense_id` text NOT NULL,
	`organisation_id` text NOT NULL,
	`document_id` text NOT NULL,
	`linked_by` text NOT NULL,
	`linked_at` text NOT NULL,
	FOREIGN KEY (`expense_id`) REFERENCES `expenses`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`document_id`) REFERENCES `document_metadata`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`linked_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_expense_receipt_link_expense` ON `expense_receipt_links` (`expense_id`);--> statement-breakpoint
CREATE UNIQUE INDEX `ux_expense_receipt_link_document` ON `expense_receipt_links` (`document_id`);--> statement-breakpoint
CREATE INDEX `idx_expense_receipt_links_organisation` ON `expense_receipt_links` (`organisation_id`,`linked_at`);--> statement-breakpoint
INSERT OR IGNORE INTO document_metadata
  (id,organisation_id,owner_domain,owner_resource_id,object_key,file_name,content_type,size_bytes,checksum_sha256,classification,scan_status,status,uploaded_by,uploaded_at,retained_until,legal_hold)
SELECT 'doc-expense-0001-receipt','org-0001','EXPENSE','expense-0001','synthetic/expenses/expense-0001/clean-receipt.pdf','synthetic-clean-receipt-expense-0001.pdf','application/pdf',2048,
  '0b440d1bb4c48cf586548309d51df4a7c0bcdc349ad1505e546d8d59f4cc6946','TAX_CONFIDENTIAL','CLEAN','AVAILABLE','usr-local-admin','2026-08-09T09:55:00Z',NULL,0
WHERE EXISTS (SELECT 1 FROM expenses WHERE id='expense-0001' AND organisation_id='org-0001');--> statement-breakpoint
UPDATE expenses SET receipt_document_id='doc-expense-0001-receipt'
WHERE id='expense-0001' AND organisation_id='org-0001' AND status='APPROVED';--> statement-breakpoint
INSERT OR IGNORE INTO expense_receipt_links
  (id,expense_id,organisation_id,document_id,linked_by,linked_at)
SELECT 'expense-receipt-link-0001','expense-0001','org-0001','doc-expense-0001-receipt','usr-local-admin','2026-08-09T09:56:00Z'
WHERE EXISTS (SELECT 1 FROM document_metadata WHERE id='doc-expense-0001-receipt');--> statement-breakpoint
CREATE TRIGGER validate_expense_receipt_link
  BEFORE INSERT ON expense_receipt_links
  WHEN NOT EXISTS (
    SELECT 1 FROM expenses e JOIN document_metadata d
      ON d.id=NEW.document_id AND d.organisation_id=e.organisation_id
    WHERE e.id=NEW.expense_id AND e.organisation_id=NEW.organisation_id
      AND e.status='DRAFT' AND e.receipt_document_id IS NULL
      AND d.owner_domain='EXPENSE' AND d.owner_resource_id=e.id
      AND d.scan_status='CLEAN' AND d.status='AVAILABLE'
  )
  BEGIN
    SELECT RAISE(ABORT,'EXPENSE_RECEIPT_LINK_INVALID');
  END;--> statement-breakpoint
CREATE TRIGGER apply_expense_receipt_link
  AFTER INSERT ON expense_receipt_links
  BEGIN
    UPDATE expenses SET receipt_document_id=NEW.document_id
    WHERE id=NEW.expense_id AND organisation_id=NEW.organisation_id AND status='DRAFT' AND receipt_document_id IS NULL;
  END;--> statement-breakpoint
CREATE TRIGGER prevent_expense_receipt_link_update
  BEFORE UPDATE ON expense_receipt_links
  BEGIN
    SELECT RAISE(ABORT,'EXPENSE_RECEIPT_LINK_IMMUTABLE');
  END;--> statement-breakpoint
CREATE TRIGGER prevent_expense_receipt_link_delete
  BEFORE DELETE ON expense_receipt_links
  BEGIN
    SELECT RAISE(ABORT,'EXPENSE_RECEIPT_LINK_IMMUTABLE');
  END;--> statement-breakpoint
CREATE TRIGGER enforce_expense_clean_receipt_decision
  BEFORE INSERT ON expense_decisions
  WHEN NEW.decision='APPROVE' AND (
    EXISTS (
      SELECT 1 FROM expenses e JOIN expense_categories c ON c.id=e.category_id
      WHERE e.id=NEW.expense_id AND e.organisation_id=NEW.organisation_id
        AND c.requires_receipt=1 AND e.receipt_document_id IS NULL
    ) OR EXISTS (
      SELECT 1 FROM expenses e WHERE e.id=NEW.expense_id AND e.organisation_id=NEW.organisation_id
        AND e.receipt_document_id IS NOT NULL AND NOT EXISTS (
          SELECT 1 FROM expense_receipt_links l JOIN document_metadata d ON d.id=l.document_id
          WHERE l.expense_id=e.id AND l.organisation_id=e.organisation_id
            AND l.document_id=e.receipt_document_id AND d.organisation_id=e.organisation_id
            AND d.owner_domain='EXPENSE' AND d.owner_resource_id=e.id
            AND d.scan_status='CLEAN' AND d.status='AVAILABLE'
        )
    )
  )
  BEGIN
    SELECT RAISE(ABORT,'EXPENSE_CLEAN_RECEIPT_REQUIRED');
  END;--> statement-breakpoint
CREATE TRIGGER enforce_expense_clean_receipt_status
  BEFORE UPDATE OF status ON expenses
  WHEN NEW.status='APPROVED' AND (
    EXISTS (SELECT 1 FROM expense_categories c WHERE c.id=NEW.category_id AND c.requires_receipt=1 AND NEW.receipt_document_id IS NULL)
    OR (NEW.receipt_document_id IS NOT NULL AND NOT EXISTS (
      SELECT 1 FROM expense_receipt_links l JOIN document_metadata d ON d.id=l.document_id
      WHERE l.expense_id=NEW.id AND l.organisation_id=NEW.organisation_id
        AND l.document_id=NEW.receipt_document_id AND d.organisation_id=NEW.organisation_id
        AND d.owner_domain='EXPENSE' AND d.owner_resource_id=NEW.id
        AND d.scan_status='CLEAN' AND d.status='AVAILABLE'
    ))
  )
  BEGIN
    SELECT RAISE(ABORT,'EXPENSE_CLEAN_RECEIPT_REQUIRED');
  END;
