ALTER TABLE `license_plans` ADD `plan_domain` text NOT NULL DEFAULT 'COMMERCIAL_SAAS' CHECK (`plan_domain` IN ('COMMERCIAL_SAAS','GOVERNMENT_TAX'));--> statement-breakpoint
ALTER TABLE `license_features` ADD `authority_domain` text NOT NULL DEFAULT 'COMMERCIAL_SAAS' CHECK (`authority_domain` IN ('COMMERCIAL_SAAS','GOVERNMENT_TAX','PLATFORM_CONTROL'));--> statement-breakpoint
ALTER TABLE `license_plan_entitlements` ADD `capacity_mode` text NOT NULL DEFAULT 'NOT_APPLICABLE' CHECK (`capacity_mode` IN ('FINITE','UNLIMITED','NOT_APPLICABLE'));--> statement-breakpoint
ALTER TABLE `subscriptions` ADD `subscription_domain` text NOT NULL DEFAULT 'COMMERCIAL_SAAS' CHECK (`subscription_domain`='COMMERCIAL_SAAS');--> statement-breakpoint
ALTER TABLE `subscriptions` ADD `payment_mode` text NOT NULL DEFAULT 'DISABLED' CHECK (`payment_mode` IN ('DISABLED','SANDBOX','APPROVED_PROVIDER'));--> statement-breakpoint
ALTER TABLE `self_serve_signup_applications` ADD `onboarding_path` text NOT NULL DEFAULT 'COMPANY_ADMIN' CHECK (`onboarding_path`='COMPANY_ADMIN');--> statement-breakpoint

UPDATE `license_features` SET `authority_domain`='GOVERNMENT_TAX' WHERE `feature_key`='CORE_VAT';--> statement-breakpoint
UPDATE `license_plan_entitlements` SET `capacity_mode`='FINITE' WHERE `limit_value` IS NOT NULL;--> statement-breakpoint
DELETE FROM `license_plan_entitlements` WHERE `feature_key`='CORE_VAT' AND `license_plan_id` IN (SELECT id FROM `license_plans` WHERE `plan_domain`='COMMERCIAL_SAAS');--> statement-breakpoint
INSERT OR IGNORE INTO `license_features` (`feature_key`,`name`,`description`,`metric_key`,`protected`,`created_at`,`authority_domain`)
VALUES ('BUSINESS_OPERATIONS','Business operations','Expenses quotations parties imports and business documents',NULL,1,'2026-08-23T12:00:00Z','COMMERCIAL_SAAS'),
       ('PLATFORM_SECURITY','Platform control','Global platform security and operational control',NULL,1,'2026-08-23T12:00:00Z','PLATFORM_CONTROL');--> statement-breakpoint
INSERT OR IGNORE INTO `license_plans` (`id`,`code`,`name`,`version`,`status`,`effective_from`,`effective_to`,`created_at`,`plan_domain`)
VALUES ('plan-tax-na-synthetic-v1','NA_GOVERNMENT_TAX','Namibia Government Tax Services',1,'ACTIVE','2026-08-01T00:00:00Z',NULL,'2026-08-23T12:00:00Z','GOVERNMENT_TAX');--> statement-breakpoint
INSERT OR IGNORE INTO `license_plan_entitlements` (`id`,`license_plan_id`,`feature_key`,`enabled`,`limit_value`,`configuration`,`capacity_mode`)
SELECT 'ent-tax-core','plan-tax-na-synthetic-v1','CORE_VAT',1,NULL,'{}','NOT_APPLICABLE'
WHERE EXISTS (SELECT 1 FROM `license_features` WHERE `feature_key`='CORE_VAT');--> statement-breakpoint
INSERT OR IGNORE INTO `license_plan_entitlements` (`id`,`license_plan_id`,`feature_key`,`enabled`,`limit_value`,`configuration`,`capacity_mode`)
SELECT 'ent-business',id,'BUSINESS_OPERATIONS',1,NULL,'{}','NOT_APPLICABLE' FROM `license_plans` WHERE `code`='PILOT_PROFESSIONAL' AND `plan_domain`='COMMERCIAL_SAAS';--> statement-breakpoint
UPDATE `license_permission_policies` SET `feature_key`='CORE_VAT' WHERE `permission_code` IN
  ('identity:read','taxpayers:read','registrations:read','registrations:submit','organisations:manage','integrations:read','integrations:manage','reports:read','reports:run');--> statement-breakpoint
