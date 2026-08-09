CREATE TABLE `business_parties` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`display_name` text NOT NULL,
	`legal_name` text,
	`vat_number` text,
	`tin` text,
	`email` text,
	`phone` text,
	`address` text,
	`source_system` text NOT NULL,
	`source_party_id` text,
	`status` text NOT NULL,
	`created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	`updated_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_business_parties_source` ON `business_parties` (`organisation_id`,`source_system`,`source_party_id`);--> statement-breakpoint
CREATE INDEX `idx_business_parties_name` ON `business_parties` (`organisation_id`,`display_name`);--> statement-breakpoint
CREATE TABLE `chart_of_accounts` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`code` text NOT NULL,
	`name` text NOT NULL,
	`account_type` text NOT NULL,
	`currency` text NOT NULL,
	`control_type` text,
	`status` text NOT NULL,
	`created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_accounts_organisation_code` ON `chart_of_accounts` (`organisation_id`,`code`);--> statement-breakpoint
CREATE TABLE `command_idempotency` (
	`id` text PRIMARY KEY NOT NULL,
	`actor_id` text NOT NULL,
	`command_type` text NOT NULL,
	`idempotency_key` text NOT NULL,
	`request_hash` text NOT NULL,
	`resource_type` text NOT NULL,
	`resource_id` text NOT NULL,
	`created_at` text NOT NULL,
	FOREIGN KEY (`actor_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_command_idempotency` ON `command_idempotency` (`actor_id`,`command_type`,`idempotency_key`);--> statement-breakpoint
CREATE TABLE `document_metadata` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`owner_domain` text NOT NULL,
	`owner_resource_id` text NOT NULL,
	`object_key` text NOT NULL,
	`file_name` text NOT NULL,
	`content_type` text NOT NULL,
	`size_bytes` integer NOT NULL,
	`checksum_sha256` text NOT NULL,
	`classification` text NOT NULL,
	`scan_status` text NOT NULL,
	`status` text NOT NULL,
	`uploaded_by` text NOT NULL,
	`uploaded_at` text NOT NULL,
	`retained_until` text,
	`legal_hold` integer DEFAULT false NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`uploaded_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_document_object_key` ON `document_metadata` (`object_key`);--> statement-breakpoint
CREATE INDEX `idx_documents_owner` ON `document_metadata` (`organisation_id`,`owner_domain`,`owner_resource_id`);--> statement-breakpoint
CREATE TABLE `expense_categories` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`code` text NOT NULL,
	`name` text NOT NULL,
	`default_tax_category` text NOT NULL,
	`requires_receipt` integer DEFAULT true NOT NULL,
	`status` text NOT NULL,
	`created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_expense_categories_organisation_code` ON `expense_categories` (`organisation_id`,`code`);--> statement-breakpoint
CREATE TABLE `expenses` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`branch_id` text,
	`category_id` text NOT NULL,
	`supplier_party_id` text,
	`project_id` text,
	`expense_number` text NOT NULL,
	`expense_date` text NOT NULL,
	`description` text NOT NULL,
	`currency` text NOT NULL,
	`net_cents` integer NOT NULL,
	`tax_cents` integer NOT NULL,
	`total_cents` integer NOT NULL,
	`status` text NOT NULL,
	`receipt_document_id` text,
	`created_by` text NOT NULL,
	`approved_by` text,
	`created_at` text NOT NULL,
	`approved_at` text,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`category_id`) REFERENCES `expense_categories`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`supplier_party_id`) REFERENCES `business_parties`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`created_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`approved_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_expenses_number` ON `expenses` (`organisation_id`,`expense_number`);--> statement-breakpoint
CREATE INDEX `idx_expenses_status_date` ON `expenses` (`organisation_id`,`status`,`expense_date`);--> statement-breakpoint
CREATE TABLE `import_records` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`declaration_number` text NOT NULL,
	`customs_office` text,
	`supplier_name` text NOT NULL,
	`country_of_origin` text NOT NULL,
	`currency` text NOT NULL,
	`customs_value_cents` integer NOT NULL,
	`import_vat_cents` integer NOT NULL,
	`declaration_date` text NOT NULL,
	`evidence_document_id` text,
	`status` text NOT NULL,
	`created_by` text NOT NULL,
	`created_at` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`created_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_import_declaration` ON `import_records` (`organisation_id`,`declaration_number`);--> statement-breakpoint
CREATE TABLE `inventory_balances` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`warehouse_id` text NOT NULL,
	`product_id` text NOT NULL,
	`quantity_micros` integer DEFAULT 0 NOT NULL,
	`average_cost_cents` integer DEFAULT 0 NOT NULL,
	`version` integer DEFAULT 0 NOT NULL,
	`updated_at` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON UPDATE no action ON DELETE no action,
	CONSTRAINT "ck_inventory_quantity_nonnegative" CHECK("inventory_balances"."quantity_micros" >= 0)
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_inventory_balance` ON `inventory_balances` (`warehouse_id`,`product_id`);--> statement-breakpoint
CREATE TABLE `journal_entries` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`journal_number` text NOT NULL,
	`journal_date` text NOT NULL,
	`reference` text,
	`description` text NOT NULL,
	`currency` text NOT NULL,
	`status` text NOT NULL,
	`source_type` text NOT NULL,
	`source_id` text,
	`created_by` text NOT NULL,
	`posted_by` text,
	`created_at` text NOT NULL,
	`posted_at` text,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`created_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`posted_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_journal_number` ON `journal_entries` (`organisation_id`,`journal_number`);--> statement-breakpoint
