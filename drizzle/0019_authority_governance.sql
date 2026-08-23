CREATE TABLE `tax_authority_access_reviews` (
	`id` text PRIMARY KEY NOT NULL,
	`tax_authority_id` text NOT NULL,
	`review_type` text NOT NULL,
	`period_start` text NOT NULL,
	`due_at` text NOT NULL,
	`status` text NOT NULL,
	`owner_id` text NOT NULL,
	`completed_by` text,
	`completed_at` text,
	`created_at` text NOT NULL,
	FOREIGN KEY (`tax_authority_id`) REFERENCES `tax_authorities`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`owner_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`completed_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	CONSTRAINT "ck_tax_authority_access_review_type" CHECK("tax_authority_access_reviews"."review_type" = 'QUARTERLY'),
	CONSTRAINT "ck_tax_authority_access_review_status" CHECK("tax_authority_access_reviews"."status" IN ('OPEN','COMPLETED','OVERDUE')),
	CONSTRAINT "ck_tax_authority_access_review_no_self_completion" CHECK("tax_authority_access_reviews"."completed_by" IS NULL OR "tax_authority_access_reviews"."completed_by" <> "tax_authority_access_reviews"."owner_id")
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_tax_authority_access_review_period` ON `tax_authority_access_reviews` (`tax_authority_id`,`review_type`,`period_start`);--> statement-breakpoint
CREATE INDEX `idx_tax_authority_access_review_status` ON `tax_authority_access_reviews` (`tax_authority_id`,`status`,`due_at`);--> statement-breakpoint
CREATE TABLE `tax_authority_federation_connections` (
	`id` text PRIMARY KEY NOT NULL,
	`tax_authority_id` text NOT NULL,
	`identity_provider_id` text NOT NULL,
	`environment` text NOT NULL,
	`protocol` text NOT NULL,
	`issuer` text,
	`audience` text,
	`metadata_hash` text,
	`claims_contract_hash` text,
	`assurance_profile` text,
	`status` text NOT NULL,
	`requested_by` text NOT NULL,
	`reviewed_by` text,
	`checked_at` text,
	`expires_at` text,
	`created_at` text NOT NULL,
	`updated_at` text NOT NULL,
	FOREIGN KEY (`tax_authority_id`) REFERENCES `tax_authorities`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`identity_provider_id`) REFERENCES `identity_providers`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`requested_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`reviewed_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	CONSTRAINT "ck_tax_authority_federation_environment" CHECK("tax_authority_federation_connections"."environment" IN ('CONTRACT_PENDING','SYNTHETIC_TEST','PRODUCTION_EQUIVALENT','PRODUCTION')),
	CONSTRAINT "ck_tax_authority_federation_protocol" CHECK("tax_authority_federation_connections"."protocol" IN ('UNCONFIRMED','OIDC','SAML')),
	CONSTRAINT "ck_tax_authority_federation_status" CHECK("tax_authority_federation_connections"."status" IN ('CONTRACT_PENDING','CONFIGURATION_PENDING','CONFORMANCE_PENDING','LOCAL_STAGING_READY','PRODUCTION_APPROVED','SUSPENDED','REVOKED')),
	CONSTRAINT "ck_tax_authority_federation_no_self_review" CHECK("tax_authority_federation_connections"."reviewed_by" IS NULL OR "tax_authority_federation_connections"."reviewed_by" <> "tax_authority_federation_connections"."requested_by")
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_tax_authority_federation_environment` ON `tax_authority_federation_connections` (`tax_authority_id`,`identity_provider_id`,`environment`);--> statement-breakpoint
CREATE INDEX `idx_tax_authority_federation_status` ON `tax_authority_federation_connections` (`tax_authority_id`,`status`,`expires_at`);--> statement-breakpoint
CREATE TABLE `tax_authority_governance_events` (
	`id` text PRIMARY KEY NOT NULL,
	`tax_authority_id` text NOT NULL,
	`onboarding_case_id` text,
	`event_type` text NOT NULL,
	`from_status` text,
	`to_status` text NOT NULL,
	`reason_code` text NOT NULL,
	`evidence_hash` text,
	`actor_id` text NOT NULL,
	`occurred_at` text NOT NULL,
	FOREIGN KEY (`tax_authority_id`) REFERENCES `tax_authorities`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`onboarding_case_id`) REFERENCES `tax_authority_onboarding_cases`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`actor_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE INDEX `idx_tax_authority_governance_event_time` ON `tax_authority_governance_events` (`tax_authority_id`,`occurred_at`);--> statement-breakpoint
CREATE TABLE `tax_authority_onboarding_cases` (
	`id` text PRIMARY KEY NOT NULL,
	`tax_authority_id` text NOT NULL,
	`target_environment` text NOT NULL,
	`status` text NOT NULL,
	`purpose` text NOT NULL,
	`evidence_bundle_hash` text,
	`readiness_reference` text,
	`requested_by` text NOT NULL,
	`submitted_at` text NOT NULL,
	`approved_at` text,
	`activated_at` text,
	`created_at` text NOT NULL,
	`updated_at` text NOT NULL,
	FOREIGN KEY (`tax_authority_id`) REFERENCES `tax_authorities`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`requested_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	CONSTRAINT "ck_tax_authority_onboarding_environment" CHECK("tax_authority_onboarding_cases"."target_environment" IN ('LOCAL_STAGING','PRODUCTION')),
	CONSTRAINT "ck_tax_authority_onboarding_status" CHECK("tax_authority_onboarding_cases"."status" IN ('SUBMITTED','UNDER_REVIEW','LOCAL_STAGING_READY','BLOCKED_EXTERNAL','REJECTED','PRODUCTION_ACTIVATED'))
);
--> statement-breakpoint
CREATE INDEX `idx_tax_authority_onboarding_status` ON `tax_authority_onboarding_cases` (`tax_authority_id`,`status`,`submitted_at`);--> statement-breakpoint
CREATE TABLE `tax_authority_onboarding_decisions` (
	`id` text PRIMARY KEY NOT NULL,
	`onboarding_case_id` text NOT NULL,
	`decision_type` text NOT NULL,
	`decision` text NOT NULL,
	`reason` text NOT NULL,
	`requested_by` text NOT NULL,
	`decided_by` text NOT NULL,
	`evidence_hash` text NOT NULL,
	`step_up_evidence_reference` text NOT NULL,
	`occurred_at` text NOT NULL,
	FOREIGN KEY (`onboarding_case_id`) REFERENCES `tax_authority_onboarding_cases`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`requested_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`decided_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	CONSTRAINT "ck_tax_authority_onboarding_decision_type" CHECK("tax_authority_onboarding_decisions"."decision_type" IN ('LOCAL_STAGING_APPROVAL','SECURITY_APPROVAL','PRIVACY_APPROVAL','LEGAL_APPROVAL','INTEGRATION_APPROVAL','ACTIVATION_APPROVAL','REJECTION')),
	CONSTRAINT "ck_tax_authority_onboarding_decision" CHECK("tax_authority_onboarding_decisions"."decision" IN ('APPROVE','REJECT')),
	CONSTRAINT "ck_tax_authority_onboarding_no_self_decision" CHECK("tax_authority_onboarding_decisions"."requested_by" <> "tax_authority_onboarding_decisions"."decided_by")
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_tax_authority_onboarding_decision_type` ON `tax_authority_onboarding_decisions` (`onboarding_case_id`,`decision_type`);--> statement-breakpoint
CREATE INDEX `idx_tax_authority_onboarding_decider` ON `tax_authority_onboarding_decisions` (`decided_by`,`occurred_at`);--> statement-breakpoint
CREATE TABLE `tax_authority_role_assignments` (
	`id` text PRIMARY KEY NOT NULL,
	`tax_authority_id` text NOT NULL,
	`authority_unit_id` text,
	`user_id` text NOT NULL,
	`role_code` text NOT NULL,
	`scope` text NOT NULL,
	`status` text NOT NULL,
	`effective_from` text NOT NULL,
	`effective_to` text,
	`requested_by` text NOT NULL,
	`approved_by` text NOT NULL,
	`approval_reference` text NOT NULL,
	`created_at` text NOT NULL,
	FOREIGN KEY (`tax_authority_id`) REFERENCES `tax_authorities`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`authority_unit_id`) REFERENCES `tax_authority_units`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`user_id`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`role_code`) REFERENCES `tax_authority_role_definitions`(`code`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`requested_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`approved_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action,
	CONSTRAINT "ck_tax_authority_role_assignment_status" CHECK("tax_authority_role_assignments"."status" IN ('ACTIVE','SUSPENDED','REVOKED','EXPIRED')),
	CONSTRAINT "ck_tax_authority_role_assignment_no_self_approval" CHECK("tax_authority_role_assignments"."requested_by" <> "tax_authority_role_assignments"."approved_by")
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_tax_authority_role_assignment` ON `tax_authority_role_assignments` (`tax_authority_id`,`user_id`,`role_code`,`authority_unit_id`);--> statement-breakpoint
CREATE INDEX `idx_tax_authority_role_assignment_status` ON `tax_authority_role_assignments` (`tax_authority_id`,`status`,`effective_to`);--> statement-breakpoint
CREATE TABLE `tax_authority_role_definitions` (
	`code` text PRIMARY KEY NOT NULL,
	`name` text NOT NULL,
	`duty_class` text NOT NULL,
	`assurance_required` text NOT NULL,
	`protected` integer DEFAULT 1 NOT NULL,
	`status` text NOT NULL,
	`created_at` text NOT NULL,
	CONSTRAINT "ck_tax_authority_role_duty" CHECK("tax_authority_role_definitions"."duty_class" IN ('ONBOARDING_MAKER','SECURITY_REVIEW','PRIVACY_REVIEW','LEGAL_REVIEW','INTEGRATION_REVIEW','ACTIVATION_APPROVAL','ACCESS_REVIEW','SYSTEM_ADMINISTRATION','AUDIT')),
	CONSTRAINT "ck_tax_authority_role_assurance" CHECK("tax_authority_role_definitions"."assurance_required" IN ('MFA','PHISHING_RESISTANT_MFA')),
	CONSTRAINT "ck_tax_authority_role_status" CHECK("tax_authority_role_definitions"."status" IN ('ACTIVE','INACTIVE'))
);
--> statement-breakpoint
CREATE TABLE `tax_authority_units` (
	`id` text PRIMARY KEY NOT NULL,
	`tax_authority_id` text NOT NULL,
	`parent_unit_id` text,
	`code` text NOT NULL,
	`name` text NOT NULL,
	`unit_type` text NOT NULL,
	`status` text NOT NULL,
	`created_at` text NOT NULL,
	FOREIGN KEY (`tax_authority_id`) REFERENCES `tax_authorities`(`id`) ON UPDATE no action ON DELETE no action,
	CONSTRAINT "ck_tax_authority_unit_type" CHECK("tax_authority_units"."unit_type" IN ('HEAD_OFFICE','DIRECTORATE','DIVISION','REGION','OFFICE','TEAM')),
	CONSTRAINT "ck_tax_authority_unit_status" CHECK("tax_authority_units"."status" IN ('ACTIVE','INACTIVE')),
	CONSTRAINT "ck_tax_authority_unit_no_self_parent" CHECK("tax_authority_units"."parent_unit_id" IS NULL OR "tax_authority_units"."parent_unit_id" <> "tax_authority_units"."id")
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_tax_authority_unit_code` ON `tax_authority_units` (`tax_authority_id`,`code`);--> statement-breakpoint
CREATE INDEX `idx_tax_authority_unit_parent` ON `tax_authority_units` (`tax_authority_id`,`parent_unit_id`,`status`);
--> statement-breakpoint
INSERT OR IGNORE INTO access_permissions
  (code,resource,action,description,classification,created_at) VALUES
  ('authority-governance:read','TAX_AUTHORITY_GOVERNANCE','READ','Read scoped Tax Authority hierarchy federation onboarding and review posture','SECURITY',CURRENT_TIMESTAMP),
  ('authority-governance:manage','TAX_AUTHORITY_GOVERNANCE','MANAGE','Manage governed Tax Authority onboarding decisions without production activation','SECURITY',CURRENT_TIMESTAMP);
--> statement-breakpoint
INSERT OR IGNORE INTO role_permission_grants
  (id,role_code,permission_code,effect,conditions,created_at)
SELECT 'rpg-pa-agr','PILOT_ADMIN','authority-governance:read','ALLOW','{"scope":"assigned-authority","environment":"local-staging"}',CURRENT_TIMESTAMP
WHERE EXISTS (SELECT 1 FROM access_roles WHERE code='PILOT_ADMIN');
--> statement-breakpoint
INSERT OR IGNORE INTO role_permission_grants
  (id,role_code,permission_code,effect,conditions,created_at)
SELECT 'rpg-pa-agm','PILOT_ADMIN','authority-governance:manage','ALLOW','{"scope":"assigned-authority","requires":"step-up-independent-review"}',CURRENT_TIMESTAMP
WHERE EXISTS (SELECT 1 FROM access_roles WHERE code='PILOT_ADMIN');
--> statement-breakpoint
INSERT OR IGNORE INTO role_permission_grants
  (id,role_code,permission_code,effect,conditions,created_at)
SELECT 'rpg-nsa-agr','NAMRA_SYSTEM_ADMIN','authority-governance:read','ALLOW','{"scope":"assigned-authority"}',CURRENT_TIMESTAMP
WHERE EXISTS (SELECT 1 FROM access_roles WHERE code='NAMRA_SYSTEM_ADMIN');
--> statement-breakpoint
INSERT OR IGNORE INTO role_permission_grants
  (id,role_code,permission_code,effect,conditions,created_at)
SELECT 'rpg-nsa-agm','NAMRA_SYSTEM_ADMIN','authority-governance:manage','ALLOW','{"scope":"assigned-authority","requires":"step-up-independent-review"}',CURRENT_TIMESTAMP
WHERE EXISTS (SELECT 1 FROM access_roles WHERE code='NAMRA_SYSTEM_ADMIN');
--> statement-breakpoint
INSERT OR REPLACE INTO license_permission_policies
  (permission_code,feature_key,operation_class,status,created_at,updated_at)
SELECT 'authority-governance:read','CORE_VAT','READ','ACTIVE',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP
WHERE EXISTS (SELECT 1 FROM license_features WHERE feature_key='CORE_VAT');
--> statement-breakpoint
INSERT OR REPLACE INTO license_permission_policies
  (permission_code,feature_key,operation_class,status,created_at,updated_at)
SELECT 'authority-governance:manage','CORE_VAT','ADMIN_WRITE','ACTIVE',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP
WHERE EXISTS (SELECT 1 FROM license_features WHERE feature_key='CORE_VAT');
--> statement-breakpoint
INSERT OR IGNORE INTO tax_authority_role_definitions (code,name,duty_class,assurance_required,protected,status,created_at) VALUES
  ('AUTHORITY_ONBOARDING_MAKER','Authority Onboarding Maker','ONBOARDING_MAKER','PHISHING_RESISTANT_MFA',1,'ACTIVE',CURRENT_TIMESTAMP),
  ('AUTHORITY_SECURITY_REVIEWER','Authority Security Reviewer','SECURITY_REVIEW','PHISHING_RESISTANT_MFA',1,'ACTIVE',CURRENT_TIMESTAMP),
  ('AUTHORITY_PRIVACY_REVIEWER','Authority Privacy Reviewer','PRIVACY_REVIEW','PHISHING_RESISTANT_MFA',1,'ACTIVE',CURRENT_TIMESTAMP),
  ('AUTHORITY_LEGAL_REVIEWER','Authority Legal Reviewer','LEGAL_REVIEW','PHISHING_RESISTANT_MFA',1,'ACTIVE',CURRENT_TIMESTAMP),
  ('AUTHORITY_INTEGRATION_REVIEWER','Authority Integration Reviewer','INTEGRATION_REVIEW','PHISHING_RESISTANT_MFA',1,'ACTIVE',CURRENT_TIMESTAMP),
  ('AUTHORITY_ACTIVATION_APPROVER','Authority Activation Approver','ACTIVATION_APPROVAL','PHISHING_RESISTANT_MFA',1,'ACTIVE',CURRENT_TIMESTAMP),
  ('AUTHORITY_ACCESS_REVIEWER','Authority Access Reviewer','ACCESS_REVIEW','PHISHING_RESISTANT_MFA',1,'ACTIVE',CURRENT_TIMESTAMP),
  ('AUTHORITY_SYSTEM_ADMIN','Authority System Administrator','SYSTEM_ADMINISTRATION','PHISHING_RESISTANT_MFA',1,'ACTIVE',CURRENT_TIMESTAMP),
  ('AUTHORITY_GOVERNANCE_AUDITOR','Authority Governance Auditor','AUDIT','MFA',1,'ACTIVE',CURRENT_TIMESTAMP);
--> statement-breakpoint
INSERT OR IGNORE INTO tax_authority_units
  (id,tax_authority_id,parent_unit_id,code,name,unit_type,status,created_at)
SELECT 'authority-unit-'||ta.id||'-hq',ta.id,NULL,'HQ','Head Office','HEAD_OFFICE','ACTIVE',CURRENT_TIMESTAMP
FROM tax_authorities ta;
--> statement-breakpoint
INSERT OR IGNORE INTO tax_authority_federation_connections
  (id,tax_authority_id,identity_provider_id,environment,protocol,issuer,audience,metadata_hash,claims_contract_hash,
   assurance_profile,status,requested_by,reviewed_by,checked_at,expires_at,created_at,updated_at)
SELECT 'authority-federation-'||ta.id||'-contract-pending',ta.id,provider.id,'CONTRACT_PENDING','UNCONFIRMED',
  NULL,NULL,NULL,NULL,NULL,'CONTRACT_PENDING',admin.user_id,NULL,NULL,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP
FROM tax_authorities ta JOIN tax_authority_administrators admin ON admin.tax_authority_id=ta.id
JOIN identity_providers provider ON provider.provider_key='ITAS'
WHERE admin.id=(SELECT candidate.id FROM tax_authority_administrators candidate
  WHERE candidate.tax_authority_id=ta.id ORDER BY candidate.effective_from,candidate.id LIMIT 1);
--> statement-breakpoint
INSERT OR IGNORE INTO tax_authority_onboarding_cases
  (id,tax_authority_id,target_environment,status,purpose,evidence_bundle_hash,readiness_reference,requested_by,
   submitted_at,approved_at,activated_at,created_at,updated_at)
SELECT 'authority-onboarding-'||ta.id||'-production',ta.id,'PRODUCTION','BLOCKED_EXTERNAL',
  'Existing Tax Authority requires the Issue 4 production governance, federation and independent acceptance evidence package.',
  NULL,'PR-013-REQUIRED',admin.user_id,CURRENT_TIMESTAMP,NULL,NULL,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP
FROM tax_authorities ta JOIN tax_authority_administrators admin ON admin.tax_authority_id=ta.id
WHERE admin.id=(SELECT candidate.id FROM tax_authority_administrators candidate
  WHERE candidate.tax_authority_id=ta.id ORDER BY candidate.effective_from,candidate.id LIMIT 1);
--> statement-breakpoint
INSERT OR IGNORE INTO tax_authority_governance_events
  (id,tax_authority_id,onboarding_case_id,event_type,from_status,to_status,reason_code,evidence_hash,actor_id,occurred_at)
SELECT 'authority-governance-event-'||c.tax_authority_id||'-production-blocked',c.tax_authority_id,c.id,
  'ProductionAuthorityOnboardingBlocked',NULL,'BLOCKED_EXTERNAL','PRODUCTION_AUTHORITY_EVIDENCE_REQUIRED',NULL,c.requested_by,CURRENT_TIMESTAMP
FROM tax_authority_onboarding_cases c WHERE c.target_environment='PRODUCTION';
--> statement-breakpoint
WITH quarter(period_start) AS (
    SELECT CASE
      WHEN cast(strftime('%m','now') AS integer)<=3 THEN strftime('%Y-01-01','now')
      WHEN cast(strftime('%m','now') AS integer)<=6 THEN strftime('%Y-04-01','now')
      WHEN cast(strftime('%m','now') AS integer)<=9 THEN strftime('%Y-07-01','now')
      ELSE strftime('%Y-10-01','now') END)
INSERT OR IGNORE INTO tax_authority_access_reviews
  (id,tax_authority_id,review_type,period_start,due_at,status,owner_id,completed_by,completed_at,created_at)
SELECT 'authority-review-'||ta.id||'-'||quarter.period_start,ta.id,'QUARTERLY',quarter.period_start,
  datetime(quarter.period_start,'+3 months','+14 days'),'OPEN',admin.user_id,NULL,NULL,CURRENT_TIMESTAMP
FROM tax_authorities ta JOIN tax_authority_administrators admin ON admin.tax_authority_id=ta.id CROSS JOIN quarter
WHERE admin.id=(SELECT candidate.id FROM tax_authority_administrators candidate
  WHERE candidate.tax_authority_id=ta.id ORDER BY candidate.effective_from,candidate.id LIMIT 1);
--> statement-breakpoint
CREATE TRIGGER tax_authority_unit_scope_insert
  BEFORE INSERT ON tax_authority_units WHEN NEW.parent_unit_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM tax_authority_units parent WHERE parent.id=NEW.parent_unit_id AND parent.tax_authority_id=NEW.tax_authority_id)
  BEGIN SELECT RAISE(ABORT,'TAX_AUTHORITY_UNIT_PARENT_SCOPE_MISMATCH'); END;
--> statement-breakpoint
CREATE TRIGGER tax_authority_unit_scope_update
  BEFORE UPDATE OF parent_unit_id,tax_authority_id ON tax_authority_units WHEN NEW.parent_unit_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM tax_authority_units parent WHERE parent.id=NEW.parent_unit_id AND parent.tax_authority_id=NEW.tax_authority_id)
  BEGIN SELECT RAISE(ABORT,'TAX_AUTHORITY_UNIT_PARENT_SCOPE_MISMATCH'); END;
--> statement-breakpoint
CREATE TRIGGER tax_authority_role_assignment_scope_insert
  BEFORE INSERT ON tax_authority_role_assignments WHEN NEW.authority_unit_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM tax_authority_units unit WHERE unit.id=NEW.authority_unit_id AND unit.tax_authority_id=NEW.tax_authority_id)
  BEGIN SELECT RAISE(ABORT,'TAX_AUTHORITY_ROLE_UNIT_SCOPE_MISMATCH'); END;
--> statement-breakpoint
CREATE TRIGGER tax_authority_role_assignment_no_self_approve
  BEFORE INSERT ON tax_authority_role_assignments WHEN NEW.approved_by=NEW.user_id
  BEGIN SELECT RAISE(ABORT,'TAX_AUTHORITY_ROLE_SELF_APPROVAL_DENIED'); END;
--> statement-breakpoint
CREATE TRIGGER tax_authority_role_assignment_sod_insert
  BEFORE INSERT ON tax_authority_role_assignments WHEN NEW.status='ACTIVE' AND EXISTS (
    SELECT 1 FROM tax_authority_role_assignments existing
    JOIN tax_authority_role_definitions existing_role ON existing_role.code=existing.role_code
    JOIN tax_authority_role_definitions new_role ON new_role.code=NEW.role_code
    WHERE existing.tax_authority_id=NEW.tax_authority_id AND existing.user_id=NEW.user_id AND existing.status='ACTIVE'
      AND ((existing_role.duty_class='ONBOARDING_MAKER' AND new_role.duty_class IN ('SECURITY_REVIEW','PRIVACY_REVIEW','LEGAL_REVIEW','INTEGRATION_REVIEW','ACTIVATION_APPROVAL'))
        OR (new_role.duty_class='ONBOARDING_MAKER' AND existing_role.duty_class IN ('SECURITY_REVIEW','PRIVACY_REVIEW','LEGAL_REVIEW','INTEGRATION_REVIEW','ACTIVATION_APPROVAL'))))
  BEGIN SELECT RAISE(ABORT,'TAX_AUTHORITY_ROLE_SOD_CONFLICT'); END;
--> statement-breakpoint
CREATE TRIGGER tax_authority_role_assignment_no_delete BEFORE DELETE ON tax_authority_role_assignments
  BEGIN SELECT RAISE(ABORT,'TAX_AUTHORITY_ROLE_ASSIGNMENT_IMMUTABLE'); END;
--> statement-breakpoint
CREATE TRIGGER tax_authority_federation_production_guard_insert
  BEFORE INSERT ON tax_authority_federation_connections WHEN NEW.status='PRODUCTION_APPROVED' AND (
    NEW.environment<>'PRODUCTION' OR NEW.protocol NOT IN ('OIDC','SAML')
    OR NEW.issuer IS NULL OR NEW.audience IS NULL OR NEW.assurance_profile IS NULL
    OR NEW.metadata_hash IS NULL OR length(trim(NEW.metadata_hash))<32
    OR NEW.claims_contract_hash IS NULL OR length(trim(NEW.claims_contract_hash))<32
    OR NEW.reviewed_by IS NULL OR NEW.reviewed_by=NEW.requested_by
    OR NEW.checked_at IS NULL OR NEW.expires_at IS NULL OR datetime(NEW.expires_at)<=datetime(NEW.checked_at))
  BEGIN SELECT RAISE(ABORT,'TAX_AUTHORITY_FEDERATION_PRODUCTION_EVIDENCE_REQUIRED'); END;
--> statement-breakpoint
CREATE TRIGGER tax_authority_federation_production_guard_update
  BEFORE UPDATE OF status,environment,protocol,issuer,audience,metadata_hash,claims_contract_hash,assurance_profile,reviewed_by,checked_at,expires_at
  ON tax_authority_federation_connections WHEN NEW.status='PRODUCTION_APPROVED' AND (
    NEW.environment<>'PRODUCTION' OR NEW.protocol NOT IN ('OIDC','SAML')
    OR NEW.issuer IS NULL OR NEW.audience IS NULL OR NEW.assurance_profile IS NULL
    OR NEW.metadata_hash IS NULL OR length(trim(NEW.metadata_hash))<32
    OR NEW.claims_contract_hash IS NULL OR length(trim(NEW.claims_contract_hash))<32
    OR NEW.reviewed_by IS NULL OR NEW.reviewed_by=NEW.requested_by
    OR NEW.checked_at IS NULL OR NEW.expires_at IS NULL OR datetime(NEW.expires_at)<=datetime(NEW.checked_at))
  BEGIN SELECT RAISE(ABORT,'TAX_AUTHORITY_FEDERATION_PRODUCTION_EVIDENCE_REQUIRED'); END;
--> statement-breakpoint
CREATE TRIGGER tax_authority_federation_no_delete BEFORE DELETE ON tax_authority_federation_connections
  BEGIN SELECT RAISE(ABORT,'TAX_AUTHORITY_FEDERATION_HISTORY_IMMUTABLE'); END;
--> statement-breakpoint
CREATE TRIGGER tax_authority_onboarding_production_insert_guard
  BEFORE INSERT ON tax_authority_onboarding_cases
  WHEN NEW.target_environment='PRODUCTION' AND NEW.status<>'BLOCKED_EXTERNAL'
  BEGIN SELECT RAISE(ABORT,'TAX_AUTHORITY_PRODUCTION_ONBOARDING_DISABLED'); END;
--> statement-breakpoint
CREATE TRIGGER tax_authority_onboarding_identity_immutable
  BEFORE UPDATE OF tax_authority_id,target_environment,requested_by,submitted_at ON tax_authority_onboarding_cases
  BEGIN SELECT RAISE(ABORT,'TAX_AUTHORITY_ONBOARDING_IDENTITY_IMMUTABLE'); END;
--> statement-breakpoint
CREATE TRIGGER tax_authority_onboarding_no_delete BEFORE DELETE ON tax_authority_onboarding_cases
  BEGIN SELECT RAISE(ABORT,'TAX_AUTHORITY_ONBOARDING_HISTORY_IMMUTABLE'); END;
--> statement-breakpoint
CREATE TRIGGER tax_authority_onboarding_local_transition_guard
  BEFORE UPDATE OF status ON tax_authority_onboarding_cases
  WHEN NEW.status='LOCAL_STAGING_READY' AND (
    OLD.status NOT IN ('SUBMITTED','UNDER_REVIEW') OR NEW.target_environment<>'LOCAL_STAGING' OR NOT EXISTS (
      SELECT 1 FROM tax_authority_onboarding_decisions d
      WHERE d.onboarding_case_id=NEW.id AND d.decision_type='LOCAL_STAGING_APPROVAL' AND d.decision='APPROVE'))
  BEGIN SELECT RAISE(ABORT,'TAX_AUTHORITY_LOCAL_STAGING_APPROVAL_REQUIRED'); END;
--> statement-breakpoint
CREATE TRIGGER tax_authority_onboarding_rejection_guard
  BEFORE UPDATE OF status ON tax_authority_onboarding_cases
  WHEN NEW.status='REJECTED' AND NOT EXISTS (
    SELECT 1 FROM tax_authority_onboarding_decisions d
    WHERE d.onboarding_case_id=NEW.id AND d.decision_type='REJECTION' AND d.decision='REJECT')
  BEGIN SELECT RAISE(ABORT,'TAX_AUTHORITY_REJECTION_DECISION_REQUIRED'); END;
--> statement-breakpoint
CREATE TRIGGER tax_authority_production_activation_guard
  BEFORE UPDATE OF status ON tax_authority_onboarding_cases WHEN NEW.status='PRODUCTION_ACTIVATED' AND (
    NEW.target_environment<>'PRODUCTION' OR OLD.status<>'BLOCKED_EXTERNAL'
    OR NEW.evidence_bundle_hash IS NULL OR length(trim(NEW.evidence_bundle_hash))<32
    OR NEW.readiness_reference IS NULL OR length(trim(NEW.readiness_reference))<8 OR NEW.activated_at IS NULL
    OR NOT EXISTS (SELECT 1 FROM tax_authority_federation_connections f
      WHERE f.tax_authority_id=NEW.tax_authority_id AND f.environment='PRODUCTION' AND f.status='PRODUCTION_APPROVED'
        AND datetime(f.expires_at)>CURRENT_TIMESTAMP)
    OR NOT EXISTS (SELECT 1 FROM tax_authority_access_reviews review
      WHERE review.tax_authority_id=NEW.tax_authority_id AND review.review_type='QUARTERLY'
        AND review.status='COMPLETED' AND review.completed_by IS NOT NULL AND review.completed_at IS NOT NULL
        AND date(review.period_start)<=date('now') AND datetime(review.due_at)>=CURRENT_TIMESTAMP)
    OR (SELECT COUNT(DISTINCT d.decision_type) FROM tax_authority_onboarding_decisions d
      WHERE d.onboarding_case_id=NEW.id AND d.decision='APPROVE'
        AND d.decision_type IN ('SECURITY_APPROVAL','PRIVACY_APPROVAL','LEGAL_APPROVAL','INTEGRATION_APPROVAL','ACTIVATION_APPROVAL'))<>5
    OR (SELECT COUNT(DISTINCT d.decided_by) FROM tax_authority_onboarding_decisions d
      WHERE d.onboarding_case_id=NEW.id AND d.decision='APPROVE'
        AND d.decision_type IN ('SECURITY_APPROVAL','PRIVACY_APPROVAL','LEGAL_APPROVAL','INTEGRATION_APPROVAL','ACTIVATION_APPROVAL'))<>5)
  BEGIN SELECT RAISE(ABORT,'TAX_AUTHORITY_PRODUCTION_ACTIVATION_EVIDENCE_REQUIRED'); END;
--> statement-breakpoint
CREATE TRIGGER tax_authority_onboarding_decision_guard
  BEFORE INSERT ON tax_authority_onboarding_decisions
  BEGIN
    SELECT CASE WHEN length(trim(NEW.reason))<10 OR length(NEW.reason)>500
      OR length(trim(NEW.evidence_hash))<32 OR length(trim(NEW.step_up_evidence_reference))<16
      THEN RAISE(ABORT,'TAX_AUTHORITY_DECISION_EVIDENCE_INVALID') END;
    SELECT CASE WHEN (NEW.decision_type='REJECTION' AND NEW.decision<>'REJECT')
      OR (NEW.decision_type<>'REJECTION' AND NEW.decision<>'APPROVE')
      THEN RAISE(ABORT,'TAX_AUTHORITY_DECISION_TYPE_MISMATCH') END;
    SELECT CASE WHEN NOT EXISTS (
      SELECT 1 FROM tax_authority_onboarding_cases c
      JOIN tax_authority_administrators admin ON admin.tax_authority_id=c.tax_authority_id
      WHERE c.id=NEW.onboarding_case_id AND admin.user_id=NEW.decided_by AND admin.status='ACTIVE'
        AND datetime(admin.effective_from)<=CURRENT_TIMESTAMP
        AND (admin.effective_to IS NULL OR datetime(admin.effective_to)>CURRENT_TIMESTAMP)
    ) THEN RAISE(ABORT,'TAX_AUTHORITY_DECIDER_SCOPE_REQUIRED') END;
    SELECT CASE WHEN NOT EXISTS (
      SELECT 1 FROM tax_authority_onboarding_cases c
      JOIN tax_authority_role_assignments assignment ON assignment.tax_authority_id=c.tax_authority_id
      JOIN tax_authority_role_definitions role ON role.code=assignment.role_code
      WHERE c.id=NEW.onboarding_case_id AND assignment.user_id=NEW.decided_by AND assignment.status='ACTIVE'
        AND datetime(assignment.effective_from)<=CURRENT_TIMESTAMP
        AND (assignment.effective_to IS NULL OR datetime(assignment.effective_to)>CURRENT_TIMESTAMP)
        AND role.duty_class=CASE NEW.decision_type
          WHEN 'SECURITY_APPROVAL' THEN 'SECURITY_REVIEW' WHEN 'PRIVACY_APPROVAL' THEN 'PRIVACY_REVIEW'
          WHEN 'LEGAL_APPROVAL' THEN 'LEGAL_REVIEW' WHEN 'INTEGRATION_APPROVAL' THEN 'INTEGRATION_REVIEW'
          ELSE 'ACTIVATION_APPROVAL' END
    ) THEN RAISE(ABORT,'TAX_AUTHORITY_DECIDER_ROLE_REQUIRED') END;
    SELECT CASE WHEN NOT EXISTS (
      SELECT 1 FROM tax_authority_onboarding_cases c JOIN tax_authority_access_reviews review ON review.tax_authority_id=c.tax_authority_id
      WHERE c.id=NEW.onboarding_case_id AND review.review_type='QUARTERLY' AND review.status IN ('OPEN','COMPLETED')
        AND date(review.period_start)<=date('now') AND datetime(review.due_at)>=CURRENT_TIMESTAMP
    ) THEN RAISE(ABORT,'TAX_AUTHORITY_ACCESS_REVIEW_REQUIRED') END;
  END;
--> statement-breakpoint
CREATE TRIGGER tax_authority_onboarding_decision_no_update BEFORE UPDATE ON tax_authority_onboarding_decisions
  BEGIN SELECT RAISE(ABORT,'TAX_AUTHORITY_ONBOARDING_DECISION_IMMUTABLE'); END;
--> statement-breakpoint
CREATE TRIGGER tax_authority_onboarding_decision_no_delete BEFORE DELETE ON tax_authority_onboarding_decisions
  BEGIN SELECT RAISE(ABORT,'TAX_AUTHORITY_ONBOARDING_DECISION_IMMUTABLE'); END;
--> statement-breakpoint
CREATE TRIGGER tax_authority_governance_event_no_update BEFORE UPDATE ON tax_authority_governance_events
  BEGIN SELECT RAISE(ABORT,'TAX_AUTHORITY_GOVERNANCE_EVENT_IMMUTABLE'); END;
--> statement-breakpoint
CREATE TRIGGER tax_authority_governance_event_no_delete BEFORE DELETE ON tax_authority_governance_events
  BEGIN SELECT RAISE(ABORT,'TAX_AUTHORITY_GOVERNANCE_EVENT_IMMUTABLE'); END;
--> statement-breakpoint
CREATE TRIGGER tax_authority_access_review_no_delete BEFORE DELETE ON tax_authority_access_reviews
  BEGIN SELECT RAISE(ABORT,'TAX_AUTHORITY_ACCESS_REVIEW_IMMUTABLE'); END;
--> statement-breakpoint
INSERT OR REPLACE INTO app_schema_revisions (revision,applied_at,source)
VALUES ('issue4-authority-governance-2026-08-23',CURRENT_TIMESTAMP,'DRIZZLE_MIGRATION_0019');
