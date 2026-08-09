CREATE TABLE `outbox_events` (
	`id` text PRIMARY KEY NOT NULL,
	`aggregate_type` text NOT NULL,
	`aggregate_id` text NOT NULL,
	`event_type` text NOT NULL,
	`event_version` integer NOT NULL,
	`partition_key` text NOT NULL,
	`payload` text NOT NULL,
	`status` text NOT NULL,
	`publish_attempts` integer DEFAULT 0 NOT NULL,
	`occurred_at` text NOT NULL,
	`available_at` text NOT NULL,
	`published_at` text,
	`last_error` text
);
--> statement-breakpoint
CREATE INDEX `idx_outbox_status_available` ON `outbox_events` (`status`,`available_at`);--> statement-breakpoint
CREATE INDEX `idx_outbox_aggregate` ON `outbox_events` (`aggregate_type`,`aggregate_id`);--> statement-breakpoint
CREATE TABLE `rate_limit_windows` (
	`bucket_key` text NOT NULL,
	`window_start` integer NOT NULL,
	`request_count` integer NOT NULL,
	`expires_at` integer NOT NULL
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_rate_limit_bucket_window` ON `rate_limit_windows` (`bucket_key`,`window_start`);--> statement-breakpoint
CREATE INDEX `idx_rate_limit_expiry` ON `rate_limit_windows` (`expires_at`);--> statement-breakpoint
CREATE TABLE `security_events` (
	`id` text PRIMARY KEY NOT NULL,
	`event_type` text NOT NULL,
	`severity` text NOT NULL,
	`actor_id` text,
	`source_token` text NOT NULL,
	`correlation_id` text NOT NULL,
	`action` text NOT NULL,
	`outcome` text NOT NULL,
	`details` text NOT NULL,
	`occurred_at` text NOT NULL
);
--> statement-breakpoint
CREATE INDEX `idx_security_events_severity_time` ON `security_events` (`severity`,`occurred_at`);--> statement-breakpoint
CREATE INDEX `idx_security_events_actor_time` ON `security_events` (`actor_id`,`occurred_at`);--> statement-breakpoint
CREATE TABLE `security_incidents` (
	`id` text PRIMARY KEY NOT NULL,
	`title` text NOT NULL,
	`severity` text NOT NULL,
	`status` text NOT NULL,
	`source_event_id` text,
	`automated_action` text,
	`owner` text,
	`opened_at` text NOT NULL,
	`updated_at` text NOT NULL,
	FOREIGN KEY (`source_event_id`) REFERENCES `security_events`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE INDEX `idx_security_incidents_status_severity` ON `security_incidents` (`status`,`severity`);