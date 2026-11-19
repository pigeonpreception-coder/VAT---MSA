<?php

namespace App\Support\Access;

use App\Models\AccessPermission;
use App\Support\Ui\Humanize;

/**
 * Plain-language names for permission codes ("returns:approve" ->
 * "VAT returns · Approve"), and the grouped option list the Administration
 * page's "Create organisation role" checklist is built from.
 *
 * The option list is exactly what OrganisationAdminService::createOrganisationRole
 * accepts: Permissions::tenantGrantablePermissions() (the validator's ceiling)
 * intersected with the AccessPermission catalogue (the service's own
 * existence check). Labels are presentation only; the codes are what is
 * submitted and validated.
 */
final class PermissionLabels
{
    private const RESOURCES = [
        'access-governance' => 'Access governance',
        'accounting' => 'Accounting',
        'administration' => 'Administration',
        'commercial' => 'Commercial records',
        'communications' => 'Communications',
        'compliance' => 'Compliance',
        'consents' => 'Consents and delegations',
        'developer' => 'API clients',
        'disputes' => 'Disputes',
        'documents' => 'Documents',
        'employees' => 'Employees',
        'exceptions' => 'Reconciliation exceptions',
        'expenses' => 'Expenses',
        'identity' => 'Identity',
        'imports' => 'Import declarations',
        'integrations' => 'Integrations',
        'inventory' => 'Inventory',
        'invoices' => 'Invoices',
        'licensing' => 'Licensing',
        'offline' => 'Offline continuity',
        'organisations' => 'Organisation and branches',
        'parties' => 'Customers and suppliers',
        'projects' => 'Projects',
        'quotations' => 'Quotations',
        'refunds' => 'VAT refunds',
        'registrations' => 'Registration applications',
        'reports' => 'Reports',
        'returns' => 'VAT returns',
        'roles' => 'Organisation roles',
        'search' => 'Search',
        'taxpayer-systems' => 'Taxpayer systems',
        'taxpayers' => 'Taxpayers',
        'vat-adjustments' => 'VAT adjustments',
        'workflows' => 'Workflows',
        'workspace' => 'Workspace',
    ];

    private const ACTIONS = [
        'read' => 'View',
        'manage' => 'Manage',
        'submit' => 'Submit',
        'post' => 'Post journals',
        'close-period' => 'Close periods',
        'respond' => 'Respond',
        'upload' => 'Upload',
        'request' => 'Request',
        'run' => 'Run',
        'decide' => 'Decide tasks',
        'approve' => 'Approve',
        'generate' => 'Generate',
        'sync' => 'Sync batches',
    ];

    /** Resource prefixes per checklist section; anything unlisted lands in "Other". */
    private const AREAS = [
        'Invoices and VAT' => ['invoices', 'imports', 'returns', 'refunds', 'vat-adjustments', 'exceptions', 'compliance', 'communications', 'disputes', 'offline'],
        'Accounting and operations' => ['accounting', 'expenses', 'inventory', 'projects', 'quotations', 'commercial', 'parties', 'employees'],
        'Records and reports' => ['documents', 'reports', 'search', 'workspace'],
        'Registry and integrations' => ['taxpayers', 'registrations', 'taxpayer-systems', 'consents', 'integrations', 'developer'],
        'Administration and security' => ['administration', 'organisations', 'roles', 'access-governance', 'workflows', 'identity', 'licensing'],
    ];

    public static function resource(string $code): string
    {
        $resource = explode(':', $code, 2)[0];

        return self::RESOURCES[$resource] ?? Humanize::enum(str_replace('-', '_', $resource));
    }

    public static function action(string $code): string
    {
        $action = explode(':', $code, 2)[1] ?? '';

        return self::ACTIONS[$action] ?? Humanize::enum(str_replace('-', '_', $action));
    }

    /** "returns:approve" -> "VAT returns · Approve". */
    public static function label(string $code): string
    {
        return self::resource($code).' · '.self::action($code);
    }

    /**
     * @return list<array{area: string, resources: list<array{label: string, permissions: list<array{code: string, action: string, description: string}>}>}>
     */
    public static function grantableOptions(): array
    {
        $descriptions = AccessPermission::query()
            ->whereIn('code', Permissions::tenantGrantablePermissions())
            ->pluck('description', 'code');

        $byResource = [];
        foreach ($descriptions as $code => $description) {
            $byResource[explode(':', $code, 2)[0]][] = [
                'code' => $code, 'action' => self::action($code), 'description' => $description,
            ];
        }

        $areas = [];
        $placed = [];
        foreach ([...self::AREAS, 'Other' => array_keys($byResource)] as $area => $resources) {
            $entries = [];
            foreach ($resources as $resource) {
                if (! isset($byResource[$resource]) || isset($placed[$resource])) {
                    continue;
                }
                $placed[$resource] = true;
                $permissions = $byResource[$resource];
                usort($permissions, fn ($a, $b) => self::actionRank($a['code']) <=> self::actionRank($b['code']));
                $entries[] = ['label' => self::resource($resource.':'), 'permissions' => $permissions];
            }
            if ($entries !== []) {
                $areas[] = ['area' => $area, 'resources' => $entries];
            }
        }

        return $areas;
    }

    /** "View" first, then the rest in catalogue order of ACTIONS. */
    private static function actionRank(string $code): int
    {
        $index = array_search(explode(':', $code, 2)[1] ?? '', array_keys(self::ACTIONS), true);

        return $index === false ? PHP_INT_MAX : $index;
    }
}