UPDATE `license_permission_policies` SET `feature_key`='BUSINESS_OPERATIONS' WHERE `permission_code` IN
  ('commercial:read','parties:manage','quotations:manage','expenses:read','expenses:manage','expenses:approve','imports:read','imports:manage','documents:read','documents:upload');--> statement-breakpoint
UPDATE `license_permission_policies` SET `feature_key`='PLATFORM_SECURITY' WHERE `permission_code` IN
  ('security:read','platform:read','platform:manage');--> statement-breakpoint

CREATE TABLE `countries` (
  `code` text PRIMARY KEY NOT NULL, `iso3_code` text NOT NULL UNIQUE, `name` text NOT NULL,
  `currency_code` text NOT NULL, `status` text NOT NULL, `created_at` text NOT NULL
);--> statement-breakpoint
CREATE TABLE `tax_jurisdictions` (
  `id` text PRIMARY KEY NOT NULL, `country_code` text NOT NULL REFERENCES `countries`(`code`),
  `code` text NOT NULL, `name` text NOT NULL, `status` text NOT NULL, `created_at` text NOT NULL,
  UNIQUE (`country_code`,`code`)
);--> statement-breakpoint
CREATE TABLE `tax_authorities` (
  `id` text PRIMARY KEY NOT NULL, `jurisdiction_id` text NOT NULL REFERENCES `tax_jurisdictions`(`id`),
  `code` text NOT NULL, `name` text NOT NULL, `status` text NOT NULL, `created_at` text NOT NULL,
  UNIQUE (`jurisdiction_id`,`code`)
);--> statement-breakpoint
CREATE TABLE `tax_authority_administrators` (
  `id` text PRIMARY KEY NOT NULL, `tax_authority_id` text NOT NULL REFERENCES `tax_authorities`(`id`),
  `user_id` text NOT NULL REFERENCES `app_users`(`id`), `status` text NOT NULL, `effective_from` text NOT NULL,
  `effective_to` text, `appointed_by` text NOT NULL, `approval_reference` text NOT NULL,
  UNIQUE (`tax_authority_id`,`user_id`)
);--> statement-breakpoint
CREATE TABLE `tax_authority_users` (
  `id` text PRIMARY KEY NOT NULL, `tax_authority_id` text NOT NULL REFERENCES `tax_authorities`(`id`),
  `user_id` text NOT NULL REFERENCES `app_users`(`id`), `authority_role` text NOT NULL, `status` text NOT NULL,
  `effective_from` text NOT NULL, `effective_to` text, UNIQUE (`tax_authority_id`,`user_id`,`authority_role`)
);--> statement-breakpoint
CREATE TABLE `tax_subscriptions` (
  `id` text PRIMARY KEY NOT NULL, `tax_authority_id` text NOT NULL REFERENCES `tax_authorities`(`id`),
  `license_plan_id` text NOT NULL REFERENCES `license_plans`(`id`), `status` text NOT NULL,
  `environment` text NOT NULL, `effective_from` text NOT NULL, `effective_to` text,
  `activation_authority` text NOT NULL, `created_at` text NOT NULL
);--> statement-breakpoint
CREATE TABLE `tax_subscription_features` (
  `id` text PRIMARY KEY NOT NULL, `tax_subscription_id` text NOT NULL REFERENCES `tax_subscriptions`(`id`),
  `feature_key` text NOT NULL REFERENCES `license_features`(`feature_key`), `status` text NOT NULL,
  `created_at` text NOT NULL, UNIQUE (`tax_subscription_id`,`feature_key`)
);--> statement-breakpoint
CREATE TABLE `taxpayer_authorizations` (
  `id` text PRIMARY KEY NOT NULL, `tax_subscription_id` text NOT NULL REFERENCES `tax_subscriptions`(`id`),
  `tax_authority_id` text NOT NULL REFERENCES `tax_authorities`(`id`),
  `jurisdiction_id` text NOT NULL REFERENCES `tax_jurisdictions`(`id`),
  `organisation_id` text NOT NULL REFERENCES `organisations`(`id`), `taxpayer_id` text NOT NULL REFERENCES `taxpayers`(`id`),
  `status` text NOT NULL CHECK (`status` IN ('PENDING','ACTIVE','SUSPENDED','REVOKED')),
  `vat_registration_status` text NOT NULL, `effective_from` text NOT NULL, `effective_to` text,
  `authorization_reference` text NOT NULL UNIQUE, `authorized_by` text NOT NULL REFERENCES `app_users`(`id`), `created_at` text NOT NULL
);--> statement-breakpoint
CREATE TABLE `taxpayer_authorization_decisions` (
  `id` text PRIMARY KEY NOT NULL, `taxpayer_authorization_id` text NOT NULL REFERENCES `taxpayer_authorizations`(`id`),
  `decision` text NOT NULL CHECK (`decision` IN ('AUTHORIZE','SUSPEND','REINSTATE','REVOKE')),
  `reason` text NOT NULL, `requested_by` text NOT NULL REFERENCES `app_users`(`id`),
  `decided_by` text NOT NULL REFERENCES `app_users`(`id`), `step_up_evidence_reference` text NOT NULL,
  `occurred_at` text NOT NULL, CHECK (`requested_by`<>`decided_by`)
);--> statement-breakpoint
CREATE TABLE `license_capacity_exceptions` (
  `id` text PRIMARY KEY NOT NULL, `organisation_license_id` text NOT NULL REFERENCES `organisation_licenses`(`id`),
  `organisation_id` text NOT NULL REFERENCES `organisations`(`id`), `active_users` integer NOT NULL,
  `licensed_capacity` integer NOT NULL, `status` text NOT NULL CHECK (`status` IN ('OPEN','RESOLVED','SUPERSEDED')),
  `reason` text NOT NULL, `opened_at` text NOT NULL, `resolved_at` text
);--> statement-breakpoint
CREATE TABLE `user_invitations` (
  `id` text PRIMARY KEY NOT NULL, `organisation_id` text NOT NULL REFERENCES `organisations`(`id`),
  `employee_id` text NOT NULL REFERENCES `employees`(`id`) UNIQUE, `invited_by` text NOT NULL REFERENCES `app_users`(`id`),
  `recipient_email_hash` text NOT NULL, `token_hash` text, `status` text NOT NULL,
  `expires_at` text NOT NULL, `created_at` text NOT NULL, `accepted_at` text, `revoked_at` text
);--> statement-breakpoint
CREATE VIEW `commercial_subscriptions` AS SELECT * FROM `subscriptions` WHERE `subscription_domain`='COMMERCIAL_SAAS';--> statement-breakpoint
CREATE VIEW `system_administrators` AS SELECT * FROM `organisation_administrators` WHERE `status`='ACTIVE';--> statement-breakpoint

