INSERT OR IGNORE INTO access_permissions (code,resource,action,description,classification,created_at) VALUES
  ('taxpayers:suspend','TAXPAYER','SUSPEND','Suspend a taxpayer''s VAT registration','RESTRICTED','2026-09-08T00:00:00Z'),
  ('registrations:approve','REGISTRATION','APPROVE','Approve a pending taxpayer registration application','RESTRICTED','2026-09-08T00:00:00Z'),
  ('invoices:cancel','INVOICE','CANCEL','Cancel a certified invoice','RESTRICTED','2026-09-08T00:00:00Z'),
  ('vat-rules:read','VAT_RULE','READ','Read the VAT rule catalogue','INTERNAL','2026-09-08T00:00:00Z'),
  ('vat-rules:manage','VAT_RULE','MANAGE','Propose and approve VAT rules','RESTRICTED','2026-09-08T00:00:00Z'),
  ('cases:override-sod','COMPLIANCE_CASE','OVERRIDE_SOD','Override segregation-of-duties on a compliance case','SECURITY','2026-09-08T00:00:00Z'),
  ('obligations:manage','COMPLIANCE_OBLIGATION','MANAGE','Create and satisfy compliance obligations','RESTRICTED','2026-09-08T00:00:00Z'),
  ('notifications:manage','NOTIFICATION','MANAGE','Queue and manage notifications','INTERNAL','2026-09-08T00:00:00Z'),
  ('reports:executive','REPORT','READ_EXECUTIVE','Read executive-tier restricted reports','RESTRICTED','2026-09-08T00:00:00Z'),
  ('payments:record','PAYMENT','RECORD','Record and allocate refund payments','RESTRICTED','2026-09-08T00:00:00Z'),
  ('security:manage','SECURITY_INCIDENT','MANAGE','Create, contain, and close security incidents','SECURITY','2026-09-08T00:00:00Z'),
  ('accounting:close-period','ACCOUNTING_PERIOD','CLOSE','Close an accounting period','RESTRICTED','2026-09-08T00:00:00Z'),
  ('documents:manage','DOCUMENT','MANAGE','Manage document retention, supersession, and scan review','RESTRICTED','2026-09-08T00:00:00Z'),
  ('communications:respond','COMMUNICATION','RESPOND','Respond to case correspondence threads','INTERNAL','2026-09-08T00:00:00Z'),
  ('licensing:manage','LICENSE','MANAGE','Manage organisation licence state and upgrades','RESTRICTED','2026-09-08T00:00:00Z');
--> statement-breakpoint
WITH policies(permission_code,feature_key,operation_class,status,created_at,updated_at) AS (VALUES
  ('taxpayers:suspend','CORE_VAT','ADMIN_WRITE','ACTIVE','2026-09-08T00:00:00Z','2026-09-08T00:00:00Z'),
  ('registrations:approve','CORE_VAT','COMPLIANCE_WRITE','ACTIVE','2026-09-08T00:00:00Z','2026-09-08T00:00:00Z'),
  ('invoices:cancel','CORE_VAT','CORRECTION_WRITE','ACTIVE','2026-09-08T00:00:00Z','2026-09-08T00:00:00Z'),
  ('vat-rules:read','CORE_VAT','READ','ACTIVE','2026-09-08T00:00:00Z','2026-09-08T00:00:00Z'),
  ('vat-rules:manage','CORE_VAT','COMPLIANCE_WRITE','ACTIVE','2026-09-08T00:00:00Z','2026-09-08T00:00:00Z'),
  ('cases:override-sod','CORE_VAT','ADMIN_WRITE','ACTIVE','2026-09-08T00:00:00Z','2026-09-08T00:00:00Z'),
  ('obligations:manage','CORE_VAT','COMPLIANCE_WRITE','ACTIVE','2026-09-08T00:00:00Z','2026-09-08T00:00:00Z'),
  ('notifications:manage','CORE_VAT','BUSINESS_WRITE','ACTIVE','2026-09-08T00:00:00Z','2026-09-08T00:00:00Z'),
  ('reports:executive','CORE_VAT','READ','ACTIVE','2026-09-08T00:00:00Z','2026-09-08T00:00:00Z'),
  ('payments:record','CORE_VAT','BUSINESS_WRITE','ACTIVE','2026-09-08T00:00:00Z','2026-09-08T00:00:00Z'),
  ('security:manage','PLATFORM_SECURITY','ADMIN_WRITE','ACTIVE','2026-09-08T00:00:00Z','2026-09-08T00:00:00Z'),
  ('accounting:close-period','ACCOUNTING','BUSINESS_WRITE','ACTIVE','2026-09-08T00:00:00Z','2026-09-08T00:00:00Z'),
  ('documents:manage','BUSINESS_OPERATIONS','BUSINESS_WRITE','ACTIVE','2026-09-08T00:00:00Z','2026-09-08T00:00:00Z'),
  ('communications:respond','CORE_VAT','COMPLIANCE_WRITE','ACTIVE','2026-09-08T00:00:00Z','2026-09-08T00:00:00Z'),
  ('licensing:manage','ADMINISTRATION','ADMIN_WRITE','ACTIVE','2026-09-08T00:00:00Z','2026-09-08T00:00:00Z'))
INSERT OR IGNORE INTO license_permission_policies (permission_code,feature_key,operation_class,status,created_at,updated_at)
SELECT p.permission_code,p.feature_key,p.operation_class,p.status,p.created_at,p.updated_at
FROM policies p
JOIN access_permissions a ON a.code=p.permission_code
JOIN license_features f ON f.feature_key=p.feature_key;
