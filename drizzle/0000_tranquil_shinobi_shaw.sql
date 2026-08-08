CREATE TABLE `app_users` (
	`id` text PRIMARY KEY NOT NULL,
	`external_user_id` text NOT NULL,
	`email` text NOT NULL,
	`display_name` text NOT NULL,
	`role` text NOT NULL,
	`taxpayer_id` text,
	`status` text NOT NULL,
	`created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	FOREIGN KEY (`taxpayer_id`) REFERENCES `taxpayers`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_app_users_external_id` ON `app_users` (`external_user_id`);--> statement-breakpoint
CREATE UNIQUE INDEX `ux_app_users_email` ON `app_users` (`email`);--> statement-breakpoint
CREATE TABLE `audit_events` (
	`id` text PRIMARY KEY NOT NULL,
	`actor_id` text NOT NULL,
	`actor_role` text NOT NULL,
	`action` text NOT NULL,
	`resource_type` text NOT NULL,
	`resource_id` text NOT NULL,
	`outcome` text NOT NULL,
	`details` text NOT NULL,
	`previous_hash` text,
	`event_hash` text NOT NULL,
	`occurred_at` text NOT NULL
);
--> statement-breakpoint
CREATE INDEX `idx_audit_resource` ON `audit_events` (`resource_type`,`resource_id`,`occurred_at`);--> statement-breakpoint
CREATE INDEX `idx_audit_occurred` ON `audit_events` (`occurred_at`);--> statement-breakpoint
CREATE TABLE `certificates` (
	`id` text PRIMARY KEY NOT NULL,
	`invoice_id` text NOT NULL,
	`verification_token` text NOT NULL,
	`invoice_hash` text NOT NULL,
	`signature` text NOT NULL,
	`signature_profile` text NOT NULL,
	`status` text NOT NULL,
	`issued_at` text NOT NULL,
	FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_certificates_invoice` ON `certificates` (`invoice_id`);--> statement-breakpoint
CREATE UNIQUE INDEX `ux_certificates_token` ON `certificates` (`verification_token`);--> statement-breakpoint
CREATE TABLE `idempotency_records` (
	`id` text PRIMARY KEY NOT NULL,
	`actor_id` text NOT NULL,
	`idempotency_key` text NOT NULL,
	`request_hash` text NOT NULL,
	`response_invoice_id` text NOT NULL,
	`created_at` text NOT NULL,
	FOREIGN KEY (`response_invoice_id`) REFERENCES `invoices`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_idempotency_actor_key` ON `idempotency_records` (`actor_id`,`idempotency_key`);--> statement-breakpoint
CREATE TABLE `invoice_lines` (
	`id` text PRIMARY KEY NOT NULL,
	`invoice_id` text NOT NULL,
	`line_number` integer NOT NULL,
	`description` text NOT NULL,
	`quantity` text NOT NULL,
	`unit_code` text NOT NULL,
	`unit_price_cents` integer NOT NULL,
	`net_amount_cents` integer NOT NULL,
	`tax_rate_bps` integer NOT NULL,
	`tax_category` text NOT NULL,
	`tax_amount_cents` integer NOT NULL,
	FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_invoice_lines_number` ON `invoice_lines` (`invoice_id`,`line_number`);--> statement-breakpoint
CREATE TABLE `invoices` (
	`id` text PRIMARY KEY NOT NULL,
	`invoice_number` text NOT NULL,
	`document_type` text NOT NULL,
	`source_system` text NOT NULL,
	`source_document_id` text NOT NULL,
	`supplier_taxpayer_id` text NOT NULL,
	`supplier_name` text NOT NULL,
	`supplier_vat_number` text NOT NULL,
	`customer_taxpayer_id` text,
	`customer_name` text NOT NULL,
	`customer_vat_number` text,
	`issue_date` text NOT NULL,
	`currency` text NOT NULL,
	`line_net_cents` integer NOT NULL,
	`tax_cents` integer NOT NULL,
	`total_cents` integer NOT NULL,
	`status` text NOT NULL,
	`risk_level` text NOT NULL,
	`payload_hash` text NOT NULL,
	`transaction_id` text NOT NULL,
	`certificate_id` text NOT NULL,
	`verification_token` text NOT NULL,
	`created_at` text NOT NULL,
	`certified_at` text NOT NULL,
	FOREIGN KEY (`supplier_taxpayer_id`) REFERENCES `taxpayers`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`customer_taxpayer_id`) REFERENCES `taxpayers`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_invoices_source_document` ON `invoices` (`supplier_taxpayer_id`,`source_system`,`source_document_id`);--> statement-breakpoint
CREATE UNIQUE INDEX `ux_invoices_certificate` ON `invoices` (`certificate_id`);--> statement-breakpoint
CREATE UNIQUE INDEX `ux_invoices_verification_token` ON `invoices` (`verification_token`);--> statement-breakpoint
CREATE INDEX `idx_invoices_status_issue_date` ON `invoices` (`status`,`issue_date`);--> statement-breakpoint
CREATE INDEX `idx_invoices_supplier_issue_date` ON `invoices` (`supplier_taxpayer_id`,`issue_date`);--> statement-breakpoint
CREATE INDEX `idx_invoices_customer_issue_date` ON `invoices` (`customer_taxpayer_id`,`issue_date`);--> statement-breakpoint
CREATE TABLE `ledger_entries` (
	`id` text PRIMARY KEY NOT NULL,
	`transaction_id` text NOT NULL,
	`invoice_id` text NOT NULL,
	`taxpayer_id` text NOT NULL,
	`entry_type` text NOT NULL,
	`direction` text NOT NULL,
	`amount_cents` integer NOT NULL,
	`period` text NOT NULL,
	`created_at` text NOT NULL,
	FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`taxpayer_id`) REFERENCES `taxpayers`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE INDEX `idx_ledger_taxpayer_period` ON `ledger_entries` (`taxpayer_id`,`period`);--> statement-breakpoint
CREATE INDEX `idx_ledger_transaction` ON `ledger_entries` (`transaction_id`);--> statement-breakpoint
CREATE TABLE `reconciliation_exceptions` (
	`id` text PRIMARY KEY NOT NULL,
	`invoice_id` text NOT NULL,
	`taxpayer_id` text,
	`exception_type` text NOT NULL,
	`severity` text NOT NULL,
	`status` text NOT NULL,
	`summary` text NOT NULL,
	`created_at` text NOT NULL,
	`resolved_at` text,
	FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`taxpayer_id`) REFERENCES `taxpayers`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE INDEX `idx_exceptions_status_created` ON `reconciliation_exceptions` (`status`,`created_at`);--> statement-breakpoint
CREATE TABLE `seed_state` (
	`key` text PRIMARY KEY NOT NULL,
	`applied_at` text NOT NULL
);
--> statement-breakpoint
CREATE TABLE `taxpayers` (
	`id` text PRIMARY KEY NOT NULL,
	`vat_number` text NOT NULL,
	`tin` text NOT NULL,
	`legal_name` text NOT NULL,
	`trading_name` text,
	`taxpayer_type` text NOT NULL,
	`vat_status` text NOT NULL,
	`return_frequency` text NOT NULL,
	`address` text NOT NULL,
	`email` text NOT NULL,
	`created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_taxpayers_vat_number` ON `taxpayers` (`vat_number`);--> statement-breakpoint
CREATE TABLE `vat_returns` (
	`id` text PRIMARY KEY NOT NULL,
	`taxpayer_id` text NOT NULL,
	`period` text NOT NULL,
	`output_tax_cents` integer NOT NULL,
	`input_tax_cents` integer NOT NULL,
	`net_payable_cents` integer NOT NULL,
	`status` text NOT NULL,
	`last_calculated_at` text NOT NULL,
	FOREIGN KEY (`taxpayer_id`) REFERENCES `taxpayers`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_returns_taxpayer_period` ON `vat_returns` (`taxpayer_id`,`period`);