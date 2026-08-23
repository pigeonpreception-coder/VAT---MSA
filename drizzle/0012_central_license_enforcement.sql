CREATE TABLE `license_navigation_policies` (
	`navigation_item_id` text PRIMARY KEY NOT NULL,
	`operation_class` text NOT NULL,
	`created_at` text NOT NULL,
	`updated_at` text NOT NULL,
	FOREIGN KEY (`navigation_item_id`) REFERENCES `navigation_items`(`id`) ON UPDATE no action ON DELETE no action,
	CONSTRAINT "ck_license_navigation_operation" CHECK("license_navigation_policies"."operation_class" IN ('READ','EXPORT','BUSINESS_WRITE','COMPLIANCE_WRITE','CORRECTION_WRITE','ADMIN_WRITE'))
);
--> statement-breakpoint
CREATE TABLE `license_permission_policies` (
	`permission_code` text PRIMARY KEY NOT NULL,
	`feature_key` text NOT NULL,
	`operation_class` text NOT NULL,
	`status` text NOT NULL,
	`created_at` text NOT NULL,
	`updated_at` text NOT NULL,
	FOREIGN KEY (`permission_code`) REFERENCES `access_permissions`(`code`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`feature_key`) REFERENCES `license_features`(`feature_key`) ON UPDATE no action ON DELETE no action,
	CONSTRAINT "ck_license_permission_operation" CHECK("license_permission_policies"."operation_class" IN ('READ','EXPORT','BUSINESS_WRITE','COMPLIANCE_WRITE','CORRECTION_WRITE','ADMIN_WRITE')),
	CONSTRAINT "ck_license_permission_status" CHECK("license_permission_policies"."status" IN ('ACTIVE','RETIRED'))
);
--> statement-breakpoint
INSERT OR IGNORE INTO access_permissions VALUES
  ('dashboard:read','DASHBOARD','READ','Read the licensed organisation dashboard','INTERNAL','2026-08-23T08:00:00Z'),
  ('exceptions:read','RECONCILIATION_EXCEPTION','READ','Read authorised VAT reconciliation exceptions','RESTRICTED','2026-08-23T08:00:00Z');
--> statement-breakpoint
WITH policies(permission_code,feature_key,operation_class,status,created_at,updated_at) AS (VALUES
  ('dashboard:read','CORE_VAT','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('identity:read','ADMINISTRATION','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('taxpayers:read','ADMINISTRATION','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('registrations:read','ADMINISTRATION','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('registrations:submit','ADMINISTRATION','COMPLIANCE_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('organisations:manage','ADMINISTRATION','ADMIN_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('invoices:read','CORE_VAT','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('invoices:submit','CORE_VAT','BUSINESS_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('exceptions:read','CORE_VAT','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('returns:read','CORE_VAT','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('returns:generate','CORE_VAT','COMPLIANCE_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('returns:approve','CORE_VAT','COMPLIANCE_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('returns:submit','CORE_VAT','COMPLIANCE_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('vat-adjustments:manage','CORE_VAT','CORRECTION_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('reconciliation:manage','CORE_VAT','COMPLIANCE_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('audit:read','CORE_VAT','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('security:read','ADMINISTRATION','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('commercial:read','CORE_VAT','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('parties:manage','CORE_VAT','BUSINESS_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('quotations:manage','CORE_VAT','BUSINESS_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('accounting:read','ACCOUNTING','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('accounting:post','ACCOUNTING','BUSINESS_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('expenses:read','CORE_VAT','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('expenses:manage','CORE_VAT','BUSINESS_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('expenses:approve','CORE_VAT','BUSINESS_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('inventory:read','INVENTORY','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('inventory:manage','INVENTORY','BUSINESS_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('projects:read','PROJECTS','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('projects:manage','PROJECTS','BUSINESS_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('imports:read','CORE_VAT','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('imports:manage','CORE_VAT','BUSINESS_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('documents:read','CORE_VAT','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('documents:upload','CORE_VAT','BUSINESS_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('compliance:read','CORE_VAT','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('cases:manage','CORE_VAT','COMPLIANCE_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('disputes:manage','CORE_VAT','COMPLIANCE_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('refunds:read','CORE_VAT','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('refunds:request','CORE_VAT','COMPLIANCE_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('refunds:review','CORE_VAT','COMPLIANCE_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('risk:read','CORE_VAT','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('risk:review','CORE_VAT','COMPLIANCE_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('communications:manage','CORE_VAT','COMPLIANCE_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('consents:manage','CORE_VAT','COMPLIANCE_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('integrations:read','API_ACCESS','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('integrations:manage','API_ACCESS','BUSINESS_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('developer:read','API_ACCESS','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('developer:manage','API_ACCESS','BUSINESS_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('offline:read','CORE_VAT','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('offline:sync','CORE_VAT','BUSINESS_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('reports:read','ANALYTICS','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('reports:run','ANALYTICS','EXPORT','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('platform:read','API_ACCESS','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('platform:manage','API_ACCESS','ADMIN_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('payments:read','CORE_VAT','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('administration:read','ADMINISTRATION','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('administration:manage','ADMINISTRATION','ADMIN_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('workspace:read','ADMINISTRATION','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('search:read','ADMINISTRATION','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('licensing:read','ADMINISTRATION','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('licensing:request','ADMINISTRATION','ADMIN_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('employees:read','ADMINISTRATION','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('employees:manage','USER_SEATS','ADMIN_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('roles:read','ADMINISTRATION','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('roles:manage','ADMINISTRATION','ADMIN_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('workflows:read','ADVANCED_WORKFLOW','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('workflows:manage','ADVANCED_WORKFLOW','ADMIN_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('workflows:decide','ADVANCED_WORKFLOW','BUSINESS_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('access-governance:read','ADVANCED_WORKFLOW','READ','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'),
  ('access-governance:manage','ADVANCED_WORKFLOW','ADMIN_WRITE','ACTIVE','2026-08-23T08:00:00Z','2026-08-23T08:00:00Z'))
INSERT INTO license_permission_policies (permission_code,feature_key,operation_class,status,created_at,updated_at)
SELECT p.permission_code,p.feature_key,p.operation_class,p.status,p.created_at,p.updated_at
FROM policies p
JOIN access_permissions a ON a.code=p.permission_code
JOIN license_features f ON f.feature_key=p.feature_key;
--> statement-breakpoint
INSERT INTO license_navigation_policies (navigation_item_id,operation_class,created_at,updated_at)
SELECT id, CASE WHEN item_key='new-invoice' THEN 'BUSINESS_WRITE' ELSE 'READ' END, '2026-08-23T08:00:00Z', '2026-08-23T08:00:00Z'
FROM navigation_items;
