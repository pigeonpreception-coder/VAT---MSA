CREATE TABLE `quotation_revisions` (
	`id` text PRIMARY KEY NOT NULL,
	`quotation_id` text NOT NULL,
	`organisation_id` text NOT NULL,
	`revision_number` integer NOT NULL,
	`action` text NOT NULL,
	`status` text NOT NULL,
	`snapshot_hash` text NOT NULL,
	`snapshot` text NOT NULL,
	`created_by` text NOT NULL,
	`created_at` text NOT NULL,
	FOREIGN KEY (`quotation_id`) REFERENCES `quotations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`created_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_quotation_revisions_number` ON `quotation_revisions` (`quotation_id`,`revision_number`);--> statement-breakpoint
CREATE INDEX `idx_quotation_revisions_organisation` ON `quotation_revisions` (`organisation_id`,`quotation_id`,`created_at`);--> statement-breakpoint
CREATE TRIGGER `prevent_quotation_revision_update`
BEFORE UPDATE ON `quotation_revisions`
BEGIN
	SELECT RAISE(ABORT,'QUOTATION_REVISION_IMMUTABLE');
END;--> statement-breakpoint
CREATE TRIGGER `prevent_quotation_revision_delete`
BEFORE DELETE ON `quotation_revisions`
BEGIN
	SELECT RAISE(ABORT,'QUOTATION_REVISION_IMMUTABLE');
END;
