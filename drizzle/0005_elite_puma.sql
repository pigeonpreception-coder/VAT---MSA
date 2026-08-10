CREATE TABLE `audit_cases` (
	`id` text PRIMARY KEY NOT NULL,
	`case_number` text NOT NULL,
	`organisation_id` text NOT NULL,
	`taxpayer_id` text NOT NULL,
	`case_type` text NOT NULL,
	`title` text NOT NULL,
	`opening_reason` text NOT NULL,
	`risk_tier` text NOT NULL,
	`status` text NOT NULL,
	`assigned_officer_id` text,
	`opened_by` text NOT NULL,
	`opened_at` text NOT NULL,
	`updated_at` text NOT NULL,
	`closed_at` text,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`taxpayer_id`) REFERENCES `taxpayers`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`assigned_officer_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`opened_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_audit_case_number` ON `audit_cases` (`case_number`);--> statement-breakpoint
CREATE INDEX `idx_audit_cases_status_risk` ON `audit_cases` (`status`,`risk_tier`,`updated_at`);--> statement-breakpoint
CREATE TABLE `audit_evidence` (
	`id` text PRIMARY KEY NOT NULL,
	`audit_case_id` text NOT NULL,
	`evidence_type` text NOT NULL,
	`source_resource_type` text NOT NULL,
	`source_resource_id` text NOT NULL,
	`document_id` text,
	`checksum_sha256` text NOT NULL,
	`description` text NOT NULL,
	`status` text NOT NULL,
	`added_by` text NOT NULL,
	`added_at` text NOT NULL,
	FOREIGN KEY (`audit_case_id`) REFERENCES `audit_cases`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`document_id`) REFERENCES `document_metadata`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`added_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_audit_evidence_source` ON `audit_evidence` (`audit_case_id`,`source_resource_type`,`source_resource_id`);--> statement-breakpoint
CREATE TABLE `audit_findings` (
	`id` text PRIMARY KEY NOT NULL,
	`audit_case_id` text NOT NULL,
	`finding_code` text NOT NULL,
	`title` text NOT NULL,
	`description` text NOT NULL,
	`legal_reference` text,
	`amount_cents` integer NOT NULL,
	`currency` text NOT NULL,
	`status` text NOT NULL,
	`author_id` text NOT NULL,
	`created_at` text NOT NULL,
	`resolved_at` text,
	FOREIGN KEY (`audit_case_id`) REFERENCES `audit_cases`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`author_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_audit_finding_code` ON `audit_findings` (`audit_case_id`,`finding_code`);--> statement-breakpoint
CREATE TABLE `communications` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text,
	`taxpayer_id` text,
	`channel` text NOT NULL,
	`direction` text NOT NULL,
	`subject` text NOT NULL,
	`content_summary` text NOT NULL,
	`classification` text NOT NULL,
	`related_resource_type` text,
	`related_resource_id` text,
	`external_reference` text,
	`status` text NOT NULL,
	`actor_id` text NOT NULL,
	`occurred_at` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`taxpayer_id`) REFERENCES `taxpayers`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`actor_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE INDEX `idx_communications_taxpayer_time` ON `communications` (`taxpayer_id`,`occurred_at`);--> statement-breakpoint
CREATE TABLE `consent_grants` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`taxpayer_id` text NOT NULL,
	`granted_by` text NOT NULL,
	`grantee_type` text NOT NULL,
	`grantee_id` text NOT NULL,
	`purpose` text NOT NULL,
	`data_categories` text NOT NULL,
	`legal_basis` text NOT NULL,
	`status` text NOT NULL,
	`valid_from` text NOT NULL,
	`valid_to` text,
	`revoked_at` text,
	`created_at` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`taxpayer_id`) REFERENCES `taxpayers`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`granted_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE INDEX `idx_consent_taxpayer_status` ON `consent_grants` (`taxpayer_id`,`status`);--> statement-breakpoint
CREATE TABLE `delegations` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`taxpayer_id` text NOT NULL,
	`delegator_user_id` text NOT NULL,
	`delegate_user_id` text NOT NULL,
	`scopes` text NOT NULL,
	`status` text NOT NULL,
	`valid_from` text NOT NULL,
	`valid_to` text,
	`approved_by` text,
	`approved_at` text,
	`created_at` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`taxpayer_id`) REFERENCES `taxpayers`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`delegator_user_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`delegate_user_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`approved_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE INDEX `idx_delegations_delegate_status` ON `delegations` (`delegate_user_id`,`status`);--> statement-breakpoint
