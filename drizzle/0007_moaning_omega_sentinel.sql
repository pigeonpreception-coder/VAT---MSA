CREATE TABLE `invoice_corrections` (
	`id` text PRIMARY KEY NOT NULL,
	`original_invoice_id` text NOT NULL,
	`correction_invoice_id` text NOT NULL,
	`correction_type` text NOT NULL,
	`reason_code` text,
	`reason` text NOT NULL,
	`status` text NOT NULL,
	`created_by` text NOT NULL,
	`created_at` text NOT NULL,
	FOREIGN KEY (`original_invoice_id`) REFERENCES `invoices`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`correction_invoice_id`) REFERENCES `invoices`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`created_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_invoice_correction_document` ON `invoice_corrections` (`correction_invoice_id`);--> statement-breakpoint
CREATE INDEX `idx_invoice_correction_original` ON `invoice_corrections` (`original_invoice_id`,`created_at`);