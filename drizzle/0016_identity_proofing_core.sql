CREATE TABLE `identity_mismatch_cases` (
	`id` text PRIMARY KEY NOT NULL,
	`proofing_case_id` text NOT NULL,
	`mismatch_type` text NOT NULL,
	`conflicting_fields` text NOT NULL,
	`details_hash` text NOT NULL,
	`status` text NOT NULL,
	`resolution_code` text,
	`assigned_to` text,
	`resolved_by` text,
	`opened_at` text NOT NULL,
	`resolved_at` text,
	FOREIGN KEY (`proofing_case_id`) REFERENCES `identity_proofing_cases`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`assigned_to`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`resolved_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	CONSTRAINT "ck_identity_mismatch_status" CHECK("identity_mismatch_cases"."status" IN ('OPEN','RESOLVED','REJECTED'))
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_identity_mismatch_proofing_case` ON `identity_mismatch_cases` (`proofing_case_id`);--> statement-breakpoint
CREATE INDEX `idx_identity_mismatch_status_opened` ON `identity_mismatch_cases` (`status`,`opened_at`);--> statement-breakpoint
CREATE TABLE `identity_proofing_cases` (
	`id` text PRIMARY KEY NOT NULL,
	`subject_type` text NOT NULL,
	`subject_reference` text NOT NULL,
	`registration_application_id` text,
	`provider` text NOT NULL,
	`provider_environment` text NOT NULL,
	`provider_reference` text,
	`status` text NOT NULL,
	`confidence_bps` integer DEFAULT 0 NOT NULL,
	`matched_taxpayer_id` text,
	`evidence_hash` text,
	`reason_code` text NOT NULL,
	`requested_by` text NOT NULL,
	`reviewed_by` text,
	`created_at` text NOT NULL,
	`updated_at` text NOT NULL,
	`reviewed_at` text,
	FOREIGN KEY (`registration_application_id`) REFERENCES `registration_applications`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`matched_taxpayer_id`) REFERENCES `taxpayers`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`requested_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`reviewed_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	CONSTRAINT "ck_identity_proofing_subject_type" CHECK("identity_proofing_cases"."subject_type" IN ('TAXPAYER_REGISTRATION','USER_IDENTITY')),
	CONSTRAINT "ck_identity_proofing_environment" CHECK("identity_proofing_cases"."provider_environment" IN ('CONTRACT_PENDING','SYNTHETIC_TEST','PRODUCTION_EQUIVALENT','PRODUCTION')),
	CONSTRAINT "ck_identity_proofing_status" CHECK("identity_proofing_cases"."status" IN ('PENDING_PROVIDER','CANDIDATE_FOUND','DUPLICATE_CONFIRMED','MISMATCH','MANUAL_REVIEW','SYNTHETIC_MATCHED','AUTHORITY_VERIFIED','REJECTED')),
	CONSTRAINT "ck_identity_proofing_confidence" CHECK("identity_proofing_cases"."confidence_bps" BETWEEN 0 AND 10000),
	CONSTRAINT "ck_identity_proofing_review_separation" CHECK("identity_proofing_cases"."reviewed_by" IS NULL OR "identity_proofing_cases"."reviewed_by" <> "identity_proofing_cases"."requested_by")
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_identity_proofing_registration` ON `identity_proofing_cases` (`registration_application_id`);--> statement-breakpoint
CREATE UNIQUE INDEX `ux_identity_proofing_provider_reference` ON `identity_proofing_cases` (`provider`,`provider_reference`);--> statement-breakpoint
CREATE INDEX `idx_identity_proofing_status_created` ON `identity_proofing_cases` (`status`,`created_at`);--> statement-breakpoint
CREATE INDEX `idx_identity_proofing_subject` ON `identity_proofing_cases` (`subject_type`,`subject_reference`);--> statement-breakpoint
CREATE TABLE `identity_proofing_events` (
	`id` text PRIMARY KEY NOT NULL,
	`proofing_case_id` text NOT NULL,
	`event_type` text NOT NULL,
	`from_status` text,
	`to_status` text NOT NULL,
	`confidence_bps` integer NOT NULL,
	`reason_code` text NOT NULL,
	`evidence_hash` text,
	`actor_id` text NOT NULL,
	`occurred_at` text NOT NULL,
	FOREIGN KEY (`proofing_case_id`) REFERENCES `identity_proofing_cases`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`actor_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	CONSTRAINT "ck_identity_proofing_event_confidence" CHECK("identity_proofing_events"."confidence_bps" BETWEEN 0 AND 10000)
);
--> statement-breakpoint
CREATE INDEX `idx_identity_proofing_events_case_time` ON `identity_proofing_events` (`proofing_case_id`,`occurred_at`);--> statement-breakpoint
CREATE TABLE `identity_reconciliation_candidates` (
	`id` text PRIMARY KEY NOT NULL,
	`proofing_case_id` text NOT NULL,
	`candidate_taxpayer_id` text NOT NULL,
	`outcome` text NOT NULL,
	`confidence_bps` integer NOT NULL,
	`matched_fields` text NOT NULL,
	`conflicting_fields` text NOT NULL,
	`evidence_hash` text NOT NULL,
	`created_at` text NOT NULL,
	FOREIGN KEY (`proofing_case_id`) REFERENCES `identity_proofing_cases`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`candidate_taxpayer_id`) REFERENCES `taxpayers`(`id`) ON UPDATE no action ON DELETE no action,
	CONSTRAINT "ck_identity_reconciliation_outcome" CHECK("identity_reconciliation_candidates"."outcome" IN ('NO_CANDIDATE','CANDIDATE_FOUND','DUPLICATE_CONFIRMED','MISMATCH','MANUAL_REVIEW')),
	CONSTRAINT "ck_identity_reconciliation_confidence" CHECK("identity_reconciliation_candidates"."confidence_bps" BETWEEN 0 AND 10000)
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_identity_reconciliation_candidate` ON `identity_reconciliation_candidates` (`proofing_case_id`,`candidate_taxpayer_id`);--> statement-breakpoint
CREATE INDEX `idx_identity_reconciliation_taxpayer` ON `identity_reconciliation_candidates` (`candidate_taxpayer_id`,`outcome`);