CREATE INDEX `idx_tax_subscription_authority_status` ON `tax_subscriptions` (`tax_authority_id`,`status`);--> statement-breakpoint
CREATE INDEX `idx_taxpayer_authorization_scope_status` ON `taxpayer_authorizations` (`tax_authority_id`,`organisation_id`,`status`);--> statement-breakpoint
CREATE INDEX `idx_license_capacity_exception_status` ON `license_capacity_exceptions` (`organisation_id`,`status`);--> statement-breakpoint
CREATE INDEX `idx_user_invitation_status_expiry` ON `user_invitations` (`organisation_id`,`status`,`expires_at`);--> statement-breakpoint

DROP TRIGGER IF EXISTS `validate_self_serve_signup_insert`;--> statement-breakpoint
CREATE TRIGGER `validate_self_serve_signup_insert` BEFORE INSERT ON `self_serve_signup_applications`
BEGIN
  SELECT CASE WHEN NOT EXISTS (
    SELECT 1 FROM `license_plans` p WHERE p.id=NEW.requested_plan_id AND p.status='ACTIVE' AND p.plan_domain='COMMERCIAL_SAAS'
      AND datetime(p.effective_from)<=CURRENT_TIMESTAMP AND (p.effective_to IS NULL OR datetime(p.effective_to)>CURRENT_TIMESTAMP)
  ) THEN RAISE(ABORT,'SELF_SERVE_SIGNUP_PLAN_UNAVAILABLE') END;
  SELECT CASE WHEN NEW.onboarding_path<>'COMPANY_ADMIN' THEN RAISE(ABORT,'COMPANY_ADMIN_AUTHORITY_REQUIRED') END;
  SELECT CASE WHEN EXISTS (SELECT 1 FROM `taxpayers` t WHERE t.vat_number=NEW.vat_number OR t.tin=NEW.tin)
    THEN RAISE(ABORT,'SELF_SERVE_SIGNUP_CANONICAL_TAXPAYER_EXISTS') END;
  SELECT CASE WHEN EXISTS (SELECT 1 FROM `registration_applications` r WHERE (r.vat_number=NEW.vat_number OR r.tin=NEW.tin)
    AND r.status IN ('PENDING_VERIFICATION','UNDER_REVIEW','VERIFIED'))
    THEN RAISE(ABORT,'SELF_SERVE_SIGNUP_CONTROLLED_REGISTRATION_EXISTS') END;
