CREATE TABLE `counterparty_trust_events` (
	`id` text PRIMARY KEY NOT NULL,
	`trust_profile_id` text NOT NULL,
	`event_type` text NOT NULL,
	`from_status` text,
	`to_status` text NOT NULL,
	`reason_code` text NOT NULL,
	`evidence_hash` text,
	`actor_id` text NOT NULL,
	`occurred_at` text NOT NULL,
	FOREIGN KEY (`trust_profile_id`) REFERENCES `counterparty_trust_profiles`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`actor_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE INDEX `idx_counterparty_events_profile_time` ON `counterparty_trust_events` (`trust_profile_id`,`occurred_at`);--> statement-breakpoint
CREATE TABLE `counterparty_trust_profiles` (
	`id` text PRIMARY KEY NOT NULL,
	`business_party_id` text NOT NULL,
	`provider` text NOT NULL,
	`provider_environment` text NOT NULL,
	`trust_status` text NOT NULL,
	`tax_registration_status` text NOT NULL,
	`vat_verification_status` text NOT NULL,
	`tin_verification_status` text NOT NULL,
	`company_verification_status` text NOT NULL,
	`confidence_bps` integer DEFAULT 0 NOT NULL,
	`evidence_hash` text,
	`source_reference` text,
	`requested_by` text NOT NULL,
	`reviewed_by` text,
	`checked_at` text,
	`expires_at` text,
	`created_at` text NOT NULL,
	`updated_at` text NOT NULL,
	FOREIGN KEY (`business_party_id`) REFERENCES `business_parties`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`requested_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`reviewed_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	CONSTRAINT "ck_counterparty_trust_environment" CHECK("counterparty_trust_profiles"."provider_environment" IN ('CONTRACT_PENDING','SYNTHETIC_TEST','PRODUCTION_EQUIVALENT','PRODUCTION')),
	CONSTRAINT "ck_counterparty_trust_status" CHECK("counterparty_trust_profiles"."trust_status" IN ('PENDING_PROVIDER','SYNTHETIC_VALID','AUTHORITY_VERIFIED','MISMATCH','INVALID','EXPIRED','UNAVAILABLE')),
	CONSTRAINT "ck_counterparty_tax_registration_status" CHECK("counterparty_trust_profiles"."tax_registration_status" IN ('UNKNOWN','ACTIVE','INACTIVE','SUSPENDED','CANCELLED','NOT_REGISTERED')),
	CONSTRAINT "ck_counterparty_vat_verification" CHECK("counterparty_trust_profiles"."vat_verification_status" IN ('NOT_PROVIDED','PENDING','MATCHED','MISMATCH','INVALID')),
	CONSTRAINT "ck_counterparty_tin_verification" CHECK("counterparty_trust_profiles"."tin_verification_status" IN ('NOT_PROVIDED','PENDING','MATCHED','MISMATCH','INVALID')),
	CONSTRAINT "ck_counterparty_company_verification" CHECK("counterparty_trust_profiles"."company_verification_status" IN ('NOT_PROVIDED','PENDING','MATCHED','MISMATCH','INVALID')),
	CONSTRAINT "ck_counterparty_trust_confidence" CHECK("counterparty_trust_profiles"."confidence_bps" BETWEEN 0 AND 10000),
	CONSTRAINT "ck_counterparty_trust_review_separation" CHECK("counterparty_trust_profiles"."reviewed_by" IS NULL OR "counterparty_trust_profiles"."reviewed_by" <> "counterparty_trust_profiles"."requested_by")
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_counterparty_trust_party` ON `counterparty_trust_profiles` (`business_party_id`);--> statement-breakpoint
CREATE UNIQUE INDEX `ux_counterparty_trust_provider_reference` ON `counterparty_trust_profiles` (`provider`,`source_reference`);--> statement-breakpoint
CREATE INDEX `idx_counterparty_trust_status_expiry` ON `counterparty_trust_profiles` (`trust_status`,`expires_at`);--> statement-breakpoint
CREATE TABLE `counterparty_verification_snapshots` (
	`id` text PRIMARY KEY NOT NULL,
	`trust_profile_id` text NOT NULL,
	`provider` text NOT NULL,
	`provider_environment` text NOT NULL,
	`source_reference` text NOT NULL,
	`observed_vat_number` text,
	`observed_tin` text,
	`observed_company_registration_number` text,
	`tax_registration_status` text NOT NULL,
	`trust_status` text NOT NULL,
	`confidence_bps` integer NOT NULL,
	`matched_fields` text NOT NULL,
	`conflicting_fields` text NOT NULL,
	`evidence_hash` text NOT NULL,
	`checked_at` text NOT NULL,
	`expires_at` text NOT NULL,
	`recorded_by` text NOT NULL,
	FOREIGN KEY (`trust_profile_id`) REFERENCES `counterparty_trust_profiles`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`recorded_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	CONSTRAINT "ck_counterparty_snapshot_confidence" CHECK("counterparty_verification_snapshots"."confidence_bps" BETWEEN 0 AND 10000)
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_counterparty_snapshot_reference` ON `counterparty_verification_snapshots` (`provider`,`source_reference`);--> statement-breakpoint
CREATE INDEX `idx_counterparty_snapshot_profile_time` ON `counterparty_verification_snapshots` (`trust_profile_id`,`checked_at`);--> statement-breakpoint
ALTER TABLE `business_parties` ADD `company_registration_number` text;--> statement-breakpoint
CREATE UNIQUE INDEX `ux_business_parties_active_vat` ON `business_parties` (`organisation_id`,`vat_number`) WHERE "business_parties"."status" = 'ACTIVE' AND "business_parties"."vat_number" IS NOT NULL;--> statement-breakpoint
CREATE UNIQUE INDEX `ux_business_parties_active_tin` ON `business_parties` (`organisation_id`,`tin`) WHERE "business_parties"."status" = 'ACTIVE' AND "business_parties"."tin" IS NOT NULL;--> statement-breakpoint
CREATE UNIQUE INDEX `ux_business_parties_active_company_registration` ON `business_parties` (`organisation_id`,`company_registration_number`) WHERE "business_parties"."status" = 'ACTIVE' AND "business_parties"."company_registration_number" IS NOT NULL;
--> statement-breakpoint
INSERT INTO counterparty_trust_profiles
  (id,business_party_id,provider,provider_environment,trust_status,tax_registration_status,vat_verification_status,
   tin_verification_status,company_verification_status,confidence_bps,evidence_hash,source_reference,requested_by,reviewed_by,
   checked_at,expires_at,created_at,updated_at)
SELECT 'trust-'||p.id,p.id,'ITAS_BIPA','CONTRACT_PENDING','PENDING_PROVIDER','UNKNOWN',
  CASE WHEN p.vat_number IS NULL THEN 'NOT_PROVIDED' ELSE 'PENDING' END,
  CASE WHEN p.tin IS NULL THEN 'NOT_PROVIDED' ELSE 'PENDING' END,
  CASE WHEN p.company_registration_number IS NULL THEN 'NOT_PROVIDED' ELSE 'PENDING' END,
  0,NULL,NULL,COALESCE((SELECT id FROM app_users WHERE id='usr-local-admin'),(SELECT id FROM app_users ORDER BY id LIMIT 1)),
  NULL,NULL,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP
FROM business_parties p WHERE NOT EXISTS (SELECT 1 FROM counterparty_trust_profiles t WHERE t.business_party_id=p.id);
--> statement-breakpoint
INSERT INTO counterparty_trust_events
  (id,trust_profile_id,event_type,from_status,to_status,reason_code,evidence_hash,actor_id,occurred_at)
SELECT 'trust-event-'||t.business_party_id,t.id,'CounterpartyVerificationRequested',NULL,'PENDING_PROVIDER',
  'AUTHORITY_PROVIDER_CONTRACT_REQUIRED',NULL,t.requested_by,CURRENT_TIMESTAMP
FROM counterparty_trust_profiles t WHERE NOT EXISTS (SELECT 1 FROM counterparty_trust_events e WHERE e.trust_profile_id=t.id);
--> statement-breakpoint
CREATE TRIGGER counterparty_authority_guard_insert BEFORE INSERT ON counterparty_trust_profiles
WHEN NEW.trust_status='AUTHORITY_VERIFIED' AND (NEW.provider_environment NOT IN ('PRODUCTION_EQUIVALENT','PRODUCTION')
 OR NEW.evidence_hash IS NULL OR length(trim(NEW.evidence_hash))<32 OR NEW.source_reference IS NULL
 OR NEW.checked_at IS NULL OR NEW.expires_at IS NULL OR datetime(NEW.expires_at)<=datetime(NEW.checked_at)
 OR NEW.reviewed_by IS NULL OR NEW.reviewed_by=NEW.requested_by)
BEGIN SELECT RAISE(ABORT,'COUNTERPARTY_AUTHORITY_EVIDENCE_REQUIRED'); END;
--> statement-breakpoint
CREATE TRIGGER counterparty_authority_guard_update BEFORE UPDATE OF trust_status,provider_environment,evidence_hash,source_reference,checked_at,expires_at,reviewed_by ON counterparty_trust_profiles
WHEN NEW.trust_status='AUTHORITY_VERIFIED' AND (NEW.provider_environment NOT IN ('PRODUCTION_EQUIVALENT','PRODUCTION')
 OR NEW.evidence_hash IS NULL OR length(trim(NEW.evidence_hash))<32 OR NEW.source_reference IS NULL
 OR NEW.checked_at IS NULL OR NEW.expires_at IS NULL OR datetime(NEW.expires_at)<=datetime(NEW.checked_at)
 OR NEW.reviewed_by IS NULL OR NEW.reviewed_by=NEW.requested_by)
BEGIN SELECT RAISE(ABORT,'COUNTERPARTY_AUTHORITY_EVIDENCE_REQUIRED'); END;
--> statement-breakpoint
CREATE TRIGGER counterparty_synthetic_guard_insert BEFORE INSERT ON counterparty_trust_profiles
WHEN NEW.trust_status='SYNTHETIC_VALID' AND (NEW.provider_environment<>'SYNTHETIC_TEST'
 OR NEW.evidence_hash IS NULL OR length(trim(NEW.evidence_hash))<32 OR NEW.checked_at IS NULL OR NEW.expires_at IS NULL
 OR datetime(NEW.expires_at)<=datetime(NEW.checked_at))
BEGIN SELECT RAISE(ABORT,'COUNTERPARTY_SYNTHETIC_EVIDENCE_INVALID'); END;
--> statement-breakpoint
CREATE TRIGGER counterparty_synthetic_guard_update BEFORE UPDATE OF trust_status,provider_environment,evidence_hash,checked_at,expires_at ON counterparty_trust_profiles
WHEN NEW.trust_status='SYNTHETIC_VALID' AND (NEW.provider_environment<>'SYNTHETIC_TEST'
 OR NEW.evidence_hash IS NULL OR length(trim(NEW.evidence_hash))<32 OR NEW.checked_at IS NULL OR NEW.expires_at IS NULL
 OR datetime(NEW.expires_at)<=datetime(NEW.checked_at))
BEGIN SELECT RAISE(ABORT,'COUNTERPARTY_SYNTHETIC_EVIDENCE_INVALID'); END;
--> statement-breakpoint
CREATE TRIGGER counterparty_profile_no_delete BEFORE DELETE ON counterparty_trust_profiles
BEGIN SELECT RAISE(ABORT,'COUNTERPARTY_TRUST_HISTORY_IMMUTABLE'); END;
--> statement-breakpoint
CREATE TRIGGER counterparty_snapshot_no_update BEFORE UPDATE ON counterparty_verification_snapshots
BEGIN SELECT RAISE(ABORT,'COUNTERPARTY_SNAPSHOT_IMMUTABLE'); END;
--> statement-breakpoint
CREATE TRIGGER counterparty_snapshot_no_delete BEFORE DELETE ON counterparty_verification_snapshots
BEGIN SELECT RAISE(ABORT,'COUNTERPARTY_SNAPSHOT_IMMUTABLE'); END;
--> statement-breakpoint
CREATE TRIGGER counterparty_event_no_update BEFORE UPDATE ON counterparty_trust_events
BEGIN SELECT RAISE(ABORT,'COUNTERPARTY_TRUST_EVENT_IMMUTABLE'); END;
--> statement-breakpoint
CREATE TRIGGER counterparty_event_no_delete BEFORE DELETE ON counterparty_trust_events
BEGIN SELECT RAISE(ABORT,'COUNTERPARTY_TRUST_EVENT_IMMUTABLE'); END;
--> statement-breakpoint
CREATE TRIGGER counterparty_identity_change_requires_reverification BEFORE UPDATE OF legal_name,vat_number,tin,company_registration_number ON business_parties
WHEN (COALESCE(NEW.legal_name,'')<>COALESCE(OLD.legal_name,'') OR COALESCE(NEW.vat_number,'')<>COALESCE(OLD.vat_number,'')
 OR COALESCE(NEW.tin,'')<>COALESCE(OLD.tin,'') OR COALESCE(NEW.company_registration_number,'')<>COALESCE(OLD.company_registration_number,''))
 AND EXISTS (SELECT 1 FROM counterparty_trust_profiles t WHERE t.business_party_id=OLD.id AND t.trust_status<>'PENDING_PROVIDER')
BEGIN SELECT RAISE(ABORT,'COUNTERPARTY_REVERIFICATION_REQUIRED'); END;
--> statement-breakpoint
CREATE TRIGGER counterparty_relationship_trust_insert BEFORE INSERT ON party_relationships
WHEN NEW.status='ACTIVE' AND NOT EXISTS (SELECT 1 FROM counterparty_trust_profiles t WHERE t.business_party_id=NEW.party_id)
BEGIN SELECT RAISE(ABORT,'COUNTERPARTY_TRUST_PROFILE_REQUIRED'); END;
--> statement-breakpoint
CREATE TRIGGER counterparty_relationship_trust_update BEFORE UPDATE OF status ON party_relationships
WHEN NEW.status='ACTIVE' AND NOT EXISTS (SELECT 1 FROM counterparty_trust_profiles t WHERE t.business_party_id=NEW.party_id)
BEGIN SELECT RAISE(ABORT,'COUNTERPARTY_TRUST_PROFILE_REQUIRED'); END;
--> statement-breakpoint
CREATE TRIGGER quotation_counterparty_trust_insert BEFORE INSERT ON quotations
WHEN NOT EXISTS (SELECT 1 FROM counterparty_trust_profiles t WHERE t.business_party_id=NEW.customer_party_id
 AND t.trust_status IN ('AUTHORITY_VERIFIED','SYNTHETIC_VALID') AND datetime(t.expires_at)>CURRENT_TIMESTAMP)
BEGIN SELECT RAISE(ABORT,'COUNTERPARTY_TRUST_REQUIRED'); END;
--> statement-breakpoint
CREATE TRIGGER quotation_counterparty_trust_update BEFORE UPDATE OF customer_party_id ON quotations
WHEN NOT EXISTS (SELECT 1 FROM counterparty_trust_profiles t WHERE t.business_party_id=NEW.customer_party_id
 AND t.trust_status IN ('AUTHORITY_VERIFIED','SYNTHETIC_VALID') AND datetime(t.expires_at)>CURRENT_TIMESTAMP)
BEGIN SELECT RAISE(ABORT,'COUNTERPARTY_TRUST_REQUIRED'); END;
--> statement-breakpoint
CREATE TRIGGER project_counterparty_trust_insert BEFORE INSERT ON projects
WHEN NEW.customer_party_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM counterparty_trust_profiles t WHERE t.business_party_id=NEW.customer_party_id
 AND t.trust_status IN ('AUTHORITY_VERIFIED','SYNTHETIC_VALID') AND datetime(t.expires_at)>CURRENT_TIMESTAMP)
BEGIN SELECT RAISE(ABORT,'COUNTERPARTY_TRUST_REQUIRED'); END;
--> statement-breakpoint
CREATE TRIGGER expense_counterparty_trust_insert BEFORE INSERT ON expenses
WHEN (NEW.supplier_party_id IS NOT NULL OR NEW.tax_cents>0) AND (NEW.supplier_party_id IS NULL OR NOT EXISTS (
 SELECT 1 FROM counterparty_trust_profiles t WHERE t.business_party_id=NEW.supplier_party_id
 AND t.trust_status IN ('AUTHORITY_VERIFIED','SYNTHETIC_VALID') AND datetime(t.expires_at)>CURRENT_TIMESTAMP
 AND (NEW.tax_cents=0 OR t.tax_registration_status='ACTIVE')))
BEGIN SELECT RAISE(ABORT,'COUNTERPARTY_TRUST_REQUIRED'); END;
--> statement-breakpoint
INSERT INTO app_schema_revisions (revision,applied_at,source)
VALUES ('issue3-counterparty-trust-2026-08-23',CURRENT_TIMESTAMP,'DRIZZLE_MIGRATION_0018');
