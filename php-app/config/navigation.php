<?php

/*
 * Sidebar navigation. The first nine groups are the master prompt's §16-21
 * taxonomy (docs/MIGRATION_MATRIX.md, "Sidebar restructuring"); the rest
 * gather the remaining destinations into a few task-based groups.
 *
 * Every link is shown to every user (the user's own decision, 2026-09-15);
 * links a user can't open are rendered locked. Each item's `permission`
 * must be the permission its destination route authorizes --
 * tests/Feature/Navigation/SidebarNavigationTest.php fails if an unlocked
 * link would 403 or a locked one would open.
 *
 * Item keys:
 *   route       route name the link points at
 *   params      route parameters / query string
 *   fragment    URL fragment; makes the item a shortcut that is never
 *               highlighted as the current page
 *   active      routeIs() patterns that highlight the item (default: route)
 *   query       query values that must match for the item to be active
 *   not_query   query values that must NOT match for it to be active
 *   permission  permission (string) or any-of permissions (array)
 *
 * Group `active` patterns expand the group on pages that belong to it but
 * have no item of their own (detail pages, edit forms).
 */

return [
    'groups' => [
        [
            'key' => 'dashboard',
            'label' => 'Dashboard',
            'icon' => 'speedometer2',
            'active' => ['dashboard', 'portals.*', 'portal.*', 'workspace-search.*'],
            'items' => [
                ['label' => 'Overview', 'route' => 'dashboard', 'permission' => 'dashboard:read'],
                ['label' => 'Portals', 'route' => 'portals.index', 'active' => ['portals.*', 'portal.*'], 'permission' => 'dashboard:read'],
                ['label' => 'Workspace Search', 'route' => 'workspace-search.index', 'active' => ['workspace-search.*'], 'permission' => 'search:read'],
            ],
        ],
        [
            'key' => 'vat-management',
            'label' => 'VAT Management',
            'icon' => 'percent',
            'active' => ['vat-management.*', 'vat-periods.*', 'vat-returns.*', 'refunds.*', 'risk-indicators.*', 'audit-cases.*', 'audit-evidence.*', 'audit-trail.*', 'disputes.*', 'obligations.*', 'communications.*', 'compliance-overview.*', 'exceptions.*'],
            'items' => [
                ['label' => 'VAT Audit Report', 'route' => 'vat-management.audit-report', 'permission' => 'compliance:read'],
                ['label' => 'Invoice Reconciliation', 'route' => 'vat-management.reconciliation', 'permission' => 'compliance:read'],
                ['label' => 'Reconciliation Exceptions', 'route' => 'exceptions.index', 'active' => ['exceptions.*'], 'permission' => 'exceptions:read'],
                ['label' => 'VAT Returns', 'route' => 'vat-periods.index', 'active' => ['vat-periods.*', 'vat-returns.*'], 'permission' => 'returns:read'],
                ['label' => 'VAT Refunds', 'route' => 'refunds.index', 'active' => ['refunds.*'], 'permission' => 'refunds:read'],
                ['label' => 'VAT Adjustment Report', 'route' => 'vat-management.adjustment-report', 'permission' => 'compliance:read'],
                ['label' => 'Risk Indicators', 'route' => 'risk-indicators.index', 'active' => ['risk-indicators.*'], 'permission' => 'risk:read'],
                ['label' => 'Audit Cases', 'route' => 'audit-cases.index', 'active' => ['audit-cases.*', 'audit-evidence.*'], 'permission' => 'compliance:read'],
                ['label' => 'Audit Trail', 'route' => 'audit-trail.index', 'active' => ['audit-trail.*'], 'permission' => 'audit:read'],
                ['label' => 'Disputes', 'route' => 'disputes.index', 'active' => ['disputes.*'], 'permission' => 'compliance:read'],
                ['label' => 'Obligations', 'route' => 'obligations.index', 'active' => ['obligations.*'], 'permission' => 'compliance:read'],
                ['label' => 'Communications', 'route' => 'communications.index', 'active' => ['communications.*'], 'permission' => 'compliance:read'],
                ['label' => 'Compliance Overview', 'route' => 'compliance-overview.index', 'active' => ['compliance-overview.*'], 'permission' => 'compliance:read'],
            ],
        ],
        [
            'key' => 'invoice-management',
            'label' => 'Invoice Management',
            'icon' => 'receipt',
            'active' => ['invoice-management.*', 'invoices.*'],
            'items' => [
                ['label' => 'Local Invoices', 'route' => 'invoice-management.local', 'active' => ['invoice-management.local*'], 'permission' => 'invoices:read'],
                ['label' => 'Foreign Invoices', 'route' => 'invoice-management.foreign', 'active' => ['invoice-management.foreign*'], 'permission' => 'imports:read'],
                ['label' => 'All Invoices', 'route' => 'invoices.index', 'active' => ['invoices.*'], 'permission' => 'invoices:read'],
            ],
        ],
        [
            'key' => 'accounting-finance',
            'label' => 'Accounting & Finance',
            'icon' => 'journal-text',
            'active' => ['accounting.*'],
            'items' => [
                ['label' => 'General Ledger', 'route' => 'accounting.index', 'permission' => 'accounting:read'],
                ['label' => 'Supplier Ledger', 'route' => 'accounting.supplier-ledger', 'permission' => 'accounting:read'],
                ['label' => 'Customer Ledger', 'route' => 'accounting.customer-ledger', 'permission' => 'accounting:read'],
                ['label' => 'Fixed Assets', 'route' => 'accounting.fixed-assets', 'permission' => 'accounting:read'],
                ['label' => 'Budgets', 'route' => 'accounting.budgets', 'active' => ['accounting.budgets*'], 'permission' => 'accounting:read'],
                ['label' => 'Purchase Orders', 'route' => 'accounting.purchase-orders', 'active' => ['accounting.purchase-orders*'], 'permission' => 'accounting:read'],
                ['label' => 'Project Cash Flow', 'route' => 'accounting.cash-flow', 'permission' => 'accounting:read'],
            ],
        ],
        [
            'key' => 'operations',
            'label' => 'Operations',
            'icon' => 'boxes',
            'active' => ['operations.*'],
            'items' => [
                ['label' => 'Business Operations', 'route' => 'operations.index', 'permission' => 'expenses:read'],
                ['label' => 'Human Resources', 'route' => 'operations.human-resources', 'active' => ['operations.human-resources*'], 'permission' => 'employees:read'],
                ['label' => 'Immovable Assets', 'route' => 'operations.immovable-assets', 'active' => ['operations.immovable-assets*'], 'permission' => 'fixed-assets:read'],
                ['label' => 'Movable Assets', 'route' => 'operations.movable-assets', 'active' => ['operations.movable-assets*'], 'permission' => 'fixed-assets:read'],
                ['label' => 'Logistics', 'route' => 'operations.logistics', 'active' => ['operations.logistics*'], 'permission' => 'logistics:read'],
                ['label' => 'Inventory', 'route' => 'operations.inventory', 'active' => ['operations.inventory*'], 'permission' => 'inventory:read'],
                ['label' => 'ERP Overview', 'route' => 'operations.erp', 'permission' => 'expenses:read'],
            ],
        ],
        [
            'key' => 'quotation',
            'label' => 'Quotation',
            'icon' => 'file-earmark-text',
            'active' => ['quotations.*', 'quotation.*'],
            'items' => [
                ['label' => 'Create New Quotation', 'route' => 'quotations.index', 'fragment' => 'issue-quotation', 'permission' => 'commercial:read'],
                ['label' => 'Issued Quotations', 'route' => 'quotations.index', 'active' => ['quotations.*'], 'not_query' => ['status' => 'CONVERTED'], 'permission' => 'commercial:read'],
                ['label' => 'Converted Quotations', 'route' => 'quotations.index', 'params' => ['status' => 'CONVERTED'], 'query' => ['status' => 'CONVERTED'], 'permission' => 'commercial:read'],
                ['label' => 'Converted to Invoices', 'route' => 'quotation.converted-invoices', 'active' => ['quotation.*'], 'permission' => 'commercial:read'],
            ],
        ],
        [
            'key' => 'project-management',
            'label' => 'Project Management',
            'icon' => 'kanban',
            'active' => ['project-management.*'],
            'items' => [
                ['label' => 'New Project', 'route' => 'project-management.new', 'permission' => 'projects:read'],
                ['label' => 'Ongoing Projects', 'route' => 'project-management.ongoing', 'permission' => 'projects:read'],
                ['label' => 'Completed Projects', 'route' => 'project-management.completed', 'permission' => 'projects:read'],
            ],
        ],
        [
            'key' => 'registered',
            'label' => 'Registered',
            'icon' => 'people',
            'active' => ['business-parties.*', 'taxpayer-systems.*', 'saas-providers.*'],
            'items' => [
                ['label' => 'Customers', 'route' => 'business-parties.index', 'params' => ['relationship' => 'CUSTOMER'], 'active' => ['business-parties.index'], 'query' => ['relationship' => 'CUSTOMER'], 'permission' => 'parties:manage'],
                ['label' => 'Suppliers', 'route' => 'business-parties.index', 'params' => ['relationship' => 'SUPPLIER'], 'active' => ['business-parties.index'], 'query' => ['relationship' => 'SUPPLIER'], 'permission' => 'parties:manage'],
                ['label' => 'Service Providers', 'route' => 'business-parties.index', 'params' => ['relationship' => 'SERVICE_PROVIDER'], 'active' => ['business-parties.index'], 'query' => ['relationship' => 'SERVICE_PROVIDER'], 'permission' => 'parties:manage'],
                ['label' => 'Taxpayer Systems', 'route' => 'taxpayer-systems.index', 'active' => ['taxpayer-systems.*'], 'permission' => 'taxpayer-systems:read'],
                ['label' => 'SaaS Providers', 'route' => 'saas-providers.index', 'active' => ['saas-providers.*'], 'permission' => 'developer:read'],
            ],
        ],
        [
            'key' => 'new-registration',
            'label' => 'New Registration',
            'icon' => 'plus-circle',
            'active' => ['new-registration.*'],
            'items' => [
                ['label' => 'New Customer', 'route' => 'business-parties.index', 'params' => ['relationship' => 'CUSTOMER'], 'fragment' => 'register-party', 'permission' => 'parties:manage'],
                ['label' => 'New Supplier', 'route' => 'business-parties.index', 'params' => ['relationship' => 'SUPPLIER'], 'fragment' => 'register-party', 'permission' => 'parties:manage'],
                ['label' => 'New Quote', 'route' => 'quotations.index', 'fragment' => 'issue-quotation', 'permission' => 'commercial:read'],
                ['label' => 'New Credit Note', 'route' => 'new-registration.credit-note', 'permission' => 'invoices:read'],
                ['label' => 'New Debit Note', 'route' => 'new-registration.debit-note', 'permission' => 'invoices:read'],
            ],
        ],
        [
            'key' => 'records',
            'label' => 'Records & Reports',
            'icon' => 'folder2-open',
            'active' => ['documents.*', 'reports.*', 'offline.*'],
            'items' => [
                ['label' => 'Documents & Records', 'route' => 'documents.index', 'active' => ['documents.*'], 'permission' => 'documents:read'],
                ['label' => 'Reports & Analytics', 'route' => 'reports.index', 'active' => ['reports.*'], 'permission' => 'reports:read'],
                ['label' => 'Offline Continuity', 'route' => 'offline.index', 'active' => ['offline.*'], 'permission' => 'offline:read'],
            ],
        ],
        [
            'key' => 'integrations',
            'label' => 'Integrations',
            'icon' => 'plug',
            'active' => ['integrations.*', 'developer.*'],
            'items' => [
                ['label' => 'Integration Health', 'route' => 'integrations.index', 'active' => ['integrations.*'], 'permission' => 'integrations:read'],
                ['label' => 'API Clients & Webhooks', 'route' => 'developer.index', 'active' => ['developer.*'], 'permission' => 'developer:read'],
            ],
        ],
        [
            'key' => 'registry',
            'label' => 'Taxpayer Registry',
            'icon' => 'building',
            'active' => ['taxpayers.*', 'registrations.*'],
            'items' => [
                ['label' => 'Taxpayers', 'route' => 'taxpayers.index', 'active' => ['taxpayers.*'], 'permission' => 'taxpayers:read'],
                ['label' => 'Registration Applications', 'route' => 'registrations.index', 'active' => ['registrations.*'], 'permission' => 'registrations:read'],
            ],
        ],
        [
            'key' => 'administration',
            'label' => 'Administration',
            'icon' => 'gear',
            'active' => ['administration.*', 'organisations.*', 'workflows.*', 'security.operations', 'security.incidents.*', 'access-governance.*', 'access-rights.*', 'licensing.*', 'platform.*'],
            'items' => [
                ['label' => 'Organisation Administration', 'route' => 'administration.index', 'active' => ['administration.*'], 'permission' => 'administration:read'],
                ['label' => 'Organisations', 'route' => 'organisations.index', 'active' => ['organisations.*'], 'permission' => 'identity:read'],
                ['label' => 'Access Governance', 'route' => 'access-governance.index', 'active' => ['access-governance.*'], 'permission' => 'access-governance:read'],
                ['label' => 'Access Rights', 'route' => 'access-rights.index', 'active' => ['access-rights.*'], 'permission' => 'access-rights:read'],
                ['label' => 'Workflows', 'route' => 'workflows.index', 'active' => ['workflows.*'], 'permission' => 'workflows:read'],
                ['label' => 'Security Operations', 'route' => 'security.operations', 'active' => ['security.operations', 'security.incidents.*'], 'permission' => 'security:read'],
                ['label' => 'Licensing & Subscription', 'route' => 'licensing.index', 'active' => ['licensing.*'], 'permission' => 'licensing:read'],
                ['label' => 'Platform Configuration', 'route' => 'platform.index', 'active' => ['platform.*'], 'permission' => 'platform:read'],
            ],
        ],
    ],

    // Outside the sidebar: the top bar's search box (only for users who can
    // search) and the account menu.
    'search' => ['route' => 'workspace-search.index', 'permission' => 'search:read'],
    'account' => [
        ['label' => 'Two-factor authentication', 'icon' => 'shield-lock', 'route' => 'security.mfa', 'permission' => 'identity:read'],
    ],
];