END;--> statement-breakpoint
DROP TRIGGER IF EXISTS `prevent_self_serve_signup_input_update`;--> statement-breakpoint
CREATE TRIGGER `prevent_self_serve_signup_input_update` BEFORE UPDATE OF id,public_reference,idempotency_key,request_hash,
  applicant_name,applicant_role,contact_email,identity_provider,identity_subject_hash,onboarding_path,country_code,requested_plan_id,
  vat_number,tin,company_registration_number,legal_name,trading_name,taxpayer_type,return_frequency,address,terms_version,
  privacy_notice_version,authority_attested_at,terms_accepted_at,privacy_notice_accepted_at,licence_status,submitted_at
ON `self_serve_signup_applications` BEGIN SELECT RAISE(ABORT,'SELF_SERVE_SIGNUP_INPUT_IMMUTABLE'); END;--> statement-breakpoint
CREATE TRIGGER `enforce_plan_feature_authority_insert` BEFORE INSERT ON `license_plan_entitlements`
BEGIN
  SELECT CASE WHEN NOT EXISTS (SELECT 1 FROM `license_plans` p JOIN `license_features` f ON p.plan_domain=f.authority_domain
    WHERE p.id=NEW.license_plan_id AND f.feature_key=NEW.feature_key AND f.authority_domain IN ('COMMERCIAL_SAAS','GOVERNMENT_TAX'))
  THEN RAISE(ABORT,'PLAN_FEATURE_AUTHORITY_DOMAIN_MISMATCH') END;
  SELECT CASE WHEN NEW.feature_key='USER_SEATS' AND NEW.capacity_mode NOT IN ('FINITE','UNLIMITED')
    THEN RAISE(ABORT,'USER_SEATS_CAPACITY_MODE_INVALID') END;
  SELECT CASE WHEN NOT ((NEW.capacity_mode='FINITE' AND NEW.limit_value IS NOT NULL AND NEW.limit_value>0)
    OR (NEW.capacity_mode IN ('UNLIMITED','NOT_APPLICABLE') AND NEW.limit_value IS NULL))
  THEN RAISE(ABORT,'ENTITLEMENT_CAPACITY_CONFIGURATION_INVALID') END;