CREATE INDEX `idx_journals_status_date` ON `journal_entries` (`organisation_id`,`status`,`journal_date`);--> statement-breakpoint
CREATE TABLE `journal_lines` (
	`id` text PRIMARY KEY NOT NULL,
	`journal_entry_id` text NOT NULL,
	`line_number` integer NOT NULL,
	`account_id` text NOT NULL,
	`branch_id` text,
	`project_id` text,
	`description` text NOT NULL,
	`debit_cents` integer DEFAULT 0 NOT NULL,
	`credit_cents` integer DEFAULT 0 NOT NULL,
	`tax_code` text,
	FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`account_id`) REFERENCES `chart_of_accounts`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_journal_lines_number` ON `journal_lines` (`journal_entry_id`,`line_number`);--> statement-breakpoint
CREATE INDEX `idx_journal_lines_account` ON `journal_lines` (`account_id`,`journal_entry_id`);--> statement-breakpoint
CREATE TABLE `party_relationships` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`party_id` text NOT NULL,
	`relationship` text NOT NULL,
	`status` text NOT NULL,
	`effective_from` text NOT NULL,
	`effective_to` text,
	`created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`party_id`) REFERENCES `business_parties`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_party_relationship` ON `party_relationships` (`organisation_id`,`party_id`,`relationship`);--> statement-breakpoint
CREATE TABLE `products` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`sku` text NOT NULL,
	`name` text NOT NULL,
	`description` text,
	`unit_code` text NOT NULL,
	`tax_category` text NOT NULL,
	`tax_rate_bps` integer NOT NULL,
	`sales_price_cents` integer NOT NULL,
	`cost_price_cents` integer NOT NULL,
	`status` text NOT NULL,
	`created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	`updated_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_products_organisation_sku` ON `products` (`organisation_id`,`sku`);--> statement-breakpoint
CREATE TABLE `project_budgets` (
	`id` text PRIMARY KEY NOT NULL,
	`project_id` text NOT NULL,
	`category` text NOT NULL,
	`amount_cents` integer NOT NULL,
	`approved_amount_cents` integer NOT NULL,
	`status` text NOT NULL,
	`approved_by` text,
	`approved_at` text,
	`created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`approved_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_project_budget_category` ON `project_budgets` (`project_id`,`category`);--> statement-breakpoint
CREATE TABLE `project_costs` (
	`id` text PRIMARY KEY NOT NULL,
	`project_id` text NOT NULL,
	`cost_type` text NOT NULL,
	`source_id` text NOT NULL,
	`amount_cents` integer NOT NULL,
	`currency` text NOT NULL,
	`occurred_at` text NOT NULL,
	`created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_project_cost_source` ON `project_costs` (`project_id`,`cost_type`,`source_id`);--> statement-breakpoint
CREATE TABLE `projects` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`code` text NOT NULL,
	`name` text NOT NULL,
	`customer_party_id` text,
	`manager_user_id` text,
	`currency` text NOT NULL,
	`start_date` text NOT NULL,
	`end_date` text,
	`status` text NOT NULL,
	`created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	`updated_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`customer_party_id`) REFERENCES `business_parties`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`manager_user_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_projects_organisation_code` ON `projects` (`organisation_id`,`code`);--> statement-breakpoint
CREATE TABLE `quotation_lines` (
	`id` text PRIMARY KEY NOT NULL,
	`quotation_id` text NOT NULL,
	`line_number` integer NOT NULL,
	`product_id` text,
	`description` text NOT NULL,
	`quantity_micros` integer NOT NULL,
	`unit_code` text NOT NULL,
	`unit_price_cents` integer NOT NULL,
	`net_amount_cents` integer NOT NULL,
	`tax_category` text NOT NULL,
	`tax_rate_bps` integer NOT NULL,
	`tax_amount_cents` integer NOT NULL,
	FOREIGN KEY (`quotation_id`) REFERENCES `quotations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_quotation_lines_number` ON `quotation_lines` (`quotation_id`,`line_number`);--> statement-breakpoint
CREATE TABLE `quotations` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`branch_id` text,
	`customer_party_id` text NOT NULL,
	`quotation_number` text NOT NULL,
	`currency` text NOT NULL,
	`issue_date` text NOT NULL,
	`valid_until` text NOT NULL,
	`status` text NOT NULL,
	`subtotal_cents` integer NOT NULL,
	`tax_cents` integer NOT NULL,
	`total_cents` integer NOT NULL,
	`notes` text,
	`created_by` text NOT NULL,
	`approved_by` text,
	`accepted_at` text,
	`converted_invoice_id` text,
	`created_at` text NOT NULL,
	`updated_at` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`customer_party_id`) REFERENCES `business_parties`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`created_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`approved_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_quotations_number` ON `quotations` (`organisation_id`,`quotation_number`);--> statement-breakpoint
CREATE INDEX `idx_quotations_status_date` ON `quotations` (`organisation_id`,`status`,`issue_date`);--> statement-breakpoint
CREATE TABLE `stock_movements` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`warehouse_id` text NOT NULL,
	`product_id` text NOT NULL,
	`movement_type` text NOT NULL,
	`quantity_micros` integer NOT NULL,
	`unit_cost_cents` integer NOT NULL,
	`reference_type` text NOT NULL,
	`reference_id` text NOT NULL,
	`reason` text NOT NULL,
	`occurred_at` text NOT NULL,
	`actor_id` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`actor_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_stock_movement_reference` ON `stock_movements` (`organisation_id`,`reference_type`,`reference_id`);--> statement-breakpoint
CREATE INDEX `idx_stock_movement_product_time` ON `stock_movements` (`warehouse_id`,`product_id`,`occurred_at`);--> statement-breakpoint
CREATE TABLE `warehouses` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`branch_id` text,
	`code` text NOT NULL,
	`name` text NOT NULL,
	`address` text NOT NULL,
	`status` text NOT NULL,
	`created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_warehouses_organisation_code` ON `warehouses` (`organisation_id`,`code`);