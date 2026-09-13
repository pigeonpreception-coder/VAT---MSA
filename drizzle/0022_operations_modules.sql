CREATE TABLE `fixed_assets` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`asset_class` text NOT NULL,
	`asset_code` text NOT NULL,
	`category` text NOT NULL,
	`description` text NOT NULL,
	`serial_or_registration_number` text,
	`location_or_address` text NOT NULL,
	`custodian_employee_id` text,
	`acquisition_date` text NOT NULL,
	`acquisition_cost_cents` integer NOT NULL,
	`current_value_cents` integer,
	`status` text NOT NULL,
	`disposal_reason` text,
	`disposed_at` text,
	`created_by` text NOT NULL,
	`created_at` text NOT NULL,
	`updated_at` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`custodian_employee_id`) REFERENCES `employees`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`created_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_fixed_assets_org_code` ON `fixed_assets` (`organisation_id`,`asset_code`);--> statement-breakpoint
CREATE INDEX `idx_fixed_assets_org_class_status` ON `fixed_assets` (`organisation_id`,`asset_class`,`status`);--> statement-breakpoint
CREATE TABLE `logistics_deliveries` (
	`id` text PRIMARY KEY NOT NULL,
	`organisation_id` text NOT NULL,
	`delivery_number` text NOT NULL,
	`reference_type` text NOT NULL,
	`reference_id` text,
	`origin` text NOT NULL,
	`destination` text NOT NULL,
	`vehicle_asset_id` text,
	`status` text NOT NULL,
	`notes` text,
	`dispatched_at` text,
	`delivered_at` text,
	`cancelled_at` text,
	`cancellation_reason` text,
	`created_by` text NOT NULL,
	`created_at` text NOT NULL,
	`updated_at` text NOT NULL,
	FOREIGN KEY (`organisation_id`) REFERENCES `organisations`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`vehicle_asset_id`) REFERENCES `fixed_assets`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`created_by`) REFERENCES `app_users`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `ux_logistics_deliveries_org_number` ON `logistics_deliveries` (`organisation_id`,`delivery_number`);--> statement-breakpoint
CREATE INDEX `idx_logistics_deliveries_org_status` ON `logistics_deliveries` (`organisation_id`,`status`);
--> statement-breakpoint
INSERT OR IGNORE INTO access_permissions (code,resource,action,description,classification,created_at) VALUES
  ('fixed-assets:read','FIXED_ASSET','READ','Read immovable and movable fixed asset records','CONFIDENTIAL','2026-09-12T00:00:00Z'),
  ('fixed-assets:manage','FIXED_ASSET','MANAGE','Register, update and dispose immovable and movable fixed asset records','CONFIDENTIAL','2026-09-12T00:00:00Z'),
  ('logistics:read','LOGISTICS_DELIVERY','READ','Read logistics delivery records','CONFIDENTIAL','2026-09-12T00:00:00Z'),
  ('logistics:manage','LOGISTICS_DELIVERY','MANAGE','Create, dispatch, deliver and cancel logistics delivery records','CONFIDENTIAL','2026-09-12T00:00:00Z');
--> statement-breakpoint
WITH policies(permission_code,feature_key,operation_class,status,created_at,updated_at) AS (VALUES
  ('fixed-assets:read','BUSINESS_OPERATIONS','READ','ACTIVE','2026-09-12T00:00:00Z','2026-09-12T00:00:00Z'),
  ('fixed-assets:manage','BUSINESS_OPERATIONS','BUSINESS_WRITE','ACTIVE','2026-09-12T00:00:00Z','2026-09-12T00:00:00Z'),
  ('logistics:read','BUSINESS_OPERATIONS','READ','ACTIVE','2026-09-12T00:00:00Z','2026-09-12T00:00:00Z'),
  ('logistics:manage','BUSINESS_OPERATIONS','BUSINESS_WRITE','ACTIVE','2026-09-12T00:00:00Z','2026-09-12T00:00:00Z'))
INSERT OR IGNORE INTO license_permission_policies (permission_code,feature_key,operation_class,status,created_at,updated_at)
SELECT p.permission_code,p.feature_key,p.operation_class,p.status,p.created_at,p.updated_at
FROM policies p
JOIN access_permissions a ON a.code=p.permission_code
JOIN license_features f ON f.feature_key=p.feature_key;