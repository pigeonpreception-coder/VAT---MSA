CREATE TABLE `taxpayer_system_registrations` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`taxpayer_id` text NOT NULL,
	`vat_registration_number` text NOT NULL,
	`tin` text,
	`company_registration_number` text,
	`system_name` text NOT NULL,
	`system_vendor` text NOT NULL,
	`system_category` text NOT NULL,
	`credential_reference` text,
	`api_status` text NOT NULL,
	`registration_status` text NOT NULL,
	`security_status` text NOT NULL,
	`last_synchronization_at` text,
	`created_by` text NOT NULL,
	`created_at` text NOT NULL,
	`updated_at` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`taxpayer_id`) REFERENCES `taxpayers`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`created_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_taxpayer_system_org_name_vendor` ON `taxpayer_system_registrations` (`organisation_id`,`system_name`,`system_vendor`);
--> statement-breakpoint
INSERT OR IGNORE INTO access_permissions (code,resource,action,description,classification,created_at) VALUES
  ('taxpayer-systems:read','TAXPAYER_SYSTEM','READ','Read registered taxpayer ERP/POS/accounting system registrations','RESTRICTED','2026-09-12T00:00:00Z'),
  ('taxpayer-systems:manage','TAXPAYER_SYSTEM','MANAGE','Register, suspend and record synchronisation state for a taxpayer''s own ERP/POS/accounting system','RESTRICTED','2026-09-12T00:00:00Z'),
  ('taxpayer-systems:approve','TAXPAYER_SYSTEM','APPROVE','Approve a taxpayer''s registered system for NamRA e-VAT MS data exchange','RESTRICTED','2026-09-12T00:00:00Z');
--> statement-breakpoint
WITH policies(permission_code,feature_key,operation_class,status,created_at,updated_at) AS (VALUES
  ('taxpayer-systems:read','CORE_VAT','READ','ACTIVE','2026-09-12T00:00:00Z','2026-09-12T00:00:00Z'),
  ('taxpayer-systems:manage','CORE_VAT','BUSINESS_WRITE','ACTIVE','2026-09-12T00:00:00Z','2026-09-12T00:00:00Z'),
  ('taxpayer-systems:approve','CORE_VAT','COMPLIANCE_WRITE','ACTIVE','2026-09-12T00:00:00Z','2026-09-12T00:00:00Z'))
INSERT OR IGNORE INTO license_permission_policies (permission_code,feature_key,operation_class,status,created_at,updated_at)
SELECT p.permission_code,p.feature_key,p.operation_class,p.status,p.created_at,p.updated_at
FROM policies p
JOIN access_permissions a ON a.code=p.permission_code
JOIN license_features f ON f.feature_key=p.feature_key;