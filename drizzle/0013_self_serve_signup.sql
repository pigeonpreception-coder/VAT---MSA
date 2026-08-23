CREATE TABLE `self_serve_signup_applications` (
	`id` text PRIMARY KEY NOT NULL,
	`public_reference` text NOT NULL,
	`idempotency_key` text NOT NULL,
	`request_hash` text NOT NULL,
	`applicant_name` text NOT NULL,
	`applicant_role` text NOT NULL,
	`contact_email` text NOT NULL,
	`identity_provider` text,
	`identity_subject_hash` text,
	`country_code` text NOT NULL,
	`requested_plan_id` text NOT NULL,
	`vat_number` text NOT NULL,
	`tin` text NOT NULL,
	`company_registration_number` text,
	`legal_name` text NOT NULL,
	`trading_name` text,
	`taxpayer_type` text NOT NULL,
	`return_frequency` text NOT NULL,
	`address` text NOT NULL,
	`terms_version` text NOT NULL,
	`privacy_notice_version` text NOT NULL,
	`authority_attested_at` text NOT NULL,
	`terms_accepted_at` text NOT NULL,
	`privacy_notice_accepted_at` text NOT NULL,
	`status` text NOT NULL,
	`identity_status` text NOT NULL,
	`taxpayer_verification_status` text NOT NULL,
	`licence_status` text NOT NULL,
	`promoted_registration_application_id` text,
	`submitted_at` text NOT NULL,
	FOREIGN KEY (`requested_plan_id`) REFERENCES `license_plans`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`promoted_registration_application_id`) REFERENCES `registration_applications`(`id`) ON UPDATE no action ON DELETE no action,
	CONSTRAINT "ck_self_serve_signup_applicant_role" CHECK("self_serve_signup_applications"."applicant_role" IN ('OWNER','DIRECTOR','PARTNER','TRUSTEE','AUTHORISED_REPRESENTATIVE')),
	CONSTRAINT "ck_self_serve_signup_country" CHECK("self_serve_signup_applications"."country_code" = 'NA'),
	CONSTRAINT "ck_self_serve_signup_identity_pair" CHECK(("self_serve_signup_applications"."identity_provider" IS NULL AND "self_serve_signup_applications"."identity_subject_hash" IS NULL) OR ("self_serve_signup_applications"."identity_provider" IS NOT NULL AND "self_serve_signup_applications"."identity_subject_hash" IS NOT NULL)),
	CONSTRAINT "ck_self_serve_signup_status" CHECK("self_serve_signup_applications"."status" IN ('PENDING_VERIFICATION','UNDER_REVIEW','REJECTED','APPROVED_FOR_PROVISIONING','WITHDRAWN')),
	CONSTRAINT "ck_self_serve_signup_identity_status" CHECK("self_serve_signup_applications"."identity_status" IN ('VERIFICATION_REQUIRED','EXTERNALLY_ASSERTED')),
	CONSTRAINT "ck_self_serve_signup_taxpayer_status" CHECK("self_serve_signup_applications"."taxpayer_verification_status" IN ('AWAITING_PROVIDER_CONTRACT','PENDING','VERIFIED','FAILED')),
	CONSTRAINT "ck_self_serve_signup_licence_status" CHECK("self_serve_signup_applications"."licence_status" = 'NOT_ACTIVATED')
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_self_serve_signup_reference` ON `self_serve_signup_applications` (`public_reference`);--> statement-breakpoint
CREATE UNIQUE INDEX `ux_self_serve_signup_contact_key` ON `self_serve_signup_applications` (`contact_email`,`idempotency_key`);--> statement-breakpoint
CREATE INDEX `idx_self_serve_signup_status_submitted` ON `self_serve_signup_applications` (`status`,`submitted_at`);--> statement-breakpoint
CREATE INDEX `idx_self_serve_signup_identifiers` ON `self_serve_signup_applications` (`vat_number`,`tin`);--> statement-breakpoint
CREATE UNIQUE INDEX `ux_self_serve_signup_active_vat`
  ON `self_serve_signup_applications` (`vat_number`)
  WHERE `status` IN ('PENDING_VERIFICATION','UNDER_REVIEW','APPROVED_FOR_PROVISIONING');--> statement-breakpoint
CREATE UNIQUE INDEX `ux_self_serve_signup_active_tin`
  ON `self_serve_signup_applications` (`tin`)
  WHERE `status` IN ('PENDING_VERIFICATION','UNDER_REVIEW','APPROVED_FOR_PROVISIONING');--> statement-breakpoint
CREATE TRIGGER `validate_self_serve_signup_insert`
  BEFORE INSERT ON `self_serve_signup_applications`
  BEGIN
    SELECT CASE WHEN NOT EXISTS (
      SELECT 1 FROM `license_plans` p WHERE p.id=NEW.requested_plan_id AND p.status='ACTIVE'
        AND datetime(p.effective_from) <= CURRENT_TIMESTAMP
        AND (p.effective_to IS NULL OR datetime(p.effective_to) > CURRENT_TIMESTAMP)
    ) THEN RAISE(ABORT,'SELF_SERVE_SIGNUP_PLAN_UNAVAILABLE') END;
    SELECT CASE WHEN EXISTS (
      SELECT 1 FROM `taxpayers` t WHERE t.vat_number=NEW.vat_number OR t.tin=NEW.tin
    ) THEN RAISE(ABORT,'SELF_SERVE_SIGNUP_CANONICAL_TAXPAYER_EXISTS') END;
    SELECT CASE WHEN EXISTS (
      SELECT 1 FROM `registration_applications` r
      WHERE (r.vat_number=NEW.vat_number OR r.tin=NEW.tin)
        AND r.status IN ('PENDING_VERIFICATION','UNDER_REVIEW','VERIFIED')
    ) THEN RAISE(ABORT,'SELF_SERVE_SIGNUP_CONTROLLED_REGISTRATION_EXISTS') END;
  END;--> statement-breakpoint
CREATE TRIGGER `prevent_self_serve_signup_input_update`
  BEFORE UPDATE OF id,public_reference,idempotency_key,request_hash,applicant_name,applicant_role,
    contact_email,identity_provider,identity_subject_hash,country_code,requested_plan_id,
    vat_number,tin,company_registration_number,legal_name,trading_name,taxpayer_type,
    return_frequency,address,terms_version,privacy_notice_version,authority_attested_at,
    terms_accepted_at,privacy_notice_accepted_at,licence_status,submitted_at
  ON `self_serve_signup_applications`
  BEGIN
    SELECT RAISE(ABORT,'SELF_SERVE_SIGNUP_INPUT_IMMUTABLE');
  END;--> statement-breakpoint
CREATE TRIGGER `enforce_self_serve_signup_transition`
  BEFORE UPDATE OF status ON `self_serve_signup_applications`
  WHEN NEW.status<>OLD.status AND NOT (
    (OLD.status='PENDING_VERIFICATION' AND NEW.status IN ('UNDER_REVIEW','REJECTED','WITHDRAWN'))
    OR (OLD.status='UNDER_REVIEW' AND NEW.status IN ('REJECTED','APPROVED_FOR_PROVISIONING','WITHDRAWN'))
  )
  BEGIN
    SELECT RAISE(ABORT,'SELF_SERVE_SIGNUP_TRANSITION_INVALID');
  END;--> statement-breakpoint
CREATE TRIGGER `enforce_self_serve_signup_promotion`
  BEFORE UPDATE OF promoted_registration_application_id ON `self_serve_signup_applications`
  WHEN NEW.promoted_registration_application_id IS NOT NULL
    AND (OLD.status<>'APPROVED_FOR_PROVISIONING' OR NEW.taxpayer_verification_status<>'VERIFIED')
  BEGIN
    SELECT RAISE(ABORT,'SELF_SERVE_SIGNUP_PROMOTION_NOT_APPROVED');
  END;--> statement-breakpoint
CREATE TRIGGER `prevent_self_serve_signup_delete`
  BEFORE DELETE ON `self_serve_signup_applications`
  BEGIN
    SELECT RAISE(ABORT,'SELF_SERVE_SIGNUP_IMMUTABLE_HISTORY');
  END;