END;--> statement-breakpoint
CREATE TRIGGER `enforce_plan_feature_authority_update` BEFORE UPDATE OF license_plan_id,feature_key,capacity_mode,limit_value ON `license_plan_entitlements`
BEGIN
  SELECT CASE WHEN NOT EXISTS (SELECT 1 FROM `license_plans` p JOIN `license_features` f ON p.plan_domain=f.authority_domain
    WHERE p.id=NEW.license_plan_id AND f.feature_key=NEW.feature_key AND f.authority_domain IN ('COMMERCIAL_SAAS','GOVERNMENT_TAX'))
  THEN RAISE(ABORT,'PLAN_FEATURE_AUTHORITY_DOMAIN_MISMATCH') END;
  SELECT CASE WHEN NEW.feature_key='USER_SEATS' AND NEW.capacity_mode NOT IN ('FINITE','UNLIMITED')
    THEN RAISE(ABORT,'USER_SEATS_CAPACITY_MODE_INVALID') END;
  SELECT CASE WHEN NOT ((NEW.capacity_mode='FINITE' AND NEW.limit_value IS NOT NULL AND NEW.limit_value>0)
    OR (NEW.capacity_mode IN ('UNLIMITED','NOT_APPLICABLE') AND NEW.limit_value IS NULL))
  THEN RAISE(ABORT,'ENTITLEMENT_CAPACITY_CONFIGURATION_INVALID') END;
END;--> statement-breakpoint
CREATE TRIGGER `enforce_commercial_license_domain` BEFORE INSERT ON `organisation_licenses`
WHEN NOT EXISTS (SELECT 1 FROM `license_plans` p JOIN `subscriptions` s ON s.id=NEW.subscription_id
  WHERE p.id=NEW.license_plan_id AND p.plan_domain='COMMERCIAL_SAAS' AND s.subscription_domain='COMMERCIAL_SAAS' AND s.organisation_id=NEW.organisation_id)
BEGIN SELECT RAISE(ABORT,'COMMERCIAL_LICENSE_AUTHORITY_DOMAIN_MISMATCH'); END;--> statement-breakpoint
CREATE TRIGGER `enforce_commercial_license_domain_update` BEFORE UPDATE OF organisation_id,subscription_id,license_plan_id ON `organisation_licenses`
WHEN NOT EXISTS (SELECT 1 FROM `license_plans` p JOIN `subscriptions` s ON s.id=NEW.subscription_id
  WHERE p.id=NEW.license_plan_id AND p.plan_domain='COMMERCIAL_SAAS' AND s.subscription_domain='COMMERCIAL_SAAS' AND s.organisation_id=NEW.organisation_id)
BEGIN SELECT RAISE(ABORT,'COMMERCIAL_LICENSE_AUTHORITY_DOMAIN_MISMATCH'); END;--> statement-breakpoint
CREATE TRIGGER `open_capacity_exception_on_entitlement_reduction` AFTER UPDATE OF capacity_mode,limit_value ON `license_plan_entitlements`
WHEN NEW.feature_key='USER_SEATS' AND NEW.capacity_mode='FINITE' AND EXISTS (
  SELECT 1 FROM `organisation_licenses` ol WHERE ol.license_plan_id=NEW.license_plan_id
    AND (SELECT COUNT(*) FROM `employees` e WHERE e.organisation_id=ol.organisation_id AND e.status IN ('ACTIVE','INVITED'))>NEW.limit_value)
BEGIN
  INSERT INTO `license_capacity_exceptions` (id,organisation_license_id,organisation_id,active_users,licensed_capacity,status,reason,opened_at,resolved_at)
  SELECT 'capex-'||ol.id||'-'||ol.state_version,ol.id,ol.organisation_id,
    (SELECT COUNT(*) FROM `employees` e WHERE e.organisation_id=ol.organisation_id AND e.status IN ('ACTIVE','INVITED')),
    NEW.limit_value,'OPEN','Activated finite capacity is below current non-destructive user usage',CURRENT_TIMESTAMP,NULL
  FROM `organisation_licenses` ol WHERE ol.license_plan_id=NEW.license_plan_id
    AND (SELECT COUNT(*) FROM `employees` e WHERE e.organisation_id=ol.organisation_id AND e.status IN ('ACTIVE','INVITED'))>NEW.limit_value
    AND NOT EXISTS (SELECT 1 FROM `license_capacity_exceptions` x WHERE x.organisation_license_id=ol.id AND x.status='OPEN');
