CREATE TABLE `api_clients` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`name` text NOT NULL,
	`client_key` text NOT NULL,
	`scopes` text NOT NULL,
	`credential_reference` text NOT NULL,
	`status` text NOT NULL,
	`rate_limit_profile` text NOT NULL,
	`last_rotated_at` text,
	`expires_at` text,
	`created_by` text NOT NULL,
	`created_at` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`created_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_api_client_key` ON `api_clients` (`client_key`);--> statement-breakpoint
CREATE TABLE `bank_imports` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`integration_connection_id` text,
	`document_id` text,
	`bank_name` text NOT NULL,
	`account_reference_masked` text NOT NULL,
	`statement_from` text NOT NULL,
	`statement_to` text NOT NULL,
	`currency` text NOT NULL,
	`transaction_count` integer DEFAULT 0 NOT NULL,
	`status` text NOT NULL,
	`requested_by` text NOT NULL,
	`created_at` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`integration_connection_id`) REFERENCES `integration_connections`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`document_id`) REFERENCES `document_metadata`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`requested_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE INDEX `idx_bank_import_status_created` ON `bank_imports` (`organisation_id`,`status`,`created_at`);--> statement-breakpoint
CREATE TABLE `integration_connections` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text,
	`provider_key` text NOT NULL,
	`category` text NOT NULL,
	`display_name` text NOT NULL,
	`capabilities` text NOT NULL,
	`endpoint_reference` text,
	`credential_reference` text,
	`configuration_status` text NOT NULL,
	`operational_status` text NOT NULL,
	`data_classification` text NOT NULL,
	`last_health_check_at` text,
	`last_health_outcome` text,
	`created_at` text NOT NULL,
	`updated_at` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_integration_provider_org` ON `integration_connections` (`provider_key`,`organisation_id`);--> statement-breakpoint
