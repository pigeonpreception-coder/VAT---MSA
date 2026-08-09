CREATE TABLE `access_permissions` (
	`code` text PRIMARY KEY NOT NULL,
	`resource` text NOT NULL,
	`action` text NOT NULL,
	`description` text NOT NULL,
	`classification` text NOT NULL,
	`created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL
);
--> statement-breakpoint
CREATE TABLE `access_roles` (
	`code` text PRIMARY KEY NOT NULL,
	`name` text NOT NULL,
	`audience` text NOT NULL,
	`risk_tier` text NOT NULL,
	`status` text NOT NULL,
	`created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL
);
--> statement-breakpoint
CREATE TABLE `branches` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`code` text NOT NULL,
	`name` text NOT NULL,
	`address` text NOT NULL,
	`status` text NOT NULL,
	`is_head_office` integer DEFAULT false NOT NULL,
	`created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	`updated_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_branches_organisation_code` ON `branches` (`organisation_id`,`code`);--> statement-breakpoint
CREATE INDEX `idx_branches_organisation_status` ON `branches` (`organisation_id`,`status`);--> statement-breakpoint
CREATE TABLE `identity_links` (
	`id` text PRIMARY KEY NOT NULL,
	`user_id` text NOT NULL,
	`provider_id` text NOT NULL,
	`subject` text NOT NULL,
	`email_at_link` text,
	`assurance_level` text NOT NULL,
	`status` text NOT NULL,
	`linked_at` text NOT NULL,
	`last_authenticated_at` text,
	FOREIGN KEY (`user_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`provider_id`) REFERENCES `identity_providers`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_identity_links_provider_subject` ON `identity_links` (`provider_id`,`subject`);--> statement-breakpoint
CREATE INDEX `idx_identity_links_user_status` ON `identity_links` (`user_id`,`status`);--> statement-breakpoint
CREATE TABLE `identity_providers` (
	`id` text PRIMARY KEY NOT NULL,
	`provider_key` text NOT NULL,
	`display_name` text NOT NULL,
	`provider_type` text NOT NULL,
	`authority_level` text NOT NULL,
	`issuer` text,
	`status` text NOT NULL,
	`configuration_status` text NOT NULL,
	`created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	`updated_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_identity_providers_key` ON `identity_providers` (`provider_key`);--> statement-breakpoint
CREATE TABLE `organisation_capabilities` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`capability` text NOT NULL,
	`status` text NOT NULL,
	`effective_from` text NOT NULL,
	`effective_to` text,
	`approved_by` text,
	`created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_organisation_capability` ON `organisation_capabilities` (`organisation_id`,`capability`);--> statement-breakpoint
CREATE INDEX `idx_organisation_capabilities_status` ON `organisation_capabilities` (`status`,`capability`);--> statement-breakpoint
CREATE TABLE `organisation_memberships` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`user_id` text NOT NULL,
	`role_code` text NOT NULL,
	`branch_id` text,
	`status` text NOT NULL,
	`valid_from` text NOT NULL,
	`valid_to` text,
	`assigned_by` text,
	`created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`user_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`role_code`) REFERENCES `access_roles`(`code`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE INDEX `idx_memberships_user_status` ON `organisation_memberships` (`user_id`,`status`);--> statement-breakpoint
CREATE INDEX `idx_memberships_organisation_status` ON `organisation_memberships` (`organisation_id`,`status`);--> statement-breakpoint
CREATE TABLE `organisations` (
	`id` text PRIMARY KEY NOT NULL,
	`taxpayer_id` text NOT NULL,
	`legal_name` text NOT NULL,
	`trading_name` text,
	`status` text NOT NULL,
	`created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	`updated_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	FOREIGN KEY (`taxpayer_id`) REFERENCES `taxpayers`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_organisations_taxpayer` ON `organisations` (`taxpayer_id`);--> statement-breakpoint
CREATE TABLE `registration_applications` (
	`id` text PRIMARY KEY NOT NULL,
	`idempotency_key` text NOT NULL,
	`request_hash` text NOT NULL,
	`vat_number` text NOT NULL,
	`tin` text NOT NULL,
	`company_registration_number` text,
	`legal_name` text NOT NULL,
	`trading_name` text,
	`taxpayer_type` text NOT NULL,
	`return_frequency` text NOT NULL,
	`address` text NOT NULL,
	`email` text NOT NULL,
	`status` text NOT NULL,
	`verification_source` text NOT NULL,
	`submitted_by` text NOT NULL,
	`submitted_at` text NOT NULL,
	`reviewed_at` text,
	`review_reason` text,
	FOREIGN KEY (`submitted_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_registration_submitter_key` ON `registration_applications` (`submitted_by`,`idempotency_key`);--> statement-breakpoint
CREATE INDEX `idx_registration_status_submitted` ON `registration_applications` (`status`,`submitted_at`);--> statement-breakpoint
CREATE INDEX `idx_registration_identifiers` ON `registration_applications` (`vat_number`,`tin`);--> statement-breakpoint
CREATE TABLE `registration_verifications` (
	`id` text PRIMARY KEY NOT NULL,
	`registration_application_id` text NOT NULL,
	`provider` text NOT NULL,
	`request_reference` text NOT NULL,
	`status` text NOT NULL,
	`response_hash` text,
	`verified_taxpayer_id` text,
	`checked_at` text NOT NULL,
	`expires_at` text,
	FOREIGN KEY (`registration_application_id`) REFERENCES `registration_applications`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`verified_taxpayer_id`) REFERENCES `taxpayers`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_registration_verification_reference` ON `registration_verifications` (`provider`,`request_reference`);--> statement-breakpoint
CREATE INDEX `idx_registration_verification_application` ON `registration_verifications` (`registration_application_id`,`status`);--> statement-breakpoint
CREATE TABLE `role_permission_grants` (
	`id` text PRIMARY KEY NOT NULL,
	`role_code` text NOT NULL,
	`permission_code` text NOT NULL,
	`effect` text NOT NULL,
	`conditions` text NOT NULL,
	`created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	FOREIGN KEY (`role_code`) REFERENCES `access_roles`(`code`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`permission_code`) REFERENCES `access_permissions`(`code`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_role_permission_grant` ON `role_permission_grants` (`role_code`,`permission_code`);--> statement-breakpoint
CREATE TABLE `taxpayer_identifiers` (
	`id` text PRIMARY KEY NOT NULL,
	`taxpayer_id` text NOT NULL,
	`identifier_type` text NOT NULL,
	`identifier_value` text NOT NULL,
	`country` text DEFAULT 'NA' NOT NULL,
	`status` text NOT NULL,
	`source` text NOT NULL,
	`verified_at` text,
	`created_at` text DEFAULT CURRENT_TIMESTAMP NOT NULL,
	FOREIGN KEY (`taxpayer_id`) REFERENCES `taxpayers`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_taxpayer_identifier_authority` ON `taxpayer_identifiers` (`identifier_type`,`identifier_value`,`country`);--> statement-breakpoint
CREATE INDEX `idx_taxpayer_identifiers_taxpayer` ON `taxpayer_identifiers` (`taxpayer_id`,`status`);