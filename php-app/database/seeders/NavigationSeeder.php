<?php

namespace Database\Seeders;

use App\Models\NavigationFolder;
use App\Models\NavigationItem;
use App\Models\NavigationWorkspace;
use Illuminate\Database\Seeder;

/**
 * Ported verbatim from db/runtime.ts's navigation_workspaces/
 * navigation_folders/navigation_items seed rows (CONTROL_PLANE_SEED_
 * STATEMENTS, plus the one extra `nitem-parties` row PARTY_LIFECYCLE_
 * SEED_STATEMENTS adds -- already present in the first batch below, so
 * that later statement is a genuine no-op in the source too). A fixed,
 * code-defined catalogue like OrganisationAdministratorRoleSeeder's own
 * sibling seeders -- no command anywhere in the source ever writes to
 * these three tables, so without this seed no navigation tree could ever
 * exist in either system. `navigation_permissions` (an unused table --
 * confirmed by grepping every .ts file under lib/ for a reader or writer,
 * finding none) is deliberately not seeded or even built as a migration,
 * matching this migration's established "positions table" precedent for
 * a genuinely dead table in the source schema.
 */
class NavigationSeeder extends Seeder
{
    public function run(): void
    {
        $workspaces = [
            ['id' => 'nav-home', 'workspace_key' => 'home', 'label' => 'Home / Command Centre', 'description' => 'Executive operational VAT and task posture', 'sort_order' => 10, 'status' => 'ACTIVE', 'classification' => 'INTERNAL'],
            ['id' => 'nav-sales', 'workspace_key' => 'sales', 'label' => 'Sales & Revenue', 'description' => 'Customers quotations invoices and output VAT', 'sort_order' => 20, 'status' => 'ACTIVE', 'classification' => 'CONFIDENTIAL'],
            ['id' => 'nav-procurement', 'workspace_key' => 'procurement', 'label' => 'Procurement & Purchases', 'description' => 'Suppliers expenses purchases and input VAT', 'sort_order' => 30, 'status' => 'ACTIVE', 'classification' => 'CONFIDENTIAL'],
            ['id' => 'nav-vat', 'workspace_key' => 'vat', 'label' => 'VAT & Tax Management', 'description' => 'VAT reconciliation returns and compliance', 'sort_order' => 40, 'status' => 'ACTIVE', 'classification' => 'RESTRICTED'],
            ['id' => 'nav-accounting', 'workspace_key' => 'accounting', 'label' => 'Accounting & Finance', 'description' => 'General ledger and financial control', 'sort_order' => 50, 'status' => 'ACTIVE', 'classification' => 'CONFIDENTIAL'],
            ['id' => 'nav-inventory', 'workspace_key' => 'inventory', 'label' => 'Inventory & Operations', 'description' => 'Inventory expenses and operating controls', 'sort_order' => 60, 'status' => 'ACTIVE', 'classification' => 'CONFIDENTIAL'],
            ['id' => 'nav-projects', 'workspace_key' => 'projects', 'label' => 'Project Management', 'description' => 'Project cost revenue and budget control', 'sort_order' => 70, 'status' => 'ACTIVE', 'classification' => 'CONFIDENTIAL'],
            ['id' => 'nav-documents', 'workspace_key' => 'documents', 'label' => 'Documents & Records', 'description' => 'Evidence documents and immutable records', 'sort_order' => 80, 'status' => 'ACTIVE', 'classification' => 'RESTRICTED'],
            ['id' => 'nav-reporting', 'workspace_key' => 'reporting', 'label' => 'Reporting & Analytics', 'description' => 'Governed reports and performance analysis', 'sort_order' => 90, 'status' => 'ACTIVE', 'classification' => 'CONFIDENTIAL'],
            ['id' => 'nav-integrations', 'workspace_key' => 'integrations', 'label' => 'Integrations', 'description' => 'ITAS SaaS API and developer controls', 'sort_order' => 100, 'status' => 'ACTIVE', 'classification' => 'RESTRICTED'],
            ['id' => 'nav-administration', 'workspace_key' => 'administration', 'label' => 'Administration', 'description' => 'Organisation people access workflow and security', 'sort_order' => 110, 'status' => 'ACTIVE', 'classification' => 'RESTRICTED'],
            ['id' => 'nav-licensing', 'workspace_key' => 'licensing', 'label' => 'Licensing & Subscription', 'description' => 'Licence entitlements usage and renewal posture', 'sort_order' => 120, 'status' => 'ACTIVE', 'classification' => 'COMMERCIAL'],
        ];
        foreach ($workspaces as $workspace) {
            NavigationWorkspace::updateOrCreate(['id' => $workspace['id']], $workspace);
        }

        $folders = [
            ['id' => 'folder-home-dashboard', 'workspace_id' => 'nav-home', 'parent_folder_id' => null, 'folder_key' => 'dashboard', 'label' => 'Dashboard', 'sort_order' => 10, 'status' => 'ACTIVE'],
            ['id' => 'folder-sales-main', 'workspace_id' => 'nav-sales', 'parent_folder_id' => null, 'folder_key' => 'sales', 'label' => 'Sales', 'sort_order' => 10, 'status' => 'ACTIVE'],
            ['id' => 'folder-proc-main', 'workspace_id' => 'nav-procurement', 'parent_folder_id' => null, 'folder_key' => 'procurement', 'label' => 'Procurement', 'sort_order' => 10, 'status' => 'ACTIVE'],
            ['id' => 'folder-vat-main', 'workspace_id' => 'nav-vat', 'parent_folder_id' => null, 'folder_key' => 'vat-management', 'label' => 'VAT Management', 'sort_order' => 10, 'status' => 'ACTIVE'],
            ['id' => 'folder-accounting-main', 'workspace_id' => 'nav-accounting', 'parent_folder_id' => null, 'folder_key' => 'accounting', 'label' => 'Accounting', 'sort_order' => 10, 'status' => 'ACTIVE'],
            ['id' => 'folder-inventory-main', 'workspace_id' => 'nav-inventory', 'parent_folder_id' => null, 'folder_key' => 'inventory', 'label' => 'Inventory & Operations', 'sort_order' => 10, 'status' => 'ACTIVE'],
            ['id' => 'folder-projects-main', 'workspace_id' => 'nav-projects', 'parent_folder_id' => null, 'folder_key' => 'projects', 'label' => 'Projects', 'sort_order' => 10, 'status' => 'ACTIVE'],
            ['id' => 'folder-documents-main', 'workspace_id' => 'nav-documents', 'parent_folder_id' => null, 'folder_key' => 'documents', 'label' => 'Documents & Records', 'sort_order' => 10, 'status' => 'ACTIVE'],
            ['id' => 'folder-reporting-main', 'workspace_id' => 'nav-reporting', 'parent_folder_id' => null, 'folder_key' => 'reports', 'label' => 'Reports & Analytics', 'sort_order' => 10, 'status' => 'ACTIVE'],
            ['id' => 'folder-integrations-main', 'workspace_id' => 'nav-integrations', 'parent_folder_id' => null, 'folder_key' => 'integrations', 'label' => 'Integrations & Developer', 'sort_order' => 10, 'status' => 'ACTIVE'],
            ['id' => 'folder-administration-main', 'workspace_id' => 'nav-administration', 'parent_folder_id' => null, 'folder_key' => 'organisation-admin', 'label' => 'Organisation Administration', 'sort_order' => 10, 'status' => 'ACTIVE'],
            ['id' => 'folder-licensing-main', 'workspace_id' => 'nav-licensing', 'parent_folder_id' => null, 'folder_key' => 'subscription', 'label' => 'Subscription', 'sort_order' => 10, 'status' => 'ACTIVE'],
        ];
        foreach ($folders as $folder) {
            NavigationFolder::updateOrCreate(['id' => $folder['id']], $folder);
        }

        $items = [
            ['id' => 'nitem-dashboard', 'workspace_id' => 'nav-home', 'folder_id' => 'folder-home-dashboard', 'item_key' => 'dashboard', 'label' => 'Operations dashboard', 'href' => '/', 'feature_key' => 'CORE_VAT', 'capability' => null, 'required_permission' => 'dashboard:read', 'sort_order' => 10, 'status' => 'ACTIVE', 'classification' => 'INTERNAL'],
            ['id' => 'nitem-portals', 'workspace_id' => 'nav-home', 'folder_id' => 'folder-home-dashboard', 'item_key' => 'portals', 'label' => 'Portal switchboard', 'href' => '/portals', 'feature_key' => 'CORE_VAT', 'capability' => null, 'required_permission' => 'dashboard:read', 'sort_order' => 20, 'status' => 'ACTIVE', 'classification' => 'INTERNAL'],
            ['id' => 'nitem-search', 'workspace_id' => 'nav-home', 'folder_id' => 'folder-home-dashboard', 'item_key' => 'search', 'label' => 'Workspace search', 'href' => '/workspace-search', 'feature_key' => 'ADMINISTRATION', 'capability' => null, 'required_permission' => 'search:read', 'sort_order' => 30, 'status' => 'ACTIVE', 'classification' => 'RESTRICTED'],
            ['id' => 'nitem-commercial', 'workspace_id' => 'nav-sales', 'folder_id' => 'folder-sales-main', 'item_key' => 'commercial', 'label' => 'Customers & quotations', 'href' => '/commercial', 'feature_key' => 'CORE_VAT', 'capability' => 'SELLER', 'required_permission' => 'commercial:read', 'sort_order' => 10, 'status' => 'ACTIVE', 'classification' => 'CONFIDENTIAL'],
            ['id' => 'nitem-parties', 'workspace_id' => 'nav-sales', 'folder_id' => 'folder-sales-main', 'item_key' => 'parties', 'label' => 'Customers & suppliers', 'href' => '/commercial/parties', 'feature_key' => 'CORE_VAT', 'capability' => null, 'required_permission' => 'parties:manage', 'sort_order' => 15, 'status' => 'ACTIVE', 'classification' => 'CONFIDENTIAL'],
            ['id' => 'nitem-invoices', 'workspace_id' => 'nav-sales', 'folder_id' => 'folder-sales-main', 'item_key' => 'invoices', 'label' => 'Tax invoices', 'href' => '/invoices', 'feature_key' => 'CORE_VAT', 'capability' => 'SELLER', 'required_permission' => 'invoices:read', 'sort_order' => 20, 'status' => 'ACTIVE', 'classification' => 'RESTRICTED'],
            ['id' => 'nitem-new-invoice', 'workspace_id' => 'nav-sales', 'folder_id' => 'folder-sales-main', 'item_key' => 'new-invoice', 'label' => 'Submit tax invoice', 'href' => '/invoices/new', 'feature_key' => 'CORE_VAT', 'capability' => 'SELLER', 'required_permission' => 'invoices:submit', 'sort_order' => 30, 'status' => 'ACTIVE', 'classification' => 'RESTRICTED'],
            ['id' => 'nitem-operations', 'workspace_id' => 'nav-procurement', 'folder_id' => 'folder-proc-main', 'item_key' => 'operations', 'label' => 'Purchases & expenses', 'href' => '/operations', 'feature_key' => 'CORE_VAT', 'capability' => 'BUYER', 'required_permission' => 'expenses:read', 'sort_order' => 10, 'status' => 'ACTIVE', 'classification' => 'CONFIDENTIAL'],
            ['id' => 'nitem-reconciliation', 'workspace_id' => 'nav-vat', 'folder_id' => 'folder-vat-main', 'item_key' => 'reconciliation', 'label' => 'VAT reconciliation', 'href' => '/reconciliation', 'feature_key' => 'CORE_VAT', 'capability' => null, 'required_permission' => 'exceptions:read', 'sort_order' => 10, 'status' => 'ACTIVE', 'classification' => 'RESTRICTED'],
            ['id' => 'nitem-returns', 'workspace_id' => 'nav-vat', 'folder_id' => 'folder-vat-main', 'item_key' => 'returns', 'label' => 'VAT returns', 'href' => '/returns', 'feature_key' => 'CORE_VAT', 'capability' => null, 'required_permission' => 'returns:read', 'sort_order' => 20, 'status' => 'ACTIVE', 'classification' => 'RESTRICTED'],
            ['id' => 'nitem-compliance', 'workspace_id' => 'nav-vat', 'folder_id' => 'folder-vat-main', 'item_key' => 'compliance', 'label' => 'Compliance & disputes', 'href' => '/compliance', 'feature_key' => 'CORE_VAT', 'capability' => null, 'required_permission' => 'compliance:read', 'sort_order' => 30, 'status' => 'ACTIVE', 'classification' => 'RESTRICTED'],
            ['id' => 'nitem-cases', 'workspace_id' => 'nav-vat', 'folder_id' => 'folder-vat-main', 'item_key' => 'cases', 'label' => 'Audit cases & risk', 'href' => '/cases', 'feature_key' => 'CORE_VAT', 'capability' => null, 'required_permission' => 'cases:manage', 'sort_order' => 40, 'status' => 'ACTIVE', 'classification' => 'RESTRICTED'],
            ['id' => 'nitem-refunds', 'workspace_id' => 'nav-vat', 'folder_id' => 'folder-vat-main', 'item_key' => 'refunds', 'label' => 'Refund control', 'href' => '/refunds', 'feature_key' => 'CORE_VAT', 'capability' => null, 'required_permission' => 'refunds:read', 'sort_order' => 50, 'status' => 'ACTIVE', 'classification' => 'RESTRICTED'],
            ['id' => 'nitem-accounting', 'workspace_id' => 'nav-accounting', 'folder_id' => 'folder-accounting-main', 'item_key' => 'accounting', 'label' => 'General ledger', 'href' => '/accounting', 'feature_key' => 'ACCOUNTING', 'capability' => null, 'required_permission' => 'accounting:read', 'sort_order' => 10, 'status' => 'ACTIVE', 'classification' => 'CONFIDENTIAL'],
            ['id' => 'nitem-inventory', 'workspace_id' => 'nav-inventory', 'folder_id' => 'folder-inventory-main', 'item_key' => 'inventory', 'label' => 'Inventory operations', 'href' => '/operations', 'feature_key' => 'INVENTORY', 'capability' => null, 'required_permission' => 'inventory:read', 'sort_order' => 10, 'status' => 'ACTIVE', 'classification' => 'CONFIDENTIAL'],
            ['id' => 'nitem-projects', 'workspace_id' => 'nav-projects', 'folder_id' => 'folder-projects-main', 'item_key' => 'projects', 'label' => 'Projects', 'href' => '/operations', 'feature_key' => 'PROJECTS', 'capability' => null, 'required_permission' => 'projects:read', 'sort_order' => 10, 'status' => 'ACTIVE', 'classification' => 'CONFIDENTIAL'],
            ['id' => 'nitem-documents', 'workspace_id' => 'nav-documents', 'folder_id' => 'folder-documents-main', 'item_key' => 'documents', 'label' => 'Evidence documents', 'href' => '/documents', 'feature_key' => 'CORE_VAT', 'capability' => null, 'required_permission' => 'documents:read', 'sort_order' => 10, 'status' => 'ACTIVE', 'classification' => 'RESTRICTED'],
            ['id' => 'nitem-audit', 'workspace_id' => 'nav-documents', 'folder_id' => 'folder-documents-main', 'item_key' => 'audit', 'label' => 'Audit evidence', 'href' => '/audit', 'feature_key' => 'CORE_VAT', 'capability' => null, 'required_permission' => 'audit:read', 'sort_order' => 20, 'status' => 'ACTIVE', 'classification' => 'RESTRICTED'],
            ['id' => 'nitem-offline', 'workspace_id' => 'nav-documents', 'folder_id' => 'folder-documents-main', 'item_key' => 'offline', 'label' => 'Offline continuity', 'href' => '/offline', 'feature_key' => 'CORE_VAT', 'capability' => null, 'required_permission' => 'offline:read', 'sort_order' => 30, 'status' => 'ACTIVE', 'classification' => 'RESTRICTED'],
            ['id' => 'nitem-reports', 'workspace_id' => 'nav-reporting', 'folder_id' => 'folder-reporting-main', 'item_key' => 'reports', 'label' => 'Reports & analytics', 'href' => '/reports', 'feature_key' => 'ANALYTICS', 'capability' => null, 'required_permission' => 'reports:read', 'sort_order' => 10, 'status' => 'ACTIVE', 'classification' => 'CONFIDENTIAL'],
            ['id' => 'nitem-integrations', 'workspace_id' => 'nav-integrations', 'folder_id' => 'folder-integrations-main', 'item_key' => 'integrations', 'label' => 'Integration health', 'href' => '/integrations', 'feature_key' => 'API_ACCESS', 'capability' => null, 'required_permission' => 'integrations:read', 'sort_order' => 10, 'status' => 'ACTIVE', 'classification' => 'RESTRICTED'],
            ['id' => 'nitem-developer', 'workspace_id' => 'nav-integrations', 'folder_id' => 'folder-integrations-main', 'item_key' => 'developer', 'label' => 'Developer & webhooks', 'href' => '/developer', 'feature_key' => 'API_ACCESS', 'capability' => null, 'required_permission' => 'developer:read', 'sort_order' => 20, 'status' => 'ACTIVE', 'classification' => 'RESTRICTED'],
            ['id' => 'nitem-administration', 'workspace_id' => 'nav-administration', 'folder_id' => 'folder-administration-main', 'item_key' => 'administration', 'label' => 'Administration command centre', 'href' => '/administration', 'feature_key' => 'ADMINISTRATION', 'capability' => null, 'required_permission' => 'administration:read', 'sort_order' => 10, 'status' => 'ACTIVE', 'classification' => 'RESTRICTED'],
            ['id' => 'nitem-organisations', 'workspace_id' => 'nav-administration', 'folder_id' => 'folder-administration-main', 'item_key' => 'organisations', 'label' => 'Organisation identity', 'href' => '/organisations', 'feature_key' => 'ADMINISTRATION', 'capability' => null, 'required_permission' => 'identity:read', 'sort_order' => 20, 'status' => 'ACTIVE', 'classification' => 'RESTRICTED'],
            ['id' => 'nitem-taxpayers', 'workspace_id' => 'nav-administration', 'folder_id' => 'folder-administration-main', 'item_key' => 'taxpayers', 'label' => 'Taxpayer registry', 'href' => '/taxpayers', 'feature_key' => 'ADMINISTRATION', 'capability' => null, 'required_permission' => 'taxpayers:read', 'sort_order' => 25, 'status' => 'ACTIVE', 'classification' => 'RESTRICTED'],
            ['id' => 'nitem-registrations', 'workspace_id' => 'nav-administration', 'folder_id' => 'folder-administration-main', 'item_key' => 'registrations', 'label' => 'Registration intake', 'href' => '/registrations', 'feature_key' => 'ADMINISTRATION', 'capability' => null, 'required_permission' => 'registrations:read', 'sort_order' => 27, 'status' => 'ACTIVE', 'classification' => 'RESTRICTED'],
            ['id' => 'nitem-security', 'workspace_id' => 'nav-administration', 'folder_id' => 'folder-administration-main', 'item_key' => 'security', 'label' => 'Security posture', 'href' => '/security', 'feature_key' => 'ADMINISTRATION', 'capability' => null, 'required_permission' => 'security:read', 'sort_order' => 30, 'status' => 'ACTIVE', 'classification' => 'SECURITY'],
            ['id' => 'nitem-licensing', 'workspace_id' => 'nav-licensing', 'folder_id' => 'folder-licensing-main', 'item_key' => 'licensing', 'label' => 'Current licence & usage', 'href' => '/administration#licensing', 'feature_key' => 'ADMINISTRATION', 'capability' => null, 'required_permission' => 'licensing:read', 'sort_order' => 10, 'status' => 'ACTIVE', 'classification' => 'COMMERCIAL'],
        ];
        foreach ($items as $item) {
            NavigationItem::updateOrCreate(['id' => $item['id']], $item);
        }
    }
}