CREATE TABLE `offline_conflicts` (
	`id` text PRIMARY KEY NOT NULL,
	`offline_sync_batch_id` text NOT NULL,
	`conflict_type` text NOT NULL,
	`source_document_id` text NOT NULL,
	`existing_resource_id` text,
	`status` text NOT NULL,
	`resolution` text,
	`resolved_by` text,
	`created_at` text NOT NULL,
	`resolved_at` text,
	FOREIGN KEY (`offline_sync_batch_id`) REFERENCES `offline_sync_batches`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`resolved_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE INDEX `idx_offline_conflicts_status` ON `offline_conflicts` (`status`,`created_at`);--> statement-breakpoint
CREATE TABLE `offline_devices` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`branch_id` text,
	`device_code` text NOT NULL,
	`display_name` text NOT NULL,
	`public_key_reference` text,
	`certificate_fingerprint` text,
	`status` text NOT NULL,
	`enrolment_status` text NOT NULL,
	`last_accepted_sequence` integer DEFAULT 0 NOT NULL,
	`last_batch_hash` text,
	`last_seen_at` text,
	`created_at` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_offline_device_code` ON `offline_devices` (`organisation_id`,`device_code`);--> statement-breakpoint
CREATE TABLE `offline_number_ranges` (
	`id` text PRIMARY KEY NOT NULL,
	`offline_device_id` text NOT NULL,
	`document_type` text NOT NULL,
	`prefix` text NOT NULL,
	`range_start` integer NOT NULL,
	`range_end` integer NOT NULL,
	`next_number` integer NOT NULL,
	`status` text NOT NULL,
	`valid_from` text NOT NULL,
	`valid_to` text NOT NULL,
	FOREIGN KEY (`offline_device_id`) REFERENCES `offline_devices`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_offline_number_range` ON `offline_number_ranges` (`offline_device_id`,`document_type`,`prefix`);--> statement-breakpoint
CREATE TABLE `offline_sync_batches` (
	`id` text PRIMARY KEY NOT NULL,
	`offline_device_id` text NOT NULL,
	`client_batch_id` text NOT NULL,
	`sequence_from` integer NOT NULL,
	`sequence_to` integer NOT NULL,
	`previous_batch_hash` text,
	`batch_hash` text NOT NULL,
	`signature` text NOT NULL,
	`document_count` integer NOT NULL,
	`status` text NOT NULL,
	`received_at` text NOT NULL,
	`processed_at` text,
	`rejection_reason` text,
	FOREIGN KEY (`offline_device_id`) REFERENCES `offline_devices`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_offline_client_batch` ON `offline_sync_batches` (`offline_device_id`,`client_batch_id`);--> statement-breakpoint
CREATE UNIQUE INDEX `ux_offline_batch_sequence` ON `offline_sync_batches` (`offline_device_id`,`sequence_from`,`sequence_to`);--> statement-breakpoint
CREATE TABLE `payment_instructions` (
	`id` text PRIMARY KEY NOT NULL,
	`refund_claim_id` text,
	`taxpayer_id` text NOT NULL,
	`amount_cents` integer NOT NULL,
	`currency` text NOT NULL,
	`beneficiary_reference_masked` text NOT NULL,
	`provider` text NOT NULL,
	`status` text NOT NULL,
	`provider_reference` text,
	`idempotency_key` text NOT NULL,
	`approved_by` text NOT NULL,
	`approved_at` text NOT NULL,
	`submitted_at` text,
	`settled_at` text,
	`last_error` text,
	FOREIGN KEY (`refund_claim_id`) REFERENCES `refund_claims`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`taxpayer_id`) REFERENCES `taxpayers`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`approved_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_payment_instruction_key` ON `payment_instructions` (`provider`,`idempotency_key`);--> statement-breakpoint
CREATE TABLE `report_definitions` (
	`id` text PRIMARY KEY NOT NULL,
	`code` text NOT NULL,
	`name` text NOT NULL,
	`audience` text NOT NULL,
	`description` text NOT NULL,
	`classification` text NOT NULL,
	`query_version` text NOT NULL,
	`status` text NOT NULL,
	`created_at` text NOT NULL
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_report_definition_code` ON `report_definitions` (`code`);--> statement-breakpoint
CREATE TABLE `report_runs` (
	`id` text PRIMARY KEY NOT NULL,
	`report_definition_id` text NOT NULL,
	`organisation_id` text,
	`taxpayer_id` text,
	`parameters` text NOT NULL,
	`status` text NOT NULL,
	`row_count` integer,
	`result_summary` text,
	`output_document_id` text,
	`requested_by` text NOT NULL,
	`requested_at` text NOT NULL,
	`completed_at` text,
	`expires_at` text,
	`error_code` text,
	FOREIGN KEY (`report_definition_id`) REFERENCES `report_definitions`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`taxpayer_id`) REFERENCES `taxpayers`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`output_document_id`) REFERENCES `document_metadata`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`requested_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE INDEX `idx_report_runs_status_requested` ON `report_runs` (`status`,`requested_at`);--> statement-breakpoint
CREATE TABLE `service_components` (
	`id` text PRIMARY KEY NOT NULL,
	`component_key` text NOT NULL,
	`display_name` text NOT NULL,
	`component_type` text NOT NULL,
	`criticality` text NOT NULL,
	`configuration_status` text NOT NULL,
	`operational_status` text NOT NULL,
	`dependency_summary` text NOT NULL,
	`last_checked_at` text,
	`status_detail` text NOT NULL
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_service_component_key` ON `service_components` (`component_key`);--> statement-breakpoint
CREATE TABLE `sync_jobs` (
	`id` text PRIMARY KEY NOT NULL,
	`integration_connection_id` text NOT NULL,
	`organisation_id` text,
	`job_type` text NOT NULL,
	`direction` text NOT NULL,
	`status` text NOT NULL,
	`cursor` text,
	`records_read` integer DEFAULT 0 NOT NULL,
	`records_written` integer DEFAULT 0 NOT NULL,
	`error_count` integer DEFAULT 0 NOT NULL,
	`requested_by` text NOT NULL,
	`requested_at` text NOT NULL,
	`started_at` text,
	`completed_at` text,
	`last_error` text,
	FOREIGN KEY (`integration_connection_id`) REFERENCES `integration_connections`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`requested_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE INDEX `idx_sync_jobs_status_requested` ON `sync_jobs` (`status`,`requested_at`);--> statement-breakpoint
CREATE TABLE `webhook_deliveries` (
	`id` text PRIMARY KEY NOT NULL,
	`webhook_subscription_id` text NOT NULL,
	`outbox_event_id` text NOT NULL,
	`status` text NOT NULL,
	`attempt_count` integer DEFAULT 0 NOT NULL,
	`response_status` integer,
	`next_attempt_at` text,
	`delivered_at` text,
	`last_error` text,
	`created_at` text NOT NULL,
	FOREIGN KEY (`webhook_subscription_id`) REFERENCES `webhook_subscriptions`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`outbox_event_id`) REFERENCES `outbox_events`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_webhook_delivery_event` ON `webhook_deliveries` (`webhook_subscription_id`,`outbox_event_id`);--> statement-breakpoint
CREATE TABLE `webhook_subscriptions` (
	`id` text PRIMARY KEY NOT NULL,
	`api_client_id` text NOT NULL,
	`event_types` text NOT NULL,
	`endpoint_url` text NOT NULL,
	`signing_key_reference` text NOT NULL,
	`status` text NOT NULL,
	`created_at` text NOT NULL,
	FOREIGN KEY (`api_client_id`) REFERENCES `api_clients`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_webhook_endpoint_client` ON `webhook_subscriptions` (`api_client_id`,`endpoint_url`);