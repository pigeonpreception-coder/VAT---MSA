CREATE TABLE `approval_tasks` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`taxpayer_id` text NOT NULL,
	`domain` text NOT NULL,
	`resource_type` text NOT NULL,
	`resource_id` text NOT NULL,
	`requested_action` text NOT NULL,
	`risk_tier` text NOT NULL,
	`status` text NOT NULL,
	`requested_by` text NOT NULL,
	`assigned_role` text NOT NULL,
	`decided_by` text,
	`requested_at` text NOT NULL,
	`decided_at` text,
	`decision_comment` text,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`taxpayer_id`) REFERENCES `taxpayers`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`requested_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`decided_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE INDEX `idx_approval_queue` ON `approval_tasks` (`status`,`assigned_role`,`requested_at`);--> statement-breakpoint
CREATE TABLE `reconciliation_matches` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`taxpayer_id` text NOT NULL,
	`vat_period_id` text,
	`invoice_id` text NOT NULL,
	`ledger_entry_id` text,
	`match_type` text NOT NULL,
	`confidence_bps` integer NOT NULL,
	`status` text NOT NULL,
	`evidence` text NOT NULL,
	`reconciled_by` text,
	`reconciled_at` text,
	`created_at` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`taxpayer_id`) REFERENCES `taxpayers`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`vat_period_id`) REFERENCES `vat_periods`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`ledger_entry_id`) REFERENCES `ledger_entries`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`reconciled_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_reconciliation_invoice_taxpayer` ON `reconciliation_matches` (`invoice_id`,`taxpayer_id`);--> statement-breakpoint
CREATE INDEX `idx_reconciliation_period_status` ON `reconciliation_matches` (`vat_period_id`,`status`);--> statement-breakpoint
CREATE TABLE `tax_box_mappings` (
	`id` text PRIMARY KEY NOT NULL,
	`tax_rule_set_id` text NOT NULL,
	`box_code` text NOT NULL,
	`label` text NOT NULL,
	`source_entry_type` text NOT NULL,
	`direction` text NOT NULL,
	`formula` text NOT NULL,
	`status` text NOT NULL,
	FOREIGN KEY (`tax_rule_set_id`) REFERENCES `tax_rule_sets`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_tax_box_mapping` ON `tax_box_mappings` (`tax_rule_set_id`,`box_code`);--> statement-breakpoint
CREATE TABLE `tax_rule_sets` (
	`id` text PRIMARY KEY NOT NULL,
	`jurisdiction` text NOT NULL,
	`version` text NOT NULL,
	`effective_from` text NOT NULL,
	`effective_to` text,
	`standard_rate_bps` integer NOT NULL,
	`legal_authority_reference` text,
	`status` text NOT NULL,
	`approved_by` text,
	`approved_at` text,
	`created_at` text NOT NULL,
	FOREIGN KEY (`approved_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_tax_rule_version` ON `tax_rule_sets` (`jurisdiction`,`version`);--> statement-breakpoint
CREATE TABLE `vat_adjustments` (
	`id` text PRIMARY KEY NOT NULL,
	`vat_period_id` text NOT NULL,
	`organisation_id` text NOT NULL,
	`taxpayer_id` text NOT NULL,
	`adjustment_type` text NOT NULL,
	`direction` text NOT NULL,
	`amount_cents` integer NOT NULL,
	`reason_code` text NOT NULL,
	`explanation` text NOT NULL,
	`evidence_document_id` text,
	`status` text NOT NULL,
	`created_by` text NOT NULL,
	`approved_by` text,
	`created_at` text NOT NULL,
	`approved_at` text,
	FOREIGN KEY (`vat_period_id`) REFERENCES `vat_periods`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`taxpayer_id`) REFERENCES `taxpayers`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`evidence_document_id`) REFERENCES `document_metadata`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`created_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`approved_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE INDEX `idx_vat_adjustments_period_status` ON `vat_adjustments` (`vat_period_id`,`status`);--> statement-breakpoint
CREATE TABLE `vat_periods` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`taxpayer_id` text NOT NULL,
	`period_code` text NOT NULL,
	`period_start` text NOT NULL,
	`period_end` text NOT NULL,
	`due_date` text NOT NULL,
	`status` text NOT NULL,
	`lock_version` integer DEFAULT 0 NOT NULL,
	`close_requested_by` text,
	`close_requested_at` text,
	`closed_by` text,
	`closed_at` text,
	`created_at` text NOT NULL,
	`updated_at` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`taxpayer_id`) REFERENCES `taxpayers`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`close_requested_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`closed_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_vat_period_code` ON `vat_periods` (`taxpayer_id`,`period_code`);--> statement-breakpoint
CREATE INDEX `idx_vat_period_status_due` ON `vat_periods` (`status`,`due_date`);--> statement-breakpoint
CREATE TABLE `vat_return_boxes` (
	`id` text PRIMARY KEY NOT NULL,
	`vat_return_version_id` text NOT NULL,
	`box_code` text NOT NULL,
	`label` text NOT NULL,
	`amount_cents` integer NOT NULL,
	`source_count` integer NOT NULL,
	`calculation_trace` text NOT NULL,
	FOREIGN KEY (`vat_return_version_id`) REFERENCES `vat_return_versions`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_vat_return_box` ON `vat_return_boxes` (`vat_return_version_id`,`box_code`);--> statement-breakpoint
CREATE TABLE `vat_return_submissions` (
	`id` text PRIMARY KEY NOT NULL,
	`vat_return_version_id` text NOT NULL,
	`provider` text NOT NULL,
	`request_reference` text NOT NULL,
	`status` text NOT NULL,
	`request_hash` text NOT NULL,
	`provider_reference` text,
	`response_hash` text,
	`attempt_count` integer DEFAULT 0 NOT NULL,
	`requested_by` text NOT NULL,
	`requested_at` text NOT NULL,
	`submitted_at` text,
	`acknowledged_at` text,
	`last_error` text,
	FOREIGN KEY (`vat_return_version_id`) REFERENCES `vat_return_versions`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`requested_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_vat_return_submission_reference` ON `vat_return_submissions` (`provider`,`request_reference`);--> statement-breakpoint
CREATE INDEX `idx_vat_return_submission_status` ON `vat_return_submissions` (`status`,`requested_at`);--> statement-breakpoint
CREATE TABLE `vat_return_versions` (
	`id` text PRIMARY KEY NOT NULL,
	`vat_period_id` text NOT NULL,
	`organisation_id` text NOT NULL,
	`taxpayer_id` text NOT NULL,
	`version_number` integer NOT NULL,
	`parent_version_id` text,
	`tax_rule_set_id` text NOT NULL,
	`output_tax_cents` integer NOT NULL,
	`input_tax_cents` integer NOT NULL,
	`adjustment_cents` integer NOT NULL,
	`net_payable_cents` integer NOT NULL,
	`status` text NOT NULL,
	`ledger_snapshot_hash` text NOT NULL,
	`generated_by` text NOT NULL,
	`generated_at` text NOT NULL,
	`approved_by` text,
	`approved_at` text,
	`superseded_at` text,
	FOREIGN KEY (`vat_period_id`) REFERENCES `vat_periods`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`taxpayer_id`) REFERENCES `taxpayers`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`tax_rule_set_id`) REFERENCES `tax_rule_sets`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`generated_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`approved_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_vat_return_version` ON `vat_return_versions` (`vat_period_id`,`version_number`);--> statement-breakpoint
CREATE INDEX `idx_vat_return_status_generated` ON `vat_return_versions` (`status`,`generated_at`);