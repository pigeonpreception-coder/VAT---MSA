CREATE TABLE `app_schema_revisions` (
	`revision` text PRIMARY KEY NOT NULL,
	`applied_at` text NOT NULL,
	`source` text NOT NULL
);
--> statement-breakpoint
CREATE TABLE `step_up_evidence_uses` (
	`evidence_digest` text PRIMARY KEY NOT NULL,
	`actor_id` text NOT NULL,
	`issued_at` text NOT NULL,
	`expires_at` text NOT NULL,
	`used_at` text NOT NULL,
	FOREIGN KEY (`actor_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE INDEX `idx_step_up_evidence_expiry` ON `step_up_evidence_uses` (`expires_at`);
--> statement-breakpoint
ALTER TABLE `certificates` ADD `rule_set_version` text;
--> statement-breakpoint
ALTER TABLE `invoices` ADD `tax_rule_set_id` text REFERENCES tax_rule_sets(id);
--> statement-breakpoint
UPDATE invoices SET tax_rule_set_id='taxrule-na-pilot-2026-1'
WHERE tax_rule_set_id IS NULL AND EXISTS (SELECT 1 FROM tax_rule_sets WHERE id='taxrule-na-pilot-2026-1');
--> statement-breakpoint
UPDATE certificates SET rule_set_version='NA-VAT-PILOT-2026.1'
WHERE rule_set_version IS NULL AND EXISTS (
  SELECT 1 FROM invoices i WHERE i.id=certificates.invoice_id AND i.tax_rule_set_id='taxrule-na-pilot-2026-1'
);
--> statement-breakpoint
CREATE TRIGGER require_invoice_tax_rule_set BEFORE INSERT ON invoices
WHEN NEW.currency<>'NAD' OR NEW.tax_rule_set_id IS NULL OR NOT EXISTS (
  SELECT 1 FROM tax_rule_sets r WHERE r.id=NEW.tax_rule_set_id AND r.jurisdiction='NA'
    AND r.status='AUTHORITY_APPROVED' AND trim(COALESCE(r.legal_authority_reference,''))<>''
    AND r.effective_from<=NEW.issue_date AND (r.effective_to IS NULL OR r.effective_to>=NEW.issue_date)
) BEGIN SELECT RAISE(ABORT,'APPROVED_TAX_RULE_SET_REQUIRED'); END;
--> statement-breakpoint
CREATE TRIGGER require_invoice_tax_rule_set_update BEFORE UPDATE OF tax_rule_set_id,issue_date,currency ON invoices
WHEN NEW.currency<>'NAD' OR NEW.tax_rule_set_id IS NULL OR NOT EXISTS (
  SELECT 1 FROM tax_rule_sets r WHERE r.id=NEW.tax_rule_set_id AND r.jurisdiction='NA'
    AND r.status='AUTHORITY_APPROVED' AND trim(COALESCE(r.legal_authority_reference,''))<>''
    AND r.effective_from<=NEW.issue_date AND (r.effective_to IS NULL OR r.effective_to>=NEW.issue_date)
) BEGIN SELECT RAISE(ABORT,'APPROVED_TAX_RULE_SET_REQUIRED'); END;
--> statement-breakpoint
CREATE TRIGGER enforce_invoice_line_tax_rule BEFORE INSERT ON invoice_lines
WHEN NOT EXISTS (
  SELECT 1 FROM invoices i JOIN tax_rule_sets r ON r.id=i.tax_rule_set_id WHERE i.id=NEW.invoice_id AND (
    (NEW.tax_category='STANDARD' AND NEW.tax_rate_bps=r.standard_rate_bps)
    OR (NEW.tax_category IN ('ZERO_RATED','EXEMPT','OUTSIDE_SCOPE') AND NEW.tax_rate_bps=0)
  )
) BEGIN SELECT RAISE(ABORT,'INVOICE_LINE_TAX_RULE_MISMATCH'); END;
--> statement-breakpoint
CREATE TRIGGER enforce_invoice_line_tax_rule_update BEFORE UPDATE OF tax_rate_bps,tax_category,invoice_id ON invoice_lines
WHEN NOT EXISTS (
  SELECT 1 FROM invoices i JOIN tax_rule_sets r ON r.id=i.tax_rule_set_id WHERE i.id=NEW.invoice_id AND (
    (NEW.tax_category='STANDARD' AND NEW.tax_rate_bps=r.standard_rate_bps)
    OR (NEW.tax_category IN ('ZERO_RATED','EXEMPT','OUTSIDE_SCOPE') AND NEW.tax_rate_bps=0)
  )
) BEGIN SELECT RAISE(ABORT,'INVOICE_LINE_TAX_RULE_MISMATCH'); END;
--> statement-breakpoint
CREATE TRIGGER require_certificate_rule_version BEFORE INSERT ON certificates
WHEN NEW.rule_set_version IS NULL OR trim(NEW.rule_set_version)='' OR trim(NEW.invoice_hash)='' OR NOT EXISTS (
  SELECT 1 FROM invoices i JOIN tax_rule_sets r ON r.id=i.tax_rule_set_id
  WHERE i.id=NEW.invoice_id AND r.version=NEW.rule_set_version
) BEGIN SELECT RAISE(ABORT,'CERTIFICATE_RULE_BINDING_REQUIRED'); END;
--> statement-breakpoint
INSERT INTO app_schema_revisions (revision,applied_at,source)
VALUES ('phase0-stabilization-2026-08-23',CURRENT_TIMESTAMP,'DRIZZLE_MIGRATION_0015');