END;--> statement-breakpoint
CREATE TRIGGER `open_capacity_exception_on_plan_downgrade` AFTER UPDATE OF license_plan_id ON `organisation_licenses`
WHEN EXISTS (SELECT 1 FROM `license_plan_entitlements` pe WHERE pe.license_plan_id=NEW.license_plan_id
  AND pe.feature_key='USER_SEATS' AND pe.enabled=1 AND pe.capacity_mode='FINITE'
  AND (SELECT COUNT(*) FROM `employees` e WHERE e.organisation_id=NEW.organisation_id AND e.status IN ('ACTIVE','INVITED'))>pe.limit_value)
BEGIN
  INSERT INTO `license_capacity_exceptions` (id,organisation_license_id,organisation_id,active_users,licensed_capacity,status,reason,opened_at,resolved_at)
  SELECT 'capex-'||NEW.id||'-'||NEW.state_version,NEW.id,NEW.organisation_id,
    (SELECT COUNT(*) FROM `employees` e WHERE e.organisation_id=NEW.organisation_id AND e.status IN ('ACTIVE','INVITED')),
    pe.limit_value,'OPEN','Activated downgrade is below current non-destructive user usage',CURRENT_TIMESTAMP,NULL
  FROM `license_plan_entitlements` pe WHERE pe.license_plan_id=NEW.license_plan_id AND pe.feature_key='USER_SEATS'
    AND pe.enabled=1 AND pe.capacity_mode='FINITE'
    AND NOT EXISTS (SELECT 1 FROM `license_capacity_exceptions` x WHERE x.organisation_license_id=NEW.id AND x.status='OPEN');
END;--> statement-breakpoint
CREATE TRIGGER `enforce_tax_subscription_plan_domain` BEFORE INSERT ON `tax_subscriptions`
WHEN NOT EXISTS (SELECT 1 FROM `license_plans` p WHERE p.id=NEW.license_plan_id AND p.plan_domain='GOVERNMENT_TAX')
BEGIN SELECT RAISE(ABORT,'TAX_SUBSCRIPTION_PLAN_DOMAIN_MISMATCH'); END;--> statement-breakpoint
CREATE TRIGGER `enforce_tax_subscription_feature_domain` BEFORE INSERT ON `tax_subscription_features`
WHEN NOT EXISTS (SELECT 1 FROM `license_features` f WHERE f.feature_key=NEW.feature_key AND f.authority_domain='GOVERNMENT_TAX')
BEGIN SELECT RAISE(ABORT,'TAX_SUBSCRIPTION_FEATURE_DOMAIN_MISMATCH'); END;--> statement-breakpoint
CREATE TRIGGER `enforce_taxpayer_authorization_scope` BEFORE INSERT ON `taxpayer_authorizations`
WHEN NOT EXISTS (SELECT 1 FROM `tax_subscriptions` ts JOIN `tax_authorities` ta ON ta.id=ts.tax_authority_id
  JOIN `organisations` o ON o.id=NEW.organisation_id WHERE ts.id=NEW.tax_subscription_id
  AND ts.tax_authority_id=NEW.tax_authority_id AND ta.jurisdiction_id=NEW.jurisdiction_id AND o.taxpayer_id=NEW.taxpayer_id)
BEGIN SELECT RAISE(ABORT,'TAXPAYER_AUTHORIZATION_SCOPE_MISMATCH'); END;--> statement-breakpoint
CREATE TRIGGER `prevent_taxpayer_authorization_decision_update` BEFORE UPDATE ON `taxpayer_authorization_decisions`
BEGIN SELECT RAISE(ABORT,'TAXPAYER_AUTHORIZATION_DECISION_IMMUTABLE'); END;--> statement-breakpoint
CREATE TRIGGER `prevent_taxpayer_authorization_decision_delete` BEFORE DELETE ON `taxpayer_authorization_decisions`
BEGIN SELECT RAISE(ABORT,'TAXPAYER_AUTHORIZATION_DECISION_IMMUTABLE'); END;--> statement-breakpoint