CREATE TABLE `disputes` (
	`id` text PRIMARY KEY NOT NULL,
	`dispute_number` text NOT NULL,
	`organisation_id` text NOT NULL,
	`taxpayer_id` text NOT NULL,
	`audit_case_id` text,
	`disputed_resource_type` text NOT NULL,
	`disputed_resource_id` text NOT NULL,
	`grounds` text NOT NULL,
	`disputed_amount_cents` integer NOT NULL,
	`currency` text NOT NULL,
	`status` text NOT NULL,
	`filed_by` text NOT NULL,
	`assigned_officer_id` text,
	`filed_at` text NOT NULL,
	`decided_at` text,
	`decision_summary` text,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`taxpayer_id`) REFERENCES `taxpayers`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`audit_case_id`) REFERENCES `audit_cases`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`filed_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`assigned_officer_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_dispute_number` ON `disputes` (`dispute_number`);--> statement-breakpoint
CREATE INDEX `idx_disputes_status_filed` ON `disputes` (`status`,`filed_at`);--> statement-breakpoint
CREATE TABLE `notifications` (
	`id` text PRIMARY KEY NOT NULL,
	`user_id` text,
	`taxpayer_id` text,
	`notification_type` text NOT NULL,
	`title` text NOT NULL,
	`message` text NOT NULL,
	`severity` text NOT NULL,
	`status` text NOT NULL,
	`action_url` text,
	`created_at` text NOT NULL,
	`read_at` text,
	FOREIGN KEY (`user_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`taxpayer_id`) REFERENCES `taxpayers`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE INDEX `idx_notifications_recipient_status` ON `notifications` (`user_id`,`taxpayer_id`,`status`);--> statement-breakpoint
CREATE TABLE `refund_claims` (
	`id` text PRIMARY KEY NOT NULL,
	`claim_number` text NOT NULL,
	`organisation_id` text NOT NULL,
	`taxpayer_id` text NOT NULL,
	`vat_return_version_id` text NOT NULL,
	`amount_cents` integer NOT NULL,
	`currency` text NOT NULL,
	`status` text NOT NULL,
	`evidence_status` text NOT NULL,
	`risk_tier` text NOT NULL,
	`requested_by` text NOT NULL,
	`requested_at` text NOT NULL,
	`approved_by` text,
	`approved_at` text,
	`payment_instruction_id` text,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`taxpayer_id`) REFERENCES `taxpayers`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`vat_return_version_id`) REFERENCES `vat_return_versions`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`requested_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`approved_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_refund_claim_number` ON `refund_claims` (`claim_number`);--> statement-breakpoint
CREATE UNIQUE INDEX `ux_refund_return_version` ON `refund_claims` (`vat_return_version_id`);--> statement-breakpoint
CREATE INDEX `idx_refund_claim_status_risk` ON `refund_claims` (`status`,`risk_tier`,`requested_at`);--> statement-breakpoint
CREATE TABLE `refund_reviews` (
	`id` text PRIMARY KEY NOT NULL,
	`refund_claim_id` text NOT NULL,
	`stage` text NOT NULL,
	`decision` text NOT NULL,
	`findings` text NOT NULL,
	`reviewer_id` text NOT NULL,
	`reviewed_at` text NOT NULL,
	FOREIGN KEY (`refund_claim_id`) REFERENCES `refund_claims`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`reviewer_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_refund_review_stage` ON `refund_reviews` (`refund_claim_id`,`stage`);--> statement-breakpoint
CREATE TABLE `risk_indicators` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`taxpayer_id` text NOT NULL,
	`subject_type` text NOT NULL,
	`subject_id` text NOT NULL,
	`indicator_code` text NOT NULL,
	`score_bps` integer NOT NULL,
	`severity` text NOT NULL,
	`rationale` text NOT NULL,
	`rule_version` text NOT NULL,
	`decision_effect` text NOT NULL,
	`status` text NOT NULL,
	`detected_at` text NOT NULL,
	`reviewed_by` text,
	`reviewed_at` text,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`taxpayer_id`) REFERENCES `taxpayers`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`reviewed_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_risk_indicator_subject` ON `risk_indicators` (`subject_type`,`subject_id`,`indicator_code`,`rule_version`);--> statement-breakpoint
CREATE INDEX `idx_risk_taxpayer_status` ON `risk_indicators` (`taxpayer_id`,`status`,`severity`);--> statement-breakpoint
CREATE TABLE `tax_obligations` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`taxpayer_id` text NOT NULL,
	`obligation_type` text NOT NULL,
	`period_code` text NOT NULL,
	`due_date` text NOT NULL,
	`amount_cents` integer NOT NULL,
	`currency` text NOT NULL,
	`status` text NOT NULL,
	`source_system` text NOT NULL,
	`source_reference` text,
	`created_at` text NOT NULL,
	`updated_at` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`taxpayer_id`) REFERENCES `taxpayers`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_tax_obligation` ON `tax_obligations` (`taxpayer_id`,`obligation_type`,`period_code`);