DROP TRIGGER IF EXISTS `enforce_employee_seat_limit_insert`;--> statement-breakpoint
DROP TRIGGER IF EXISTS `enforce_employee_seat_limit_update`;--> statement-breakpoint
CREATE TRIGGER `enforce_employee_seat_limit_insert` BEFORE INSERT ON `employees` WHEN NEW.status IN ('ACTIVE','INVITED')
BEGIN
  SELECT CASE WHEN COALESCE((SELECT pe.capacity_mode FROM `organisation_licenses` ol JOIN `license_plans` lp ON lp.id=ol.license_plan_id AND lp.plan_domain='COMMERCIAL_SAAS'
    JOIN `license_plan_entitlements` pe ON pe.license_plan_id=ol.license_plan_id AND pe.feature_key='USER_SEATS' AND pe.enabled=1
    WHERE ol.organisation_id=NEW.organisation_id ORDER BY ol.effective_from DESC LIMIT 1),'MISSING')='MISSING'
    THEN RAISE(ABORT,'COMMERCIAL_USER_SEAT_ENTITLEMENT_REQUIRED') END;
  SELECT CASE WHEN COALESCE((SELECT pe.capacity_mode FROM `organisation_licenses` ol JOIN `license_plan_entitlements` pe ON pe.license_plan_id=ol.license_plan_id
    AND pe.feature_key='USER_SEATS' AND pe.enabled=1 WHERE ol.organisation_id=NEW.organisation_id ORDER BY ol.effective_from DESC LIMIT 1),'MISSING')='FINITE'
    AND (SELECT COUNT(*) FROM `employees` e WHERE e.organisation_id=NEW.organisation_id AND e.status IN ('ACTIVE','INVITED')) >=
      (SELECT pe.limit_value FROM `organisation_licenses` ol JOIN `license_plan_entitlements` pe ON pe.license_plan_id=ol.license_plan_id
       AND pe.feature_key='USER_SEATS' AND pe.enabled=1 WHERE ol.organisation_id=NEW.organisation_id ORDER BY ol.effective_from DESC LIMIT 1)
    THEN RAISE(ABORT,'USER_LICENSE_LIMIT_REACHED') END;
END;--> statement-breakpoint
CREATE TRIGGER `enforce_employee_seat_limit_update` BEFORE UPDATE OF status,organisation_id ON `employees`
WHEN NEW.status IN ('ACTIVE','INVITED') AND OLD.status NOT IN ('ACTIVE','INVITED')
BEGIN
  SELECT CASE WHEN COALESCE((SELECT pe.capacity_mode FROM `organisation_licenses` ol JOIN `license_plans` lp ON lp.id=ol.license_plan_id AND lp.plan_domain='COMMERCIAL_SAAS'
    JOIN `license_plan_entitlements` pe ON pe.license_plan_id=ol.license_plan_id AND pe.feature_key='USER_SEATS' AND pe.enabled=1
    WHERE ol.organisation_id=NEW.organisation_id ORDER BY ol.effective_from DESC LIMIT 1),'MISSING')='MISSING'
    THEN RAISE(ABORT,'COMMERCIAL_USER_SEAT_ENTITLEMENT_REQUIRED') END;
  SELECT CASE WHEN COALESCE((SELECT pe.capacity_mode FROM `organisation_licenses` ol JOIN `license_plan_entitlements` pe ON pe.license_plan_id=ol.license_plan_id
    AND pe.feature_key='USER_SEATS' AND pe.enabled=1 WHERE ol.organisation_id=NEW.organisation_id ORDER BY ol.effective_from DESC LIMIT 1),'MISSING')='FINITE'
    AND (SELECT COUNT(*) FROM `employees` e WHERE e.organisation_id=NEW.organisation_id AND e.status IN ('ACTIVE','INVITED')) >=
      (SELECT pe.limit_value FROM `organisation_licenses` ol JOIN `license_plan_entitlements` pe ON pe.license_plan_id=ol.license_plan_id
       AND pe.feature_key='USER_SEATS' AND pe.enabled=1 WHERE ol.organisation_id=NEW.organisation_id ORDER BY ol.effective_from DESC LIMIT 1)
    THEN RAISE(ABORT,'USER_LICENSE_LIMIT_REACHED') END;
END;